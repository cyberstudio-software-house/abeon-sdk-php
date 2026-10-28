# ADR-0040: The Content API has its own host, site keys and a stateless surface

**Status:** Proposed
**Date:** 2026-10-28
**Amends:** ADR-0036 §1, §4; ADR-0033 (narrows the public surface for multi-site clients)

## Context

ADR-0036 made the CMS a panel plus a Content API at `/{basePath}/api/content/v1`, read by a site's
server with a "read-only site key". A review of the CMS plan against the code on 2026-10-28 found that
the API has no address it can actually be reached on, and that the key is defined two ways.

- **No reachable host.** `basePath` is the panel prefix on the platform host. ADR-0035's router sits in
  front of it and fails closed: "No token, or a token it cannot verify, is a redirect to login." A site's
  server never holds `abeon_token`.
- **No stateless group.** Inside an instance, every route outside the public group belongs to `web` in
  the template's `bootstrap/app.php`, so it runs with a session, CSRF and `EnsurePanelSurface`. A `POST`
  from a site would get a 419.
- **The key is not read-only.** The CMS plan has two public write paths, form submissions and reporting
  missing interface phrases, and only staging keys may report phrases. ADR-0036 describes none of this.
- **Many domains, one host.** The plan has many sites per instance (one client runs 55 domains today).
  ADR-0033 allows one public host per *(organisation, application)*: `tenant_apps.public_host` is a single
  column, and the template binds one `Route::domain()`.
- **The review also found** preview tokens travelling in query strings, site scoping with no
  fail-closed rule, and no anti-abuse design for the public write paths.

## Decision

### 1. One platform host for content, routed by key

The Content API is served at `https://content.abeon.pl/v1/…` (the host is configuration). This host
has its own entry in the router of ADR-0035, and it never redirects to login.

A **site key** has the form `ack_{instance_ref}_{secret}`.
1. The router reads `instance_ref`, resolves it to an instance address through Unified's registry, the
   same as it does for `org_id`, and proxies.
2. An unknown or not-ready instance gets a 404 or 503.
3. The router is not the authorisation boundary (ADR-0035 §3). The instance verifies the secret.

A site's domain (`www.acme.com`, or any of 55) is the front's business, not the API's. A front calls the
API server-to-server from wherever it runs.

### 2. A stateless route group in the template

The template gains a `content-api` group: no session, no cookies, no CSRF, no `EnsurePanelSurface`,
JSON only. Its first middleware resolves the key and sets the request's site:

- A key is stored as a hash (with an id prefix for lookup) and compared in constant time.
- A key is bound to **one site and one environment** (`staging` or `production`). That binding is the
  only source of the request's site. No parameter can override it.
- Two keys can be active per site and environment during rotation, and `last_used_at` is recorded.
- **Scopes:** `read` (every key), `submit` (form submissions, file uploads for a form, password unlock;
  every key), `collect_phrases` (reporting missing interface phrases; staging keys only).

### 3. Site scope fails closed

Every query in the group runs under a site scope set by the key. A request without a site is an error,
never "all sites", following ADR-0018's rule. Every identifier that arrives in a request is checked to
belong to the key's site, or the request returns a 404: a form id, a page id, an item in an inquiry's
context, a file. Panel users are assigned to sites, and the panel applies the same scope.

### 4. Preview travels in a header, and is never cached

- **The token** goes in `X-Abeon-Preview`, server-to-server, together with the site key, and never in a
  URL.
- **Claims:** site, environment, the objects it opens (a page, a menu, a shared block), `exp` of at most
  one hour, and a `jti` that can be revoked.
- **No cache:** a preview response bypasses the CMS cache and carries `Cache-Control: no-store`. An
  invalid token returns 401. It never falls back to published content.
- **The integration guide** tells fronts to use their framework's draft mode with
  `Referrer-Policy: no-referrer` and `noindex`.

### 5. Public writes are defended at the CMS

- **Reader IP:** the front forwards the reader's IP in `X-Abeon-Client-IP`. The CMS trusts it only on a
  request with a valid key and uses it for rate limits per IP, form and key.
- **Captcha:** form submissions carry a captcha token (Turnstile or equivalent), and the CMS verifies it
  server-side.
- **Anonymous files:** the form's file field asks `POST v1/forms/{id}/uploads` for a single-use ticket
  for one submission, with a size limit. The file is owned by the submission. It is hidden from the
  media library, readable only with `cms.forms.read`, and counts against its own sub-quota.
- **Phrase reporting** is capped by count per namespace and in total, the key format is checked, the
  length is limited, and the default text is stored as plain text.
- **Protected pages and files:** `POST v1/unlock` checks a password (argon2id) with rate limits per
  object and per IP, and returns a short object-bound token sent in a header. Protected content is never
  cached and never listed by `search`, `content_list`, related items, sitemap or feed. Protected files
  are private and are read through a signed GET.

### 6. Versioning and documented envelope exceptions

ADR-0004 applies:
- v1 and v2 overlap for at least three months, with `Deprecation` and `Sunset` headers.
- `GET v1/meta` reports the instance's CMS version and the API versions it serves, so an agency can see
  which wave a client is on.

The documented exceptions to the `{data}` envelope are the flat `translations` map, the XML sitemap and
the feed.

### 7. The instance's public surface is for single-site clients

ADR-0033's public host stays as it is. A Blade site in the instance (ADR-0036 §4) is possible only for a
client with **one** site on that host. A multi-site client's sites are external fronts on the Content
API. Supporting many hosts on one instance's public surface would need a new ADR.

## Consequences

**Positive**

- A front anywhere can reach the API, and the panel's session rules are untouched.
- One key equals one site and one environment, which makes leaks and rotation small and traceable.
- Preview and protected content cannot leak through caches, listings or the Referer header.

**Negative**

- The router gains a second entry rule (key-based), and the registry needs `instance_ref`.
- Fronts must forward the reader's IP and a captcha token. The integration guide has to say how.
- One more host and certificate.

**Rejected**

- *The Content API on the client's public host (ADR-0033).* One host per instance cannot serve 55 sites.
  It would also tie the API to whether the client has a domain.
- *A user-style token for sites.* It would need refresh flows on servers we do not run, for what is a
  per-site credential.
- *A preview token in the query string.* It ends up in Referer headers, access logs and CDN keys.

## References

- Amends ADR-0036 (§1 path and "read-only" key; §4 Blade site only for single-site clients) and narrows
  ADR-0033. Uses ADR-0035's router, ADR-0018's fail-closed rule, ADR-0004's versioning, ADR-0037's files
  module (owned files) and ADR-0039 (instance identity) for the instance's own calls.
- Template code to change: `abeon-boilerplate-inertia/bootstrap/app.php` (route groups).
- Product document: `abeon-cms-prd-2026-10-27.md` chapters 7 and 14.
- Not implemented on 2026-10-28.
