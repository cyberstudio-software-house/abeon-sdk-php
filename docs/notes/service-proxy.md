# Service proxy (`ProxiesToService`)

Background for `src/Panel/ProxiesToService.php`.

## Why a same-origin relay exists at all

The shared API client defaults `baseUrl` to `window.location.origin`, so every platform
endpoint the chrome calls has to exist on this application's own origin even though the
service is elsewhere. In production the path-prefix ingress routes `/api/v1/auth/*` and
`/api/v1/notifications/*` straight to their services, so this is development scaffolding —
but it is the scaffolding the whole chrome runs on locally, and two copies of it would drift.

## `SESSION_PATHS`

These are the paths that legitimately need the refresh cookie in the upstream request,
because Auth reads it from there rather than from a header. Nothing else does — and the
cookie is a seven-day credential that Auth stores hashed, so "nothing else" has to be
enforced rather than assumed.

Switching organisation rotates the refresh family rather than starting a new session
(ADR-0023 §2), so Auth reads the cookie on `/api/v1/auth/tenant` too. Leaving it off this
list left the family scoped to the *previous* organisation: the switch looked right until
the next refresh silently moved the user back.

## Query string on every method

The query string is carried for every method, not only `GET`. It used to be passed as an
options array on the `GET` branch alone, so `POST`, `PATCH` and `PUT` silently dropped it
and `DELETE` dropped the body as well. The first endpoint that paginates a `POST` or takes
a query on a `DELETE` would have stopped working through this relay only — and only
locally, because in production the ingress routes `/api/v1/*` past this code entirely.
"Works on production" is a bad shape of bug.

## Refresh-on-401

A 401 on a relayed call is the expiry case for a page nobody has navigated away from — the
bell polls, the access token has gone stale underneath it, and the browser has no way to
renew a cookie it cannot read.

The condition is the **refresh** cookie, not the access one. The access cookie carries the
access TTL as its lifetime, so the browser deletes it on expiry and the request arrives
with no token at all — which is a live session asking to be renewed, not an anonymous
caller. Requiring a presented access token here meant every poll after expiry 401'd, and
the suite could not see it because both of its cases supplied both cookies.

Never refresh in response to a failed refresh. A 401 from `/auth/refresh` means the
presented token is spent or revoked; retrying with the same cookie is a second use of it,
which ADR-0023 reads as a replay. Defence in depth — `AuthProxyController` already handles
that path before the relay sees it.

Idempotent methods only. A 401 usually means the request was refused before it did
anything, but "usually" is not a guarantee: a service that acts and only then discovers an
authorisation problem in another scope answers 401 having already acted, and replaying that
is a second write. Today every endpoint behind this relay is idempotent, so nothing breaks
either way — but this file is the template sixteen applications will copy, and "we replayed
your POST on a guess" is the wrong default to hand them. A `POST` that meets an expired
token gets its 401, and the caller decides.

## Forwarding `Set-Cookie`

Forward `Set-Cookie` from upstream — but only from the service that owns the session, and
only for the platform's own cookies.

Auth rotates the access cookie on token-issuing endpoints: `POST /auth/tenant` re-issues on
an organisation switch (ADR-0017), `refresh` rotates, `logout` clears. Dropping the header
meant the switch succeeded server-side while the browser kept the old token, so the UI
silently stayed in the previous organisation — which is why this forwarding exists at all.

It used to forward anything from anyone. AbeonUnified answers on this same origin through
the notification proxy, so it could set `abeon_token` and fix the session of every user
whose bell it answered. A service that is not the identity provider has no business writing
cookies here.

## Path traversal (`safePath`)

The route pattern is `.*`, so `..` segments arrive intact and Guzzle resolves them when it
builds the URI: `GET /api/v1/notifications/../../auth/secret` reached AbeonUnified as
`GET /api/auth/secret` — verified with `curl --path-as-is`. The impact today is limited,
because Unified's internal routes want a service token this relay does not have, so this is
hardening rather than a closed escalation. But it was the only boundary this code had, and
it did not hold.

Encoded traversal is refused outright rather than decoded: `%2e%2e%2f` passes through here
literally and is resolved by whatever sits upstream, so the only place that can judge it is
the one that refuses to forward it.

## Passthrough headers

`Content-Type` alone used to come through, which loses `WWW-Authenticate` on a 401,
`Retry-After` on a 429 — the one this platform now produces on both the notification emit
cap and the login throttle — and `Content-Disposition` on the first endpoint that returns a
file. An allowlist rather than a passthrough, because `Set-Cookie` is handled separately and
deliberately, and hop-by-hop headers must not be copied at all.

## Outbound request details

The chrome's calls are part of the page they were made from, so they carry the same
correlation id (ADR-0003). Without it every relayed call started its own trace and the two
halves of one page load could not be joined.

Auth's own `/auth/refresh` and `/auth/logout` read the refresh token from a cookie, and it
never reaches them otherwise — the relay speaks to upstream with a Bearer header, not with
the browser's cookie jar. **Only those two paths, and only to Auth.** This used to attach
the cookie to every relayed call, so a seven-day credential — which Auth itself stores
hashed — was sent in the clear to AbeonUnified on every poll of the notification bell,
which is to say on every page load.

The browser's own content type is used, not `application/json` imposed on everything.
Forcing it meant `multipart/form-data` could not pass through this relay at all, so the
first endpoint taking a file — an avatar, an attachment — would have failed here and
nowhere else.

## `AuthProxyController`

Same-origin proxy for `/api/v1/auth/*` calls from the SPA (useApps, usePreferences, the tenant
switcher, the App Store).

Refresh has exactly one entry point, and it is not this relay. The proxy route is
`/api/v1/auth/{any}`, so `refresh` used to pass straight through to Auth — bypassing
`TokenRefresher` and its single-flight entirely. Two tabs calling it at once is then two uses
of one refresh token, which ADR-0023 reads as a replay and answers by revoking the family.
Nothing in the browser calls it today, but this is a template every future application forks,
and a client-side retry-on-401 is an obvious thing to add.

Auth owns the session: it is the only upstream allowed to see the refresh cookie or to set
cookies on this origin.

## `NotificationProxyController`

Same-origin proxy for `/api/v1/notifications/*` — the topbar bell (ADR-0006), served by
AbeonUnified (ADR-0019).

This replaced `Dev\StubNotificationsController`, which returned static sample rows in `local`
only. The stub was written against the chrome hook rather than the ADR, so it answered the
unread count with `count` where the contract says `unread_count` — a divergence nothing could
see while both sides were ours.
