# ADR-0025: Auth service invariants

**Status:** Accepted
**Date:** 2026-08-13

## Context

Turning `abeon-auth-plan.md` into a testable requirement set (`abeon-auth-spec.md`) surfaced six
questions that no existing ADR answers and that the implementation cannot be left to decide, because
each one either lands in the first migration or determines how a failure looks in production.

They are collected in one ADR rather than six because they share a subject — the behaviour of one
service at its edges — and because six separate records of "what Auth does when X" would be harder to
read than one. Each is stated with the alternative that was rejected.

The six: what happens to a user with no organisation, which organisation a login lands in, what happens
when a token would exceed its size budget, what happens when the aggregated JWKS source is broken,
what makes the audit log actually append-only, and which of two P0 rules wins when rate limiting meets
refresh-reuse detection.

## Decision

### 1. A user with no active membership cannot authenticate

Login returns **403** with a Problem Details body of type `no-organisation`. Auth **never** mints a user
token without an `org_id`.

This is forced by the rest of the platform rather than chosen freely: ADR-0016 makes `org_id` an
authorization dimension, ADR-0001 makes it a required claim, and `JwtValidator::decodeUser()` already
rejects a token whose `org_id` is absent or not an integer. A token minted for an org-less user would
therefore be refused by every service that received it — the only question was whether the failure
happens at login with a comprehensible status, or later as a 500 from the issuer.

The state is reachable: a user exists before their first membership does whenever an invitation creates
the account, and a user can be removed from their last organisation.

**Rejected: mint a token with `org_id: null` for "platform-level" users.** There is no such user. The
one genuinely organisation-less actor is a service, which has its own token type (ADR-0005).

### 2. The organisation a login lands in is defined, in this order

1. `users.default_org_id` if set and still an active membership,
2. otherwise the most recently used active membership,
3. otherwise the lowest `org_id`.

The stub picks the first membership in array order (`Memberships::default()`), which is fine for a
fixture and arbitrary for a person who belongs to three organisations. `default_org_id` is a nullable
FK on `users`; adding it later means altering the one table nobody wants to migrate.

### 3. A token that would exceed its size budget is refused, never truncated

ADR-0024 sets a 4 KB budget and a build-time test. At runtime, if expanding a real user's roles would
breach it, Auth **refuses to mint**: 500 with a Problem Details body and an alertable log event.

**Rejected: truncate the permission list.** A silently truncated token produces an account that is
randomly missing permissions, with nothing in the log and nothing in the UI to explain it. The user
reports "sometimes the button doesn't work" and the cause is invisible. A loud failure affects one
person, names itself, and points at the real fix — which is the role-only-token escape hatch ADR-0024
already describes.

### 4. A broken JWKS ConfigMap degrades, it does not stop the service

If the aggregated service-key ConfigMap (ADR-0005) is missing or malformed, `GET /.well-known/jwks.json`
serves **Auth's own keys**, returns 200, and emits an alertable log event once per load.

A duplicate `kid` across sources is different and **does** fail startup: serving two different keys
under one identifier makes signature validation non-deterministic, and there is no safe way to pick.

**Rejected: fail startup on a bad ConfigMap.** That trades a service-to-service problem for a total
outage of user login. The asymmetry is deliberate — user tokens are signed by Auth's own keys, which are
present; only cross-service validation is degraded.

### 5. The audit log is append-only in the database, not by convention

The application's database role holds `INSERT` and `SELECT` on `audit_log` and **not** `UPDATE` or
`DELETE`. The grant is part of the migration and the Helm chart, not a code comment.

"Append-only" asserted in prose is not a property; it is a hope about every future code path. And it is
one of the few decisions that genuinely cannot be applied later: a log that *could* have been modified
for a year does not become trustworthy the day the grant is revoked.

### 6. Rate limiting wins over reuse detection

ADR-0023 revokes a whole refresh family when a consumed token is presented again. Rate limiting applies
to `/refresh` as it does to every credential endpoint. These interact badly: a client that receives a
429 and retries after backoff would present a token that — if the 429 had consumed it — is already
spent, and lose the session.

**A 429 MUST NOT consume the presented refresh token.** Rejection for rate limiting happens before the
token is exchanged, so a retry after backoff is an ordinary refresh, not a reuse event.

