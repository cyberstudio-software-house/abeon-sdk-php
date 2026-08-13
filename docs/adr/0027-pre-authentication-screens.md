# ADR-0027: Pre-authentication screens live in their own application

**Status:** Accepted
**Date:** 2026-08-13

## Context

Pointing `abeon-boilerplate-inertia` at the real Auth service surfaced a gap nobody owned: **there is no
login screen anywhere.** `AbeonWebAuth` redirects an unauthenticated browser to
`{auth.url}/auth/login`, which `abeon-auth-stub` served as a dev picker page and which `abeon-auth`
deliberately does not serve — ADR-0026 gave it no frontend at all. In a browser the redirect is a 404.

ADR-0026 did decide that screens belong to the boilerplate, but it decided that for the **administration
panel**. Login is different in kind, and the difference is what this ADR turns on.

Three shapes were available:

1. **A page in the boilerplate template.** Every application gets its own `/login`. No new service.
2. **One nominated application owns it** — `abeon-home`, which ADR-0014 already reserves.
3. **A dedicated application** for pre-authentication screens only.

## Decision

**Pre-authentication screens live in one dedicated application, `abeon-auth-ui`.** It owns login now, and
is the home for password reset, invitation acceptance, email verification and any future MFA challenge or
external-IdP return.

`abeon-auth` remains API-only — ADR-0026 is unchanged, and the swap-out criterion with it.

### Why not a page in the boilerplate

**The invitation link decides it.** ADR-0022 §6 has user creation return a one-time link to its caller.
If login lives per application, *which application's URL goes into that link?* There is no good answer,
and the same question returns for every password reset and address verification. One host for these
flows gives one canonical answer.

Two further reasons, in order of weight:

- **This is not one page, it is a set of flows.** Login, reset, invitation acceptance, verification, MFA
  challenge, IdP return. Copying that across sixteen templates is a different problem from copying one
  page — and the copies are what ADR-0026 accepted for screens that are *inside* an application, not for
  the front door.
- **The boilerplate is a template for an authenticated application.** Its routes are wrapped in
  `AbeonWebAuth` and `EnsureAppEntitled`; the whole chrome assumes a token, an organisation and a set of
  grants. A pre-authentication screen has none of those and would have to be lifted out of every one of
  those assumptions. Possible, but it is a foreign body in that template.

### Why not `abeon-home`

ADR-0014 reserves `abeon-home` for the **suite dashboard** — a surface for a user who is already signed
in. Making the signed-in dashboard the owner of the signed-out screens couples two unrelated lifecycles,
and it does not exist yet, so it cannot unblock anything today.

### Why not a frontend inside Auth

It is the simplest thing to build and the most expensive thing to own. ADR-0026 kept Auth free of a
frontend build precisely so that replacing it with the company-wide Auth stays a deployment change. A
login screen inside Auth would have to be rebuilt against whatever that service exposes.

### Shape

- **Laravel + Blade, no frontend build, no database, no queue, no events.** It has no state of its own:
  every screen is a form that posts to the app's own backend, which calls Auth's public API.
- **The form does not post cross-origin to Auth.** The backend calls Auth server-side and forwards
  Auth's `Set-Cookie` to the browser, exactly as `AuthProxyController` already does in the boilerplate.
  This keeps the flow same-origin, avoids CORS-with-credentials, and works identically in development
  where the services sit on different ports.
- It MAY depend on `abeon/sdk` for configuration and correlation-id propagation. It MUST NOT hold a
  signing key, a database connection, or any user state.

### The return-to contract

`AbeonWebAuth` today redirects without saying where the user was going, so a deep link into
`/crm/contacts` cannot survive a login. That is fixed here, and fixed carefully:

1. The redirecting application appends its intended destination as a `redirect` query parameter.
2. `abeon-auth-ui` MUST validate that parameter against a configured **allowlist of platform origins**
   before using it, and MUST fall back to a configured default on any failure.
3. Validation is on the parsed origin, not on a string prefix. `https://app.abeon.pl.evil.test/` starts
   with the right characters and is a different host.
4. A rejected target MUST NOT be echoed back into the page.

Without rule 2 this endpoint is an open redirect on the platform's front door — the one page every user
reaches while unauthenticated, and therefore the most valuable one to an attacker running a phishing
hop.

## Consequences

**Positive:**

- One URL for every pre-authentication flow, so invitation and reset links have an obvious target.
- Auth keeps no frontend, so ADR-0026's swap-out criterion survives intact.
- The screens are not copied per application, so branding and the flows themselves stay in one place.
- The application is genuinely small — no database, no queue, no events — so "one more service" costs
  little beyond its deployment.

**Negative / accepted:**

- **One more service to deploy, watch and route.** In a platform that currently runs one, that is a real
  step. It is accepted because the alternative multiplies the flows by sixteen.
- **A single point of failure for signing in.** If `abeon-auth-ui` is down, nobody can log in anywhere —
  though existing sessions keep working, because tokens validate locally against cached JWKS (ADR-0001).
  The same is already true of Auth itself for refresh.
- **A third repository moves for one feature** when a flow changes: an endpoint in `abeon-auth`, a screen
  here, and possibly a redirect target in the boilerplate.
- Rejected alongside the other two shapes: putting the screens in `@abeon/ui` as components. That would
  make every application carry them again, which is the cost this ADR exists to avoid.

## References

- Prompted by: the 2026-08-13 cutover drive — `abeon-concept-status-2026-08-13.html`, step 6
- Constrains: [ADR-0026](0026-administration-is-an-sdk-surface.md) (Auth ships no frontend — unchanged;
  this ADR names the owner for the one screen set ADR-0026 did not cover)
- Depends on: [ADR-0022](0022-organisation-provisioning.md) §6 (the invitation link needs a target),
  [ADR-0001](0001-jwt-format.md) (cookie names and the httpOnly access cookie this app sets)
- Not the owner: [ADR-0014](0014-suite-dashboard-composition.md) (`abeon-home` is the signed-in dashboard)
- Requirements: `abeon-auth-spec.md` FR-2 (login), FR-3 (reset), FR-16 (invitations), FR-4 (verification)
