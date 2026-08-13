# ADR-0023: Token revocation and session lifecycle

**Status:** Accepted
**Date:** 2026-08-13

## Context

ADR-0001 fixes the shape of a session — a 15-minute RS256 access token in the `abeon_token` cookie, a
7-day refresh token in `abeon_refresh` — and lists `jti` among the required claims with the note that it
*"enables revocation lists"*.

No revocation list exists, and the ADR does not say what the refresh flow does beyond existing. That
leaves three questions open at exactly the point where Auth stops being a stub:

1. **What does a refresh do to the token it consumed?** If the old refresh token stays valid, a stolen
   cookie is a 7-day credential and nothing ever notices it was stolen.
2. **What does "log out" mean?** `abeon-auth-stub`'s `DevLoginController::logout()` clears cookies. On a
   real service, clearing the client's copy of a credential that is still valid server-side is not
   logging out — it is asking politely.
3. **How is a token revoked before it expires?** This is the hard one, and it is hard *because* of
   ADR-0001's central promise: every service validates locally against cached JWKS and never calls Auth
   per request. A revocation list that has to be *queried* would undo the one property that keeps Auth
   off the hot path of sixteen services.

The temptation is to leave all three implicit and let the implementation decide. That is how the drift
this ADR set spent a day reconciling got started.

## Decision

### 1. Refresh tokens rotate, and reuse kills the family

Every successful refresh **consumes** the presented token and issues a new one. Tokens issued from one
login form a **family** (`family_id`, plus `parent_id` for the chain).

Presenting a token that has already been consumed is a **reuse event**. It means one of two things —
the token was stolen and replayed, or the legitimate client raced itself — and Auth cannot tell them
apart. It therefore takes the safe action: **revoke the entire family**, audit the event with
`revoked_reason = 'reuse_detected'`, and force a fresh login.

This is the standard OAuth 2.1 refresh-rotation rule and the reason it is worth stating: it converts a
stolen refresh token from a silent 7-day compromise into a loud, single-use one. The legitimate user
gets logged out, which is the point — a logout is a much better outcome than an undetected session
hijack.

### 2. A session is a row

```
refresh_tokens(id, family_id, parent_id, user_id, org_id, token_hash,
               user_agent, ip, last_used_at, expires_at, revoked_at, revoked_reason)
```

The token is stored **hashed**; a database read never yields a usable credential. Because the row
carries `user_agent`, `ip` and `last_used_at`, the same table is what later serves "your active
sessions" and "sign out everywhere" without a second mechanism.

`org_id` is on the row because ADR-0017 rotates cookies on tenant switch exactly as a refresh does — the
family survives the switch, the organisation it is scoped to does not.

**Logout revokes the family** and then clears the cookies, in that order.

### 3. Access-token revocation: a bounded window in v1, a fanout later

**v1 — the window is the mechanism, and it is ≤ 15 minutes.** Revoking a session revokes the refresh
family immediately; the outstanding access token keeps working until it expires. Auth publishes nothing
and services check nothing.

This is a real security property with a real bound, and it is written down here so nobody later assumes
the platform has immediate revocation when it does not:

> Revoking a user's access — disabling the account, removing a membership, changing a role — takes
> effect for **new** tokens at once and for **outstanding** tokens within 15 minutes.

For role and membership changes this is usually acceptable. For account compromise it is the number to
argue about, and the lever is `exp`, not architecture: shortening the access token narrows the window at
the cost of more refreshes.

**v2 — `auth.token.revoked` on a fanout exchange.** Auth publishes the revoked `jti` and its `exp`;
every service keeps a small local deny-list of *unexpired* revoked `jti`s and `JwtValidator` consults it
after signature verification. The list is bounded by construction — entries are dropped at `exp`, so it
holds at most the revocations of one token lifetime.

This preserves the local-validation promise: it is a push, not a query. A service that misses messages
degrades to v1 behaviour rather than failing open in some new way, because the deny-list only ever
*adds* denials.

**Rejected: an introspection endpoint** (`POST /auth/introspect`, RFC 7662). It gives exact revocation
and it puts Auth in the request path of every call in the platform — the precise thing ADR-0001 and
ADR-0005 are built to avoid. If Auth is down, everything is down.

**Rejected: a shared Redis deny-list** that every service reads. It is a smaller version of the same
mistake — one more piece of shared infrastructure on the hot path, and a cross-service coupling through
a datastore rather than through a contract.

### 4. What is revoked when

| Event | Refresh family | Access tokens |
|---|---|---|
| Logout | revoked | expire (≤ 15 min) |
| Reuse detected | revoked, audited | expire |
| Password reset / change | **all** families for the user | expire |
| MFA enrolled or reset | all families | expire |
| Membership removed | families scoped to that `org_id` | expire |
| Account suspended | all families | expire |
| Tenant switch (ADR-0017) | **not** revoked — rotated in place | superseded by the new token |

## Consequences

**Positive:**

- Refresh-token theft becomes detectable rather than silent, and the detection is automatic.
- One table answers rotation, reuse detection, the session list and "sign out everywhere" — no second
  mechanism to keep consistent with the first.
- The local-validation property that keeps Auth off the hot path survives both v1 and v2.
- The revocation window is a documented number, so it can be argued with. An undocumented one cannot.

**Negative / accepted:**

- **Up to 15 minutes of stale authorization.** Named above rather than buried. A user removed from an
  organisation may act in it for the remainder of their token's life.
- **Reuse detection logs out honest users** who race two refreshes — a mobile client resuming on a flaky
  connection is the usual case. Mitigate in the client (single-flight the refresh, which
  `@abeon/shared`'s `refreshTokenIfExpired()` is the natural place for), not by weakening the rule.
- **Rotation makes the refresh endpoint stateful and write-heavy.** Every refresh is a write. At 15-minute
  access tokens that is one write per user per 15 minutes — cheap, but it is no longer a pure read path
  and it needs the same rate limiting as login.
- **v2 adds an SDK dependency on the broker for a security property.** A service that cannot reach
  RabbitMQ silently keeps v1 semantics. That must be an alertable condition, not a quiet degradation.

## References

- Amends: [ADR-0001](0001-jwt-format.md) — gives `jti`'s "enables revocation lists" an actual mechanism,
  and fixes the meaning of the refresh flow it introduced
- Related: [ADR-0017](0017-tenant-switching.md) (switch rotates cookies exactly as refresh does),
  [ADR-0005](0005-service-to-service-auth.md) (service tokens are deliberately *not* revocable — bounded
  by a 5-minute TTL instead), [ADR-0002](0002-event-envelope.md) (`auth.token.revoked` envelope)
- Config: `abeon.auth.cookies.access` / `.refresh` (`config/abeon.php`)
- Plan: `abeon-auth-plan.md` §A2, §D2
