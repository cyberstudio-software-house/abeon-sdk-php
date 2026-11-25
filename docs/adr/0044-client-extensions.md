# ADR-0044: Client code lives in an extension built on a pinned base image

**Status:** Proposed
**Date:** 2026-11-25
**Amends:** ADR-0031 §1 (all instances run the same image)

## Context

ADR-0031 §1 gives every client its own instance of a business application: one deployment, one
database, one `org_id`. It also says that all instances run the same image and differ only in
configuration.

Clients will want behaviour of their own: a content block for one site, an integration with their
booking system, a report nobody else needs. Part of that work will be written by an AI agent acting for
the client. Configuration and content can hold code snippets for a page (`cms.code.write`), but not
server-side code, so the rule "same image, different configuration" leaves no place for it.

Two answers come first and both fail at scale:

- **A fork of the application per client.** Every fix and every security patch to the base has to be
  merged into each fork, and forks drift. The nearest example is the Agent: a fork of the template, pinned
  to `abeon/sdk` `^0.18`, that still carries its own copies of the panel middleware and chrome
  (`AbeonWebAuth`, `EnsureAppEntitled`, `SendPanelToPlatform`, `Chrome.tsx`, `Sidebar.tsx`) which
  `abeon/sdk` 0.23 moved into `Panel`. It is not a per-client fork, but it shows how copies age.
- **Client code inside the base repository.** Every client's code ships to every client, a change for one
  client is a release for all, and access for an agent cannot be limited to one client's code.

Three facts of the current code shape what an extension can be:

- The CMS image (`abeon-cms/Dockerfile`) ships PHP and compiled assets only: the runtime stage is
  `php:8.4-fpm` without Composer or Node, `node_modules` is not copied, and Inertia resolves pages from
  `resources/js/Pages` alone (`resources/js/app.tsx`).
- The block editor is a fixed `switch` over block types (`resources/js/components/blocks/BlockFields.tsx`),
  and `App\Blocks\BlockRegistry` fixes its list of types in the constructor; the class is not bound as a
  singleton.
- Auth keeps one permission list per service, shared by every organisation. A declaration replaces the
  whole list (`abeon-auth/app/Auth/PermissionCatalog.php`), and any instance that signs as `cms` can
  declare it (ADR-0039).

## Decision

**A client that needs code of its own gets an extension: a package in its own repository, installed into
an image built from a pinned base image. The base keeps one repository and one image for everyone, and
grows extension points deliberately.**

### 1. Base and extension

- The base application keeps one repository and one image, `cyberstudioinc/abeon-<app>`.
- Client code lives in `cyberstudio-software-house/<client>-<app>-ext` (e.g. `veno-med-cms-ext`), a
  Composer package of type `library` with a Laravel service provider found by package discovery.
  The marker for tooling is `extra.abeon.extension: "<name>"` in its `composer.json`; no installer
  plugin is involved.
- A client without an extension runs the base image.

### 2. Trust model

An extension runs in the same PHP process as the application. It has everything the instance has: the
instance's service key and `APP_KEY`, its database role, and the shared Redis (where ADR-0032 §3 calls
the prefix a convention, not a boundary). **The rules below are review rules backed by CI checks, not
runtime guarantees.** ADR-0031 §6's caveat "acceptable only while every instance is first-party code"
therefore still holds: an extension is first-party code by review, because nothing reaches production
without the pull request, CI and human review in §7.

What the instance's isolation still gives: an extension runs only in its client's instance, against that
client's database and `org_id` binding (ADR-0031 §4), so a defect that respects these rules affects that
client only. A defect that breaks them is a review failure, not something the runtime stops.

### 3. What an extension may and may not do

- **Tables:** its own, prefixed `ext_{name}_`, created by its own migrations, which run with the
  application's through `ABEON_MIGRATE_ON_START`. Removing an extension leaves its tables; dropping them is
  a separate, explicit migration.
- **Data of the application:** read through the application's models and services; never alter the
  application's tables or schema, and never touch `abeon_audit_log` or its triggers (ADR-0042). Writes
  that change client data go through `AuditLog`/`Audited` like the application's own.
- **Routes:** only through route-group helpers from the SDK contract (§4), never raw `Route::` calls, so
  every route carries the middleware of its surface:
  - panel: the `Panel::WEB_MIDDLEWARE` group (session, entitlement, `SendPanelToPlatform`) under the
    instance's path prefix, at `/ext/{name}`;
  - content API: the `content-api` group with site-key scopes (ADR-0040), at `content/v1/ext/{name}`;
  - the public surface of ADR-0033: not available to extensions in v1.
- **Permissions:** none of its own in v1. Auth's catalogue is one list per service and every declaration
  replaces it, so an extension permission would be deleted by the next declaration of any other instance,
  and would appear in every organisation's role editor. Extension routes reuse base permissions (e.g.
  `cms.pages.write`). Own permissions need a catalogue scoped to service and organisation in Auth first;
  that is a separate decision.
- **Keys and calls:** only the instance's own service key; no new credentials in the extension's code or
  repository.

### 4. Extension contract and extension points

