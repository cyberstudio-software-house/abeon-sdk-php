# ADR-0012: Single-tenant per instance

**Status:** Accepted
**Date:** 2026-06-23

## Context

The React MVP ("unified shell") was **multi-tenant**: a user could belong to several tenants, a
tenant switcher lived in the top bar, and switching re-derived the set of available apps from
`tenant_users` / `tenant_apps`. The new platform takes a different stance — the architecture doc
states each instance serves **one organisation** ("jedna instancja = jedna organizacja").

This needs to be a recorded decision, because the assumption is load-bearing: it propagates into the
JWT shape (ADR-0001), the app registry (ADR-0010), preferences (ADR-0009), and any future
row-level-security model. Reversing it later is expensive.

## Decision

**Each deployment is single-tenant: one running instance == one organisation.** There is no tenant
switcher and no per-request tenant scoping.

- The `org_id` claim in the user JWT (ADR-0001) is **informational** (auditing, display, cross-org
  analytics later) — it is **not** an authorization or data-scoping dimension within an instance.
  Authorization is by `{app}.{resource}.{action}` permissions only.
- The app registry (`/api/v1/auth/apps`, ADR-0010) is the instance's catalog filtered by the user's
  permissions — not by tenant membership.
- Multiple organisations are served by **multiple instances** (separate deployments / namespaces),
  not by one instance partitioning data.

## Consequences

- **Simpler everything:** no tenant column on every table, no tenant-scoped cache keys, no tenant
  switcher UI, no cross-tenant leakage class of bugs. The MVP's tenant-switch capability (C8 in the
  gap analysis) is intentionally **not** reproduced.
- **Retrofit cost (if multi-tenant is ever required):** a tenant claim would need to be added to the
  JWT and validated everywhere; every domain table would need a tenant key + row-level security; the
  registry and preferences would need tenant scoping; the chrome would need a switcher that
  re-derives apps and resets `currentApp` on change. This is a cross-cutting migration, not an
  additive feature — hence recording the decision now.

## References

- ADR-0001 (JWT format — `org_id` claim), ADR-0010 (apps endpoint), ADR-0009 (preferences)
- Supersedes the MVP's multi-tenant model for the new platform.
