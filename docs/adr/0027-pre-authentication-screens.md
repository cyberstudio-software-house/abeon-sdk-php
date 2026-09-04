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
  > **Superseded in part by the amendment of 2026-08-17.** The application is Laravel + Inertia +
  > React and *does* have a frontend build. Everything else in this bullet still holds — no database,
  > no queue, no events, no state of its own, and every screen still a form that posts to this app's
  > own backend.
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
- Rejected alongside the other two shapes: putting the **flows** in `@abeon/ui` as components. That would
  make every application carry them again, which is the cost this ADR exists to avoid. See the amendment
  below for the line this draws — it is narrower than "screens".

## Amendment 2026-08-14 — the visual layer

As first written, this ADR rejected "putting the screens in `@abeon/ui` as components" without saying
what a screen is made of. Read literally that also bans the *look* of the front door from the design
system, and that reading is wrong. The rejection was about **duplication of flows**, not about where a
card and a form field are styled.

The line is drawn between two things:

| Layer | Owner |
|---|---|
| Layout, form markup, tokens, dark mode, the versioned reference on design.abeon.pl | `@abeon/ui` |
| Routing, CSRF, the POST to Auth, the `redirect` allowlist, sessions, cookies | `abeon-auth-ui` |

`@abeon/ui` now ships `AuthLayout`, `LoginForm` and `ForgotPasswordForm` — joined on 2026-08-17 by
`SetPasswordForm`, for invitation acceptance. They are **presentational
only**: a real `<form method action>` with named fields and a slot for hidden inputs. They hold no
route, no token and no call to Auth. Nothing about the arrangement this ADR chose changes — there is
still exactly one host for the flows, and the screens are still not copied across sixteen templates.

Two reasons the visual layer belongs there and not here:

- **Tokens live in one place.** The invented palette in `login.blade.php` (`--accent: #2f4b8f`) is not
  derived from `tokens.css`, so the one screen every user sees first is the one screen that does not
  match the platform. A design system that excludes the front door cannot fix that.
- **Pre-auth is a set of screens, and it grows.** Reset, invitation, verification, MFA challenge — the
  same argument that made this one application also makes one layout.

**Known debt this does not close.** ~~Per §Shape, `abeon-auth-ui` is Laravel + Blade with no frontend
build, so it cannot import these React components. Its two screens still carry ~90 lines of duplicated
inline `<style>` and the local palette.~~ **Closed on 2026-08-17** by the amendment below, which took
the second of the two options named here: auth-ui was given a build. The duplicated `<style>` and the
invented palette are gone.

## References

- Prompted by: the 2026-08-13 cutover drive — `abeon-concept-status-2026-08-13.html`, step 6
- Constrains: [ADR-0026](0026-administration-is-an-sdk-surface.md) (Auth ships no frontend — unchanged;
  this ADR names the owner for the one screen set ADR-0026 did not cover)
- Depends on: [ADR-0022](0022-organisation-provisioning.md) §6 (the invitation link needs a target),
  [ADR-0001](0001-jwt-format.md) (cookie names and the httpOnly access cookie this app sets)
- Not the owner: [ADR-0014](0014-suite-dashboard-composition.md) (`abeon-home` is the signed-in dashboard)
- Requirements: `abeon-auth-spec.md` FR-2 (login), FR-3 (reset), FR-16 (invitations), FR-4 (verification)

## Amendment 2026-08-17 — §Shape gains a frontend build

The 2026-08-14 amendment assigned the visual layer to `@abeon/ui` and then named what it
could not close: §Shape says "Laravel + Blade, no frontend build", so `abeon-auth-ui` could not
import the React components that had just been written for it. It offered two ways out —
consume `dist/tokens.css` from a shared Blade layout, or give the app a build — and decided
neither.

**This amendment takes the second.** `abeon-auth-ui` is Laravel + Inertia + React, mirroring
`abeon-boilerplate-inertia`, and its two screens are `AuthLayout` + `LoginForm` and
`AuthLayout` + `SetPasswordForm`. The ~90 lines of duplicated inline `<style>` and the invented
palette (`--accent: #2f4b8f`) are gone; the front door now renders from the same tokens as
every other screen on the platform, which was the whole point.

§Shape's "no frontend build" no longer holds. Everything else in it does: no database, no
queue, no events, no state of its own, and every screen still a form that posts to this app's
own backend.

**The form submission stays native.** `LoginForm` and `SetPasswordForm` render a real
`<form method action>`, and the pages use them that way rather than through Inertia's
`useForm()`. This is not caution: on success both controllers answer `redirect()->away()` to
another origin while relaying Auth's `Set-Cookie`, and an XHR submit would follow that redirect
in CORS mode carrying `X-Inertia` — failing *after* the browser had already stored the cookies.
The user would be signed in, looking at a form that appeared to do nothing, and a refresh would
"fix" it. No PHP test can see that: Laravel's test client has no CORS semantics and never sends
`X-Inertia`.

**What this costs, stated rather than discovered later.**