`Abeon\SDK\Extensions` defines the contract shared by every business application: the interface an
extension's provider implements, the route-group helpers of §3, and the discovery of
`extra.abeon.extension`.

Extension points belong to each application. The first ones in the CMS:

- **Block types.** `BlockRegistry` becomes a singleton, and providers add types through `register()`
  (or tagged services) before first use. The panel gains a schema-driven fallback editor in `BlockFields`
  built from `BlockType::schema()`, so a block written in PHP alone needs no frontend build.
- **Event listeners and scheduled commands** through Laravel's own registration in the provider.

Panel pages and menu items from an extension need an Inertia resolver that finds pages outside
`resources/js/Pages` and a frontend build per client. They are left out of v1; the first client need
for one is a base change that adds that point.

A capability without an extension point is a change to the base, made once for everyone.

### 5. Base versions and the client image

- **Versioned base.** The base image workflow pushes a semver tag next to `:main` and `:<sha>`
  (e.g. `cyberstudioinc/abeon-cms:1.4.0`). An extension states the base versions it supports in
  `extra.abeon.requires` (a Composer-style constraint), checked by its CI before the build.
- **Pinned build.** The client image `cyberstudioinc/abeon-<app>-<client>` (a private Docker Hub
  repository) is built from a base pinned by digest, passed in by the dispatch of §6, never from `:main`.
  The recipe is multi-stage:
  1. a `composer:2` stage that copies `/app` from the base, adds the extension with `--no-update`, runs
     `composer update <extension>` only, and fails if any other locked package changes;
  2. a final stage `FROM` the same base digest that copies in the resulting `vendor` and runs
     `php artisan package:discover`.
- **Labels.** Every client image carries the base version, the base digest and the extension commit as
  OCI labels, which also answers ADR-0031's need to see which instance runs which version.
- **Deployment by digest.** Client deployments reference the client image by digest or sha tag, never
  by a moving tag, so a red build leaves the client on the image it already runs.

### 6. Releases flow downwards

- After a base image is pushed, the base workflow dispatches the image workflow of every repository
  with the GitHub topic `abeon-ext-<app>`, passing the new base version and digest.
- The dispatch authenticates as a GitHub App installed only on the extension repositories, with
  `actions:write` and nothing else. The base repository holds that App's credentials and no personal token.
- The extension's CI runs its tests on the new base image. Green: a new client image is pushed and the
  deployment is updated through the deployment layer (§8). Red: nothing changes for the client, and the
  failure is reported to the extension's owner.

### 7. AI works in the extension only

- An agent acting for a client gets a token scoped to that client's extension repository, with
  `contents` and `pull_requests` write and **no `workflows` permission**, so it cannot change the
  pipeline or reach its secrets.
- Changes go through a pull request, green CI and a human review before merge.
- CI checks back the rules of §3: migrations touch only `ext_{name}_` tables, no `DROP TRIGGER` or audit
  table access, no raw `Route::` registration, no Redis keys built without the instance prefix.
- Secrets (Docker Hub, read access to the SDK packages) live in a protected `main` environment, and images
  are pushed only after merge.

### 8. Deployment layer

The provisioner (ADR-0031 §5) needs to know which client runs which image:

- the mapping client → image lives with the instance assignment in AbeonUnified (the image is part of
  what was provisioned), not in the extension repository;
- the panel instances endpoint accepts an image override; this is a change to `bandziormanagement`, which
  needs the owner's approval before it lands;
- after a client image build, the deployment layer updates that one deployment's image and commits it.

Until the provisioner drives the panel, these steps are done by hand in the panel.

## Consequences

**Positive**

- Base releases reach every client without merges, and an extension that breaks on a new base is a red
  build before it reaches the cluster.
- Every running image says which base and which extension it contains.
- An agent can be given one client's code and nothing else.

**Negative**

- One extra repository and image per client that needs code, and none for clients that do not.
- The base has to grow extension points deliberately; v1 covers blocks, listeners and scheduled
  commands only.
- Isolation between an extension and its instance rests on review and CI, not on the runtime (§2).
- Versioning the base image, the GitHub App and the image override in the panel are prerequisites.

**Rejected**

- *Fork per client* — drift and per-fork patching (see Context).
- *Client code in the base repository behind feature flags* — every client ships every client's code,
  and an agent's access cannot be scoped to one client.
- *Own permissions per extension in v1* — Auth's catalogue is per service, and any declaration replaces
  it for all organisations.
- *No client code at all* — stays the default; content, configuration and the client's own front cannot
  express everything, and this ADR adds a path for the rest.

## References

- [ADR-0031](0031-app-instance-per-client.md) (instance per client; §1, §4, §5, §6, §7)
- [ADR-0032](0032-redis-shared-ephemeral-store.md) (shared Redis, prefixes)
- [ADR-0033](0033-public-surface.md) (public surface)
- [ADR-0039](0039-instance-service-identity.md) (service identity per instance)
- [ADR-0040](0040-content-api-addressing-and-site-keys.md) (content API site keys)
- [ADR-0042](0042-service-audit-log.md) (audit log)
- `abeon/sdk` 0.23 `Panel` and its CHANGELOG
