# Web auth middleware

Background for `src/Panel/AbeonWebAuth.php`.

## What it is

Web-friendly auth middleware: reads the `abeon_token` cookie, validates it with the SDK's
JwtValidator, and populates AuthContext. On any failure redirects the browser to the configured
Auth URL's `/auth/login`.

Counterpart to the SDK's API-only `abeon.auth` middleware (which throws RFC-7807 401s — useless
for a browser flow). Apply this on Inertia routes BEFORE anything that reads `auth_user()` /
`AuthContext`.

## The usual expiry case is no token at all

The usual expiry case is not an invalid token — it is **no token at all**. The access cookie is
written with the access TTL as its lifetime, so the browser deletes it the moment it expires and
the request simply arrives without one, carrying a refresh cookie that is good for another week.

## Forwarding the bearer header

The JWT is forwarded to any downstream `abeon.auth`-protected route reached via internal
redirect/forward, so the SDK middleware sees it. An explicit header wins — except after a
refresh, where whatever it carried is the token that just expired.

## A token for another organisation

**A token for another organisation is not unusable** (ADR-0031 §4). On an instance bound by
`ABEON_ORG_ID` it is valid and simply not for here, so treating it like an expired one would
refresh it, get the same organisation back, send the browser to login, and have login — which
already has a session — send it straight back. The user gets a 403 instead, rendered as
`Errors/WrongOrganisation` rather than the generic "no permission" page: it names the
organisation this instance serves and, when the user is a member, offers the switch
(`POST /api/v1/auth/tenant`, ADR-0017) and reloads. The `/api/v1/auth/*` proxy does not run
`abeon.auth`, so the switch works from the very page that was refused. Other 403s from the
validator (no organisation at all) keep the plain page.

## Redirecting to login

The screen lives in `abeon-auth-ui`, not in Auth: Auth is API-only (ADR-0026), and
pre-authentication screens have one owner rather than a copy per application (ADR-0027).
Pointing this at Auth is what produced a 404 during the cutover.

`redirect` is the caller's *request*, not a promise — `abeon-auth-ui` validates it against an
allowlist of platform origins before using it, because otherwise this parameter would make the
login page an open redirect.

**Why `Inertia::location()` and not `redirect()->away()`.** Only the first hit on this
application is a browser navigation; every click after it is an XHR visit carrying `X-Inertia`.
XHR follows a 302 itself, so the browser would re-issue that Inertia-flavoured request against
`abeon-auth-ui`, across an origin boundary — where CORS cuts it off, or Inertia receives a
response that is not an Inertia response and throws in the console. Either way the click does
nothing, at the one moment the user most needs to be told their session ended.

Inertia's protocol answers this with a 409 carrying `X-Inertia-Location`, which the client turns
into a real browser navigation. `location()` degrades to the same `Redirect::away()` for a
non-Inertia request, so the first-hit case is unchanged.