- The Consequences section already lists "a single point of failure for signing in". Extend it:
  a missing or corrupt `public/build/manifest.json` now means **every login on the platform
  returns 500**. Before this, `GET /login` had no build dependency at all.
- The front door no longer renders without JavaScript. The *form* still submits without it,
  which is why the native POST above is part of the trade rather than a detail.
- Dark mode is no longer free. `@abeon/ui` is `darkMode: ["class"]` and the Blade screens
  followed the OS through `prefers-color-scheme`, so the root view carries a pre-paint script
  that sets `.dark` from `matchMedia`. It reads the OS and nothing else — the stored preference
  lives behind `@abeon/shared`, which needs a session this application by definition does not
  have.

**Inertia specifically is a deliberate choice, not an inherited one.** This application will not
make a single Inertia visit: two pages, no `<Link>`, native POSTs, and every success path leaves
the origin. The protocol is installed for one thing — `errors` as a shared prop — while bringing
a middleware, an asset-version 409 path and `assertInertia` in the tests. The reason is that one
shape across the suite is worth more than a bespoke React mount that the next person has to read
from scratch. Anyone revisiting this should know it was weighed.

**`SetPasswordForm` is markup, not a flow.** The original Consequences section rejects "putting
the **flows** in `@abeon/ui` as components", and that still stands: the routing, the CSRF token,
the POST to Auth, the `redirect` allowlist and the cookie relay all remain here. What moved is a
card with two password fields. The 2026-08-14 amendment's table is the line, and this component
sits on the `@abeon/ui` side of it.

`ForgotPasswordForm` remains unwired. Auth has no password-reset endpoint, and a link to a screen
that cannot work — or worse, a stub telling a locked-out user that a mail is on its way — is
worse than its absence.

- Related: ADR-0026 (Auth ships no frontend — unchanged; its prohibition on `abeon-ui` growing
  screens is scoped to the *administration* surface, which this is not).

## Amendment 2026-09-04 — one repository, still two applications

`abeon-auth-ui` now lives at **`abeon-auth/auth-ui/`**. The application, the deployment, the
container and port 8140 are all unchanged; what disappeared is a seventh git repository.

### What changed the premise

The §Decision reasoning and the "Why not a frontend inside Auth" section both lean on one thing:
ADR-0026 keeps Auth free of a frontend so that replacing it with a company-wide Auth stays a
deployment change, and pre-authentication screens must survive that replacement. Co-locating them
with the service designed to be replaced was therefore backwards.

**That premise is retired.** `abeon-auth` is the platform's Auth — one multi-tenant instance
serving every organisation, differentiated at most by per-organisation branding on the login
screen. There is no later swap for the screens to survive.

### What this does not change

- **NFR-10 is untouched.** It enumerates the API surface a replacement must serve — JWKS, both JWT
  schemas, FR-22…FR-25 with FR-2/FR-8/FR-14, and FR-28 — and says nothing about repository layout.
  The objection above was about ergonomics, not the contract, and it was weaker than it was stated.
- **ADR-0026 holds as written.** Auth the *service* still ships no frontend: no Blade, no Inertia,
  no Vite, no `package.json` at the repository root, and nothing under `app/` importing from
  `auth-ui/`. The repository now contains a frontend build; the service does not. `abeon-auth`'s
  README states that distinction explicitly, because it is exactly the kind of sentence that goes
  quietly false.
- **Every other line of this ADR stands.** One host for the flows, one target for invitation and
  reset links, screens not copied across sixteen templates, the `redirect` allowlist rules 1-4,
  and the 2026-08-14 line between `@abeon/ui` markup and `abeon-auth-ui` routing.

### Consequences, revised

- The Consequences entry "**A third repository moves for one feature**" is now wrong: an endpoint
  in `abeon-auth` and a screen in `abeon-auth/auth-ui/` are one repository and one commit. The
  boilerplate redirect target, when it changes, is still a second.
- "**One more service to deploy, watch and route**" is unchanged and still accepted. This amendment
  removes a repository, not a service.
- **Reversible at low cost.** `git filter-repo --path auth-ui` extracts the directory with its
  history if this is ever wrong. That is not a hypothetical: the same tool ran against this
  repository on 2026-09-04 to purge a signing key before the first push.

### Mechanics worth recording

- All 11 commits were preserved, rewritten under `auth-ui/` by `git filter-repo
  --to-subdirectory-filter` before an unrelated-histories merge, so `git blame` still reaches the
  reasoning behind each change — including "refuse return-to URLs that two parsers read
  differently", which is rule 3 of §The return-to contract.
- Two path dependencies went one level deeper: `composer.json` to `../../abeon-sdk-php` and
  `package.json` to `file:../../../abeon-ui`. `repositories` feeds composer's content-hash, so
  the lock was rewritten with `composer update --lock` — path and hash only, no version resolved.
- No tooling collision. `abeon-auth`'s `phpstan.neon` and `phpunit.xml` resolve their paths
  relative to themselves and never descend into `auth-ui/`; the nested `auth-ui/.gitignore`
  anchors `/vendor`, `/node_modules` and `/public/build` to its own directory, which is how git
  reads an anchored pattern in a subdirectory.
