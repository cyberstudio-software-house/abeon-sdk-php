# ADR-0031: One instance of each business application per client

**Status:** Accepted
**Date:** 2026-09-22

## Context

The product owner restated the target on 2026-09-22:

- the platform is **one suite**: one login, one set of notifications, one app launcher;
- a client **adds or buys an application**, and that creates **the client's own instance of it** — its
  own deployment and its own database;
- every application is built on the SDK and on the shared template
  (`abeon-shared-laravel-boilerplate`), so moving between applications looks and behaves like one
  product;
- clients may use **their own domains**.

None of the existing records says this. PRD v1.0 §1.1 made the *whole platform* single-tenant — a
separate copy with its own Auth per organisation, which gives no shared login. ADR-0016 reversed that
into one deployment of each application serving every organisation, with rows separated by `org_id`
(ADR-0018). A database per client, or a deployment per client, was never weighed: ADR-0018 compared
SDK scoping, per-service scoping and row-level security only. The one line close to the owner's model
is PRD §5B.5, which lists "activating a module creates a database and deploys the service" as future
work.

The platform half is already what this needs. Auth, organisations, memberships, permissions, the store
(`tenant_apps`), notifications and broadcasting are multi-tenant and stay that way. What is missing is
the business-application half. The code as it stands has these gaps:

1. The registry has one `path` per application name and `tenant_apps` has no address, so every client
   gets the same link.
2. Self-registration writes `path` into the one `apps` row, so two instances of `cms` overwrite each
   other on every boot.
3. **An instance does not know which client it belongs to.** `EnsureAppEntitled` checks only for a
   `{app}.` permission, so a user of client B holding `cms.*` is let into client A's CMS. The audience
   is the shared `abeon` (ADR-0001), so nothing else stops the token.
4. Switching organisation swaps the token in place, so on client A's instance the user is left holding
   client B's token.
5. With the default queue prefix, instances of one application compete for one queue. With a
   per-instance prefix each receives every organisation's events and nothing filters them.
6. The platform cookie lives on `.abeon.pl` (ADR-0013), which a client's own domain never receives.
7. Nothing creates a database, a deployment or a route when an application is enabled.

## Decision

### 1. A business application runs as one instance per client

Enabling an application for an organisation creates that organisation's instance: one deployment, one
database, bound to one `org_id`. All instances of an application run the same image; configuration
differs.

This applies to **business applications**. Auth and AbeonUnified remain single multi-tenant services
under ADR-0016 and ADR-0018.

### 2. One host per client, one path prefix per application

A client is reached at **one host**, and each of its applications lives under a path prefix:

```
panel.acme.com/cms  →  cms-acme   (database cms_acme)
panel.acme.com/crm  →  crm-acme   (database crm_acme)
beta.abeon.pl/cms   →  cms-beta   (database cms_beta)
```

A client without its own domain gets `{slug}.abeon.pl`; `slug` already exists on `organisations` in
Auth and in Unified's projection. The ingress routes on `Host(client) && PathPrefix(/app)`. This is
PRD §7 (one domain, path prefixes) applied per client, and PRD §16 #4 (a certificate per domain).

**One origin per client is the point of this shape.** Inside a client every link stays relative, so the
launcher, `crossAppHref`, `deriveCurrentApp` and notification links work as they do today. The
registry keeps one `path` per application, because `/cms` is the same for everyone; what an
organisation gains is a host. Self-registration stops being a collision, since every instance writes
the same value.

### 3. Login across domains: a central session and a one-time code

The shared `.abeon.pl` cookie cannot reach `panel.acme.com`, so:

- the platform cookies (`abeon_token`, `abeon_refresh`) become **host-only** on the client's host;
- the login session lives on `auth.abeon.pl`. A client host without a session redirects there; Auth
  answers with a **single-use, short-lived code** to `https://{client host}/auth/callback`, and the
  template exchanges it server-to-server for tokens and sets the host-only cookies. This is the
  authorization-code shape of OIDC, without the rest of OIDC;
- the return-to check (ADR-0027) accepts a host only if it is registered to an organisation, instead of
  a fixed list of origins;
- switching client means going to that client's host. A session is per host, so two clients in two
  tabs no longer overwrite each other's token (ADR-0017's one active organisation per browser becomes
  one per host).

### 4. An instance is bound to its organisation

