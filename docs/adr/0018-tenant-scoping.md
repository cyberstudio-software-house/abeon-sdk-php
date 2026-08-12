# ADR-0018: Tenant scoping is owned by the SDK

**Status:** Accepted
**Date:** 2026-08-12

## Context

ADR-0016 makes `org_id` a data-scoping dimension. Every domain table across 16+ services will carry a
tenant key, and every read and write must be filtered by it. The failure mode of getting this wrong is
one client reading another client's data — the worst defect this platform can produce, and one that is
invisible until it is catastrophic.

Someone has to own the mechanism. Three options:

1. **The SDK owns it** — a trait or base model plus a global query scope, shipped in `abeon/sdk`.
2. **Each service owns it** — the SDK exposes the tenant, services write their own `where` clauses.
3. **The database owns it** — row-level security.

This is being decided now because **zero domain tables exist**. Every week it stays open, the retrofit
grows.

## Decision

**The SDK owns tenant scoping, and the absence of a tenant is an error rather than a wildcard.**

### The mechanism

- A **trait / base model** marks a model as tenant-scoped. It applies a **global query scope** filtering
  on the tenant key, and populates that key on create from the current tenant context.
- **`withoutTenantScope()`** is the only sanctioned way to read across tenants. It is explicit and
  greppable by design: auditing cross-tenant access is a search, not a code review.
- **A tenant-scoped model used with no tenant in context throws.** It must never fall back to
  "unfiltered" — a query that silently returns every organisation's rows is exactly the bug this ADR
  exists to prevent.

**This last rule matters more than the mechanism.** Any scoping implementation can be bypassed; what
makes a system safe is that the unsafe state is loud. Failing closed converts "we usually remember" into
"we find out immediately, in development, on the first request".

### Where the tenant comes from

| Context | Source |
|---|---|
| HTTP request | `AuthContext` — gains `orgId()` / `requireOrgId()` accessors |
| Event consumer | **The envelope.** `AuthContext` is request-scoped and `EventConsumer` deliberately does not populate it (documented in `AuthContext`'s class PHPDoc), so a handler has no auth context at all. The tenant must be carried on the envelope and pushed into scope explicitly by the consumer. |
| Queued job | Captured at dispatch, restored on handle — same as `CorrelationContext` |
| Console command | None. Must opt in explicitly per invocation. |

**This makes the ADR-0002 amendment a hard prerequisite**, not a parallel task. Until the envelope
carries a tenant, the asynchronous half of the platform cannot be scoped at all.

## Consequences

**Positive:**
- The safe path is the default path. A developer writing an ordinary Eloquent query on a tenant-scoped
  model gets correct behaviour without thinking about it.
- Decided once, inherited sixteen times. "Is this service scoped correctly?" becomes a question about
  the SDK plus a grep for the escape hatch, rather than a full read of every service.

**Negative / accepted — where this does not reach:**
- **Raw access bypasses it entirely.** `DB::table()`, raw SQL and query-builder joins do not run through
  an Eloquent global scope. Reviews and static analysis must treat raw database access in a service as
  something requiring justification.
- **Quiet saves bypass the stamp.** `saveQuietly()` and `Model::withoutEvents()` suppress the `creating`
  hook, so the row inserts unstamped. This cannot be closed from inside the trait, so the **tenant column
  MUST be declared `NOT NULL`** — the database is the backstop for this one hole, turning a silent
  unscoped row into a failed insert. (Found while building the component, and covered by a test that
  asserts the constraint fires.)
- **Consumers and queued jobs have no ambient context**, per the table above. This is handled, but by
  explicit plumbing rather than by the global scope.
- **Migrations, seeders and console commands run tenant-less** by nature.
- **Object storage is not covered at all.** ADR-0021 puts every organisation's files in one container
  shared by all services; that boundary is a path prefix enforced by the SDK's `Storage` component, not
  by this scope.

**Rejected options:**
- **Per-service scoping.** Sixteen teams writing their own `where('org_id', …)` is sixteen chances to
  omit one, with cross-client disclosure as the failure mode, and no central place to verify.
- **Database-level (row-level security).** MariaDB has no native RLS — that is PostgreSQL. Achieving it
  would mean per-tenant views or session variables plus a connection discipline every service must
  honour, and the guarantee still breaks the moment a migration or an admin console connects
  differently. Real operational weight for a leaky guarantee.

## References

- Depends on: ADR-0016 (multi-tenant organisations), **ADR-0002 (envelope tenant field — prerequisite
  for the consumer path)**
- Related: ADR-0003 (`CorrelationContext` — the precedent for propagating request state into jobs and
  consumers), ADR-0021 (object storage, which this scope does *not* cover)
- Implementation (shipped 2026-08-12): `Abeon\SDK\Auth\AuthContext` (`orgId()`, `requireOrgId()`) and
  `Abeon\SDK\Tenancy\` — `TenantContext` (current organisation; falls back to `AuthContext` in HTTP,
  entered explicitly via `runFor()` in consumers, jobs and commands), `TenantScope` (global scope,
  fail-closed), `BelongsToTenant` (scope + stamp on create + `withoutTenantScope()`)
- Implementation delta: `abeon-sdk-delta-2026-08-12.md` items 5–6
