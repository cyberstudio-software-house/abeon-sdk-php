# ADR-0035: The platform host is the default address, and a router picks the instance

**Status:** Accepted
**Date:** 2026-09-24
**Amends:** ADR-0031 §2–3

## Context

PRD v1.0 §1.1 opened with one entry point: every application under one domain, `app.abeon.pl`. ADR-0031
reframed that per client — one host each, applications under path prefixes — and in doing so made a
client's own host **mandatory**. `Organisation::effectiveHost()` returns `$this->host ?? $this->slug.'.'.hostSuffix()`,
so there is no path through the code where a client is served from a shared address. Before the first
client is served, that means DNS and a certificate for every client, including the ones who never asked
for a domain of their own.

The owner restated the target on 2026-09-24: `app.abeon.pl` stays the shared surface — the main version
and the login — and per-client domains are something the platform **allows** where they earn their
place. The clearest case is the one ADR-0033 already built: a client's CMS publishing a site on the
client's own domain. That is a different need from "where does the panel live", and ADR-0031 answered
both with the same mechanism.

**And the part of ADR-0031 §3 that justified mandatory hosts was never built.** It promised that
"switching client means going to that client's host" and that "two clients in two tabs no longer
overwrite each other's token". Neither happened: `abeon-sdk-ts/src/react/use-tenant.ts` calls
`POST /api/v1/auth/tenant` and swaps the token in place, explicitly "with no page reload"; the `host`
field exists on `schemas/dto/tenant.json` and **no frontend code reads it**; and `AbeonWebAuth::decode()`
answers a foreign-organisation token with `abort(403)` rather than sending the browser to that
organisation's host. From the browser's point of view the running system already behaves as one host.

So this ADR is not introducing "one organisation at a time". It is writing down what the code does, and
choosing an address model that matches it, instead of leaving a promise in a record that nothing keeps.

## Decision

### 1. The platform host is where the panel lives by default

`app.abeon.pl` serves the panel. `organisations.host` becomes what its name says: an **optional** own
domain, set by `abeon:org:host` as today. `effectiveHost()` returns the platform host when no own domain
is set, instead of deriving `{slug}.{suffix}`.

Path prefixes per application are unchanged (ADR-0031 §2): `/cms`, `/crm`, one `path` per application in
the registry, links relative inside the suite.

### 2. A router at the entry picks the instance

The address no longer says which client's instance is meant — the organisation is in the token. A small
component at the entry resolves it:

1. validates `abeon_token` against the published JWKS (ADR-0005), exactly as a service would;
2. reads `org_id` from the verified claims, never from a header, a parameter or a path segment;
3. resolves the pair *(organisation, application)* to an instance address through AbeonUnified's
   registry — the assignment row and its provisioning state (ADR-0031 §5);
4. proxies, passing `X-Forwarded-Prefix` as the ingress does today.

**It fails closed.** No token, or a token it cannot verify, is a redirect to login. A pair it cannot
resolve is a 404. An instance that is not `ready` gets the answer the launcher gives — "being prepared" —
and never a proxy attempt at an address that does not serve yet.

The resolution is cached with an explicit TTL, and the TTL is a correctness parameter rather than a
tuning knob: a stale entry sends a user to an instance that has moved, and the failure looks like the
platform being down rather than a cache being old.

`tools/proxy.py` is the local stand-in, as it is for the ingress today. It currently routes on host and
prefix alone and reads no token; that is the gap this ADR opens for the deployment step.

### 3. The router is not an authorization boundary

It trusts the token for exactly one purpose: choosing a backend. Everything that decides what a user may
*do* stays where it is. The instance still refuses a user token whose `org_id` differs from its
`ABEON_ORG_ID` with a 403 (ADR-0031 §4), and that refusal — not the router — is what keeps one client
out of another's data. A router that resolved the wrong instance would be a bug that the instance
catches.

This makes ADR-0031 §6's per-instance service identity load-bearing rather than deferred. Its own words
were that an instance acting for any organisation is "acceptable only while every instance is
first-party code"; with a component in front that reads tokens and picks backends, that sentence needs
to stop being true before the first third-party instance exists.

### 4. One session on the platform host, one organisation at a time