Every instance is started with `ABEON_ORG_ID`. `JwtValidator::decodeUser()` refuses a user token whose
`org_id` differs with a 403 (`wrong-organisation`), so every path that reads a user token — web, API,
broadcasting — enforces it without a middleware anyone could forget to add. It is not a permission
check: permissions are per application, the binding is per instance.

The template answers that 403 with its error page and does **not** treat it as an expired session;
refreshing would return the same organisation and login would send the browser straight back. Once
organisations have hosts (§2), the template sends the user to the host of the organisation the token
names instead.

An unset `ABEON_ORG_ID` means "serves every organisation", which is right for Auth, Unified and a local
copy of the template. A set value that is not a positive integer stops the application rather than
being read as unset.

An instance's database holds one client's data, so a business application does **not** use
`BelongsToTenant` and has no `org_id` column to scope by.

### 5. Buying an application is provisioning

A `tenant_apps` row gains a state: `requested → provisioning → ready | failed`. Enabling an application
in the store (ADR-0015) writes `requested`, and Unified publishes `unified.app_instance.requested`
through the outbox. A **provisioner** — a component of the deployment layer, not of the platform
services — creates the database, the deployment, the ingress route and the certificate, then publishes
`…app_instance.ready` or `…app_instance.failed`. The launcher shows an application only when it is
`ready`, and shows "being prepared" before that.

Disabling an application stops the instance and keeps the database; deleting data is a separate,
explicit operation.

### 6. Events and service identity are per instance

- An instance's queues are prefixed `{app}-org{org_id}` by default once `ABEON_ORG_ID` is set, so two
  instances never compete for one queue; `ABEON_QUEUE_PREFIX` still overrides it (e.g. `cms-acme`).
- `EventConsumer` drops events for other organisations when `ABEON_ORG_ID` is set.
- A service token identifies the instance (`cms@acme`), and the receiving side checks that the token's
  `org_id` belongs to that identity. Until then an instance can act for any organisation — acceptable
  only while every instance is first-party code.

### 7. Every business application is built from the template

A business application **must** start from the shared template and run under its path prefix. That is
what makes moving between applications look and behave like one product: the chrome from `@abeon/ui`,
the data from `@abeon/sdk-ts`, full-page navigation from ADR-0013.

## Consequences

**Positive**

- Matches the owner's model: one suite, one login, an application per client, bought and provisioned.
- Independence per client: an instance can be upgraded, restored or moved without touching anyone else.
  A client's data sits in its own database, which makes export and deletion trivial.
- Most of the navigation code is untouched, because each client is one origin.
- Two clients in two tabs work, which today they do not.

**Negative**

- **Deployments multiply**: applications × clients. Sixteen applications for twenty clients is 320
  deployments, each needing CPU, memory and a database. Idle instances cost the same as busy ones until
  instances can be put to sleep.
- **Every release is a rollout over N instances.** Needs a common image, waves, and a way to see which
  instance runs which version.
- Migrations run per instance; a failing migration fails one client, not all, but must be visible.
- The provisioner, DNS for client domains and certificate issuing are new moving parts that the
  platform does not have today.
- The login flow grows a redirect and a code exchange.

**Rejected**

- *One deployment per application, rows separated by `org_id`* (ADR-0016/0018 as applied to business
  apps). Cheapest to run, but no independence per client — which is the requirement. Stays in force for
  Auth and Unified.
- *One deployment, one database per client, connection switched by `org_id`.* Separates data but not
  deployments or versions, and adds connection routing to every application.
- *A subdomain per instance* (`cms.acme.abeon.pl`). Every move between applications crosses an origin,
  so the registry, links and current-app detection all need absolute addresses per organisation.
- *Keep the `.abeon.pl` cookie.* Does not reach a client's own domain.

## References

- PRD v1.0 §1.1, §5B.5, §7, §16 #4
- ADR-0001 (token, `aud`), ADR-0005 (service tokens), ADR-0010 (catalogue `path`), ADR-0013 (full-page
  navigation, shared cookie), ADR-0015 (store and entitlement), ADR-0016 (multi-tenant organisations),
  ADR-0017 (tenant switching), ADR-0018 (tenant scoping), ADR-0022 (organisation provisioning),
  ADR-0027 (return-to allowlist)
- Analysis: `abeon-model-klientow-2026-09-22.html` in the suite root
- Amends ADR-0010, 0013, 0015, 0016, 0017, 0018 and 0022 (notes in their `## References`, 2026-09-22)
- Implemented so far: §4 and §6 (binding and queues) in `abeon/sdk` 0.7.0 and the template
