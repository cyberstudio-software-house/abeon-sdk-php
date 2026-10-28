# ADR-0036: The CMS is API-first, and the site in front of it is interchangeable

**Status:** Accepted
**Date:** 2026-10-28
**Amends:** architecture document §3.2 and §16 decision 2 (CMS on Next.js)

## Context

The CMS is the first business application the platform will build. Three documents disagree about what
it is.

- **PRD v1.0** (§3.2, §3.3, §7.1) lists `abeon-cms/ # CMS (Next.js + Laravel API)`, routes
  `app.abeon.pl/cms/*` to a Next.js process on port 3000, and justifies it with "SEO / SSR potrzebne".
  The architecture document carries this into §16 decision 2: *CSR by default, SSR only for CMS and
  E-commerce*.
- **ADR-0033** gave an instance a public surface and named the CMS as its case: the panel on the platform
  host, the published site on the client's domain, **the same image and the same database**. That is a
  Laravel instance rendering the site, not a separate Next.js process.
- **The architecture document's own v1.2 changelog** leaves "SSR for 2 apps or 4" open "until the Next.js
  template is built". That template does not exist (`abeon-podstawa-rozwoju.md`, row 7), and
  `abeon-concept-status.html` records two `@abeon/ui` defects a Next.js CMS would hit first: the Tailwind
  preset's ESM/CJS mismatch under Turbopack, and `useSidebarCollapsed` failing during SSR.

So the CMS was going to be the first application on a template nobody has built, while an accepted ADR
already described it differently.

Two facts changed the question. The CMS PRD (`abeon-cms-prd-2026-10-27.md`) was written from an analysis
of the sites we run today — telko.in, PCC, hafen_v2, magnetimarelli, checkstar — and the fronts of those
sites are not uniform: legacy PHP themes, Yii2 themes with Vue islands, Blade, and agency-built fronts.
And PRD v1.0's own split, *"Aplikacje Next.js mają osobny backend Laravel (API-only)"*, already makes the
content an API. The owner's stated intent (2026-10-23) is that the site in front of that API must be
replaceable — PHP or Next.js, ours or an agency's.

## Decision

**The CMS is a panel and a Content API. The site in front of it is not part of the CMS.**

### 1. Two surfaces, one application

- **The panel** is an Inertia application forked from `abeon-boilerplate-inertia`, like every other
  business application. It needs no SSR — editors are signed in and nobody indexes the panel.
- **The Content API** (`/{basePath}/api/content/v1`) is the CMS's product. It is stateless and is called
  server-to-server by a site, authenticated by a read-only **site key** (`X-Site-Key`, stored hashed,
  rotatable, rate-limited per key). Drafts are served only against a short-lived, page-bound **preview
  token**, with `Cache-Control: no-store`.

### 2. The API is the contract, so it is versioned and tested like one

- Blocks and collections are published as JSON Schema (`GET schema/blocks`, `GET schema/collections`).
  A site implements the schemas; it does not read the CMS's tables.
- A breaking change is a new major path (`v2`). CI holds a schema snapshot of `pages/resolve`, `menus`,
  `sitemap` and `schema/*`, and a change to it fails the build until the version is decided.
- Rich text is returned as the editor's JSON **and** as sanitised HTML, so a simple site does not have to
  implement a ProseMirror renderer to show a paragraph.
- Everything a site needs to be correct for search engines comes from the API: SEO fields, `alternates`
  (hreflang), breadcrumbs, redirects (a `301` from `pages/resolve`, plus `GET redirects` for an edge),
  sitemap entries and a feed. A site renders them; it does not derive them.

### 3. Freshness is pushed, not polled

On publish, unpublish, a move, a menu or settings save, and when a visibility window opens or closes, the
CMS sends a webhook signed with HMAC (`X-Abeon-Signature`) carrying the paths to revalidate, retried
from the queue with a delivery log. Next.js `revalidatePath`, a Blade response cache and an edge purge
all consume the same message. Platform consumers get `cms.page.published|unpublished` through the outbox
(ADR-0002).

### 4. No site ships with v1

The CMS does not render the client's site in its first version. ADR-0033 still stands: an instance
**may** serve a public surface, and a Blade site built later can live there — the same image, the same
database, calling the same serializer the API uses rather than HTTP to itself. A Next.js starter is
equally possible. Which one is built first, and when, is decided by the first client who needs it, not by
this ADR.

### 5. The Next.js template stops blocking the CMS

§16 decision 2 is narrowed: SSR is the **site's** concern, and the CMS application itself is Inertia. The
Next.js template is still the PRD's answer for an SSR-heavy application with its own UI (E-commerce,
Analytics, the AI assistant); it is built with the first of those that needs it.

## Consequences

**Positive**

- The CMS starts on the template that exists, with the auth, chrome, tests and CI it already has.
- Every client site we run today can be moved onto the CMS without rewriting its front first — the
  migration is the content, and the front follows when the client wants it.
- A site depends on one versioned contract, so a CMS release cannot silently break a client's site.
- The panel's availability and the site's availability are separate: a site with a response cache keeps
  serving while the panel is being deployed.

**Negative**

- **Every block and every collection type costs one implementation per site technology.** This is the
  price of interchangeability. It is kept bounded on purpose: v1 has eleven block types and four
  collection types, and the schemas are published so a site can be checked against them.
- Preview needs a site. Until one exists for a client, an editor has no visual preview — only the draft
  in the panel. The CMS PRD lists a reference site before the first pilot as an open question.
- One more credential type to manage (site keys) and one more outbound integration (webhooks) per
  instance.

**Rejected**

- *CMS on Next.js as PRD v1.0 drew it.* It would make the first application wait for a template that
  does not exist and for `@abeon/ui` SSR fixes, and it would still tie every client's site to one
  front technology.
- *A Blade site inside the CMS as the only front.* Quick for one client, but it makes the CMS's template
  engine the client's site — every agency-built or Next.js front would then be a second integration,
  written after the fact.
- *Visual in-page editing.* It requires every front to implement an edit mode, which is the opposite of
  an interchangeable front.
- *Published content without a key.* Simpler, but anyone could pull a client's whole content and there
  would be no per-client limit to enforce.

## References

- Amends: architecture document §3.2 and §16 decision 2 (dated note added there, 2026-10-28); resolves
  the v1.2 changelog's open "SSR for 2 or 4 applications" for the CMS.
- Builds on: ADR-0033 (public surface — unchanged, now optional for the CMS), ADR-0031 (one instance per
  client), ADR-0004 (REST envelope), ADR-0002 (events), ADR-0032 (Redis for the response cache and edit
  locks).
- Product documents: `abeon-cms-prd-2026-10-27.md` (scope, data model, API endpoints),
  `abeon-cms-files-podsumowanie-2026-10-28.html` (summary).
- Not implemented yet: no `abeon-cms` repository exists on 2026-10-28.
- **Amended 2026-10-28 by [ADR-0040](0040-content-api-addressing-and-site-keys.md):** the Content API is
  served at `https://content.abeon.pl/v1` with key-based routing, not under `{basePath}`; a site key is
  bound to one site and environment and carries `read`, `submit` and (staging only) `collect_phrases`
  scopes, so it is no longer "read-only"; a Blade site in the instance (§4) is only for single-site
  clients. **Amended the same day by [ADR-0041](0041-outbound-http-to-client-urls.md):** webhooks (§3) go
  through the hardened outbound client and carry a timestamped signature. Scope drift recorded: v1 now has
  six collection types (`abeon-cms-prd-2026-10-27.md`), and the pilot's site is built by the client or an
  agency, so the "reference site" question in Consequences is settled.
