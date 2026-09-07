# ADR-0024: Permissions are expanded at issue time

**Status:** Accepted
**Date:** 2026-08-13

## Context

ADR-0001 puts a `permissions` array in every user token and constrains each entry to
`{app}.{resource}.{action}`:

```json
"pattern": "^[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*$"
```

ADR-0016 then makes those grants **per membership** — a user is an admin in one organisation and
read-only in another — which means Auth re-derives them on every login and every tenant switch.

What nobody wrote down is whether a *wildcard* grant is a thing. It is not, according to the schema, and
the code agrees. Verified 2026-08-13:

| | |
|---|---|
| `src/DTO/User.php:53` | `hasPermission()` is `in_array($permission, $this->permissions, true)` — exact match |
| `abeon-sdk-ts/src/react/use-auth.ts:42` | `hasPermission` is `permissions.includes(permission)` — exact match, same semantics |
| `schemas/auth/jwt-user.json:22` | the pattern above; `*.*.*` does not satisfy it |

But `abeon-auth-stub/config/dev-users.php` seeds its superuser with `'permissions' => ['*.*.*']`, and
its own comment claims this *"satisfies the permission half for every app"*. **It does not.** It works
for exactly one thing — `StubAppsController::isVisible()`, which special-cases a leading `*` — and fails
every `hasPermission()` gate that a real business application would put in front of a controller. The
dev admin is a superuser in the app switcher and a nobody everywhere else.

Nothing has broken yet only because no business service exists to enforce a permission. The first one
will.

## Decision

### 1. No wildcards on the wire

A token's `permissions` array contains **literal, fully-qualified grants only**, exactly as the schema
already says. `*`, `crm.*` and `*.*.*` are not valid values and Auth must never mint them.

### 2. Auth expands roles to literal grants at issue time

Roles are the administrative unit; a token is a snapshot of what a role means *now*. Auth resolves
`membership → roles → role_permissions` and writes the flattened, deduplicated set into the token at
login, at refresh and at tenant switch (ADR-0017 re-mints from the target membership, so expansion
happens there too).

"Give this role everything" is expressed at the **catalog** level — the role is granted every permission
the platform currently declares — not by a magic string in the token. Which means Auth needs the
permission catalog to expand such a role, and `service.permissions.declared` (already published by
`PermissionsDeclarator`) is where it comes from. A permission that no service has declared cannot be
granted; that is a feature.

**Rejected: wildcard grants plus glob matching in the SDK.** It is fewer bytes and it is the wrong
trade. Sixteen services would each depend on a matcher whose subtleties (`crm.*` vs `crm.contacts.*` vs
precedence against an explicit deny) are exactly the kind of thing that gets implemented three
different ways. It also moves authorization semantics out of the service that owns authorization and
into a library compiled into every consumer — so fixing a matcher bug becomes sixteen deploys. The
current exact-match implementations in both SDKs are an asset, not a limitation.

### 3. There is a size budget, and it is checked by a test

Expansion has a cost. A superuser across ~16 applications with ~10 permissions each is ~160 strings —
roughly 4–6 KB of JWT, travelling in a cookie **and** in an `Authorization` header, against the 8 KB
header limit typical of nginx and Traefik defaults.

**Auth ships a test asserting the encoded token size for a realistic superuser fixture, with a 4 KB
ceiling.** Not a guideline — a failing build. The ceiling is half the typical limit because the token
travels in two places and shares the header budget with correlation ids, cookies and proxy headers.

**If that test ever fails, the escape hatch is role-only tokens**: the token carries `roles[]`, and each
service resolves roles to permissions from its own declared set. That is a genuine contract change —
`permissions` becomes optional in `jwt-user.json`, and `hasPermission()` gains a resolution step — and
it is dramatically cheaper to make while zero business services exist than after sixteen do. The budget
test exists to force that decision early enough to be cheap, rather than discovering it as a 502 in
production.

### 4. The stub's wildcard does not survive

`abeon-auth-stub` may keep `*.*.*` as a dev convenience for as long as it lives — it is throwaway and
its own controller compensates. The real service must not, and the boilerplate must not learn to depend
on it. When Auth replaces the stub, the dev superuser seed becomes a role holding every declared
permission.

## Consequences

**Positive:**

- The JWT schema, both SDKs and the token issuer finally agree. Today two of the three are right and the
  only issuer is wrong.
- Authorization semantics stay in the service that owns authorization. A consumer's job is set
  membership, which is hard to get wrong.
- The catalog gains a purpose beyond documentation: it is what makes "grant everything" expressible
  without a wildcard.
- The size ceiling turns an invisible scaling wall into a failing test on the day the design is still
  cheap to change.

**Negative / accepted:**

- **Tokens grow with privilege**, and the most privileged users — the ones most likely to be
  administering the platform when something breaks — carry the largest tokens. §3 is the mitigation and
  the tripwire.
- **A permission granted while a token is live is not effective until the token is re-minted** (≤ 15
  minutes, per ADR-0023). Consistent with everything else in the platform, and the reason ADR-0010's
  `/auth/user` re-derives grants server-side for display.
- **Auth cannot expand a "grant everything" role until services have declared their permissions.** In
  slice order that means the catalog is populated by `service.permissions.declared` events, which need
  RabbitMQ — so early roles are enumerated by hand. Acceptable, and it is why the platform ships with
  system role templates.
- **Deduplication is Auth's problem.** A user with three overlapping roles must not get the same grant
  three times in the array; the size budget makes this load-bearing rather than cosmetic.

## References

- Amends: [ADR-0001](0001-jwt-format.md) — the `permissions` claim's pattern is now backed by a stated
  issuing rule, and the size budget constrains what may go in it
- Related: [ADR-0016](0016-multi-tenant-organisations.md) (grants are per membership),
  [ADR-0017](0017-tenant-switching.md) (switch re-mints, so it re-expands),
  [ADR-0010](0010-auth-me-and-apps-endpoints.md) (`/auth/user` re-derives grants for display)
- Code verified 2026-08-13: `src/DTO/User.php:53`, `abeon-sdk-ts/src/react/use-auth.ts:42`,
  `schemas/auth/jwt-user.json:18-24`, `abeon-auth-stub/config/dev-users.php`,
  `abeon-auth-stub/app/Http/Controllers/StubAppsController.php`
- Catalog source: `src/Auth/PermissionsDeclarator.php` (`service.permissions.declared`)
- Plan: `abeon-auth-plan.md` §A4, §D1