The platform cookies are host-only on `app.abeon.pl` and shared by every application the browser opens
there. Switching organisation is a token re-issue (ADR-0017), which is what the switcher already does.

**Two organisations in two tabs do not work**, and this ADR accepts that instead of promising otherwise.
It is today's behaviour; ADR-0031 §3 said it would be fixed by per-host sessions and nothing implemented
that. A client who genuinely needs two organisations side by side takes an own domain, which is exactly
the case §5 keeps working.

### 5. The one-time code stays, for the clients that have their own domain

Nothing about ADR-0031 §3's mechanism changes for a client with an own domain: the code is minted for
the organisation that owns the host it is going to, it is single-use, short-lived and host-bound, and
the template exchanges it server-to-server at `/auth/callback`. The return-to check (ADR-0027) and
`GET /api/v1/auth/hosts/{host}` are untouched, and ADR-0033's public host remains a third address with
no session at all.

What changes is when it runs: for a client on the platform host there is no domain to cross, so there is
no handoff.

### 6. The root of the platform host is a launcher

`auth-ui`'s root is `redirect()->route('login')` today. It becomes: the login screen for an anonymous
visitor, and for a signed-in one the list of their applications and organisations, from
`GET /api/v1/auth/apps` and `GET /api/v1/auth/tenants` — data that already exists.

This is the minimal form of what ADR-0014 called the Suite landing. Widget composition, the part of
ADR-0014 that needs a service of its own, stays deferred; what this settles is that the landing has an
owner and an address.

## Consequences

**Positive**

- The suite has one address again, which is what PRD §1.1 asked for and what a person types.
- A client can be served the day it is created: no DNS, no certificate, no waiting on either.
- An own domain stays available for the clients that want one, and remains the answer for the two cases
  that need it — a client's public site (ADR-0033) and two organisations open at once.
- The record stops promising a per-host session behaviour that no code delivers.

**Negative**

- **A new component on the path of every panel request.** The platform's availability now includes the
  router's, and the router's includes the JWKS endpoint's. It has to be as available as the ingress and
  roughly as fast, which makes it infrastructure rather than a service.
- **One organisation at a time in a browser.** Written down rather than introduced, but written down —
  and a client who hits it has to be moved to an own domain rather than told to wait for a fix.
- The registry lookup and its cache are a new source of "it works for me": an instance that moved, a
  cache that did not, and a user who sees the platform as broken.
- Two address models to keep working — the shared host and the own domain — and the handoff path is now
  the less-travelled one, which is the one that rots.

**Rejected**

- *The client in the path* (`app.abeon.pl/acme/cms`). Routes without reading a token, and cookie paths
  could scope a session per client — which is why it was the first candidate. Rejected because every
  address grows a segment that means nothing to the person reading it, `PathPrefix` becomes two-level
  everywhere it is parsed, and a cookie `path` is not a security boundary inside one origin: a page on
  `/acme` can still make requests to `/bravo` and the browser will attach that client's cookie.
- *An own host for every client* — the current record. Costs no new component, and keeps per-host
  sessions genuinely available. Rejected because the suite then has no single address, every client
  needs DNS and a certificate before it can be used at all, and the per-host benefit is one the code has
  never actually provided.
- *The organisation in a query parameter.* Trivially forgeable, and the router would be trusting
  something the user can type.
- *A subdomain per instance* (`cms.acme.abeon.pl`). Rejected in ADR-0031 already: every move between
  applications crosses an origin.

## References

- **Amends ADR-0031 §2–3.** §1 (an instance per client), §4 (`ABEON_ORG_ID` and the refusal), §5
  (provisioning), §6 (queues and service identity) and §7 (the template) stand unchanged.
- ADR-0013 (full-page navigation), ADR-0014 (the Suite landing this gives an owner), ADR-0017 (switching
  by token re-issue), ADR-0027 (the login screen and the return-to allowlist), ADR-0033 (the public
  surface, a third address, untouched), ADR-0005 (JWKS validation, which the router performs).
- PRD v1.0 §1.1, §7.
- **Nothing here is implemented yet.** This is a decision taken before the code, so that the change to
  `Organisation::effectiveHost()`, the router in place of `tools/proxy.py`'s host-only routing, and the
  launcher in `auth-ui` explain themselves by one record rather than by each other.