Clients should additionally single-flight refreshes; `refreshTokenIfExpired()` in `@abeon/sdk-ts` is
where that belongs. That is a mitigation, not the rule — the rule has to hold for a client that gets it
wrong, because the failure mode is logging out an honest user.

## Consequences

**Positive:**

- Five of the six failure modes become observable and named instead of surfacing as a 500, a silent
  omission, or an unexplained logout.
- All six are cheap now and expensive later: three touch the first migration, one touches a database
  grant that cannot be applied retroactively with any credibility.
- The one asymmetry that looks inconsistent — degrade on a bad ConfigMap, fail hard on a duplicate
  `kid` — is written down with its reasoning, so it does not read as an oversight.

**Negative / accepted:**

- **§1 makes an empty membership set a dead end** rather than a degraded login. An invited user who has
  not yet been added to an organisation cannot sign in at all. That is correct — there is nothing for
  them to see — but the invitation flow must therefore create the membership, not just the user.
- **§2 adds a column to `users`** that is null for most of its life.
- **§3 means a sufficiently privileged user cannot log in at all** rather than logging in with reduced
  rights. That is the intended trade and the reason ADR-0024's budget test runs from day one.
- **§5 constrains operations:** log retention and any future redaction need a separate privileged path,
  and the deploy role must be distinct from the application role.
- **§6 leaves a narrow window** where an attacker who can trigger rate limiting can replay a refresh
  token without tripping detection. Bounded by the token's own expiry and preferable to logging out
  honest users on flaky connections.

**Noted, not decided here:** `schemas/dto/user.json` still types `org_id` as `["integer", "null"]`,
which §1 makes unreachable for any authenticated response. Tightening it to a non-null integer is a
golden-fixture change on both sides and should be done deliberately, when the fixtures are next
touched, rather than as a side effect of this ADR.

## References

- Sources: `abeon-auth-spec.md` FR-13, FR-19, FR-23, FR-27, FR-33, FR-8.6 · findings A-04, A-06, A-08,
  A-12, A-13, A-14, A-17
- Depends on: [ADR-0016](0016-multi-tenant-organisations.md) (`org_id` is authorization),
  [ADR-0023](0023-token-revocation-and-sessions.md) (families and reuse detection),
  [ADR-0024](0024-permission-expansion.md) (the size budget this refuses to breach)
- Related: [ADR-0005](0005-service-to-service-auth.md) (the aggregated JWKS this degrades from),
  [ADR-0004](0004-rest-envelope-and-errors.md) (Problem Details for `no-organisation`)
- Code verified 2026-08-13: `src/Auth/JwtValidator.php` (`decodeUser` rejects a null `org_id`),
  `abeon-auth-stub/app/Support/Memberships.php` (`default()` returns the first membership),
  `abeon-auth-stub/app/Auth/UserTokenIssuer.php` (throws rather than minting org-less)

## References

- **Amended 2026-08-14 — §6 keeps its guarantee and loses its ordering.**

  §6 required the throttle check to run *before* the presented refresh token is looked
  up, so that a 429 could never consume it. The ordering delivered that, and also made
  the limit apply to **valid** tokens — and the limit is keyed on IP. Behind a shared
  egress, a NAT or a corporate proxy, that is a denial of service on everyone: eleven bad
  attempts from one client locked refresh for every user at the same address, verified on
  a running service.

  It also bought very little. What a refresh token protects is 64 characters of CSPRNG
  output; a ten-per-minute limit is not what stands between an attacker and guessing it,
  and replay is already answered by family revocation (ADR-0023).

  The throttle now applies **only to failures**. A valid token is never subject to it,
  so it can never be consumed by a 429 — §6's actual requirement, satisfied more
  strongly than the ordering satisfied it. Looking a token up does not consume it; only
  rotation does.

  Deployment note the old rule hid: with the limit keyed on `$request->ip()`, a service
  behind a proxy that does not configure `TrustProxies` sees one address for every
  caller, which turns any per-IP limit into a global one. That is worth fixing wherever
  a per-IP limit remains — login still has one, and there it protects a low-entropy
  secret, so it earns its place.
