# ADR-0015: App store + org-level app enablement ("add to plan")

**Status:** Accepted
**Date:** 2026-06-25 (accepted 2026-06-26)

> Contract + dev stub + boilerplate Store page implemented. The **production** store endpoints belong
> to the real Auth/registry service (not in this repo) — here they are served by `abeon-auth-stub`.
> Execution detail: `abeon-app-store-plan.md` (repo root).

## Context

The MVP shell had an App Store (catalog → "install"/license an app into your plan). The new platform
has no equivalent: apps self-register (ADR-0010) and `GET /api/v1/auth/apps` filters the registry by
the user's **permissions only** — there is no notion of an app being **enabled/licensed for the org**,
and no surface to add one. The platform is single-tenant per instance (ADR-0012), so "add to plan"
means enabling at the **org/instance** level (the MVP's per-tenant `tenant_apps` does not apply).

## Decision

1. **Org-level enablement.** The app registry gains a per-app **`enabled`** state (org owns it).
   Surfaced on the wire by adding a nullable **`enabled: bool | null`** to `AppDescriptor` —
   populated by Auth on catalog/`/apps` responses, `null`/ignored on self-registration.
2. **`/apps` visibility = org-enabled AND user-permission** (tightens ADR-0010, which was
   permission-only). ADR-0010 is amended accordingly.

> **Amended 2026-08-12 (ADR-0016): enablement is per organisation, and it is a row not a flag.**
> Under the superseded ADR-0012 the platform was single-tenant, so "org-level" and "instance-global"
> were the same thing and `enabled` could be one boolean on the registry. With many organisations per
> deployment they are no longer the same: enablement is the **presence of a `tenant_apps` row** for
> `(org_id, app)` — which is where the React MVP had it, and what its App Store "install" wrote (FR-9).
>
> **The wire contract does not change.** `AppDescriptor.enabled` stays a nullable boolean; it is now
> resolved *for the caller's organisation* when the catalogue is served. The store endpoints
> (`GET /api/v1/auth/store`, `POST /api/v1/auth/store/{app}/{enable|disable}`) act on the organisation
> in the caller's token — so "enable" means **assign the app to this organisation**, and admin scope is
> per organisation, not platform-wide.
>
> Ownership moves with the registry to **AbeonUnified** (ADR-0019); paths are unchanged and Auth
> proxies (ADR-0010 as amended).
3. **Admin store API** (owned by Auth; the SDK provides the contract, the dev `abeon-auth-stub`
   provides a reference stub):
   - `GET /api/v1/auth/store` — admin: the **full** registered catalog + each app's `enabled` state.
   - `POST /api/v1/auth/store/{app}/enable` / `.../disable` — admin: flip org enablement.
4. **Permission-granting to roles** remains Auth's role-management concern (an enabled app is still
   only *visible* to users who hold a permission under its prefix). Referenced, not specified here.
5. **Billing/licensing is explicitly out of scope** — no plan/seat/price model now. If introduced
   later, it attaches as the gate on the `enable` action; this ADR deliberately leaves that unspecified.

## Consequences

- Adds a clean, demoable "add to plan" flow: enable a registered-but-disabled app → it appears in the
  AppSwitcher for entitled users immediately (FR-9 behavior).
- One additive, nullable contract field (`AppDescriptor.enabled`) — no breaking change; mirrors the
  Tier-A chrome fields.
- Production needs the real Auth/registry service to persist enablement and own the store endpoints;
  this repo ships only the contract + a cache-backed dev stub + a boilerplate Store page.

## References

- ADR-0010 (apps endpoint + filtering rule — amended), ~~ADR-0012 (single-tenant per instance)~~ →
  superseded by ADR-0016
- **Amended 2026-08-12 by ADR-0016 and ADR-0019**: enablement is a `tenant_apps` row per organisation
  rather than an instance-global flag; the store acts on the caller's organisation; ownership moves to
  AbeonUnified. The wire shape (`AppDescriptor.enabled`) is unchanged.
- Gap-analysis C10; MVP spec FR-9 (app store install flow)
- Execution detail: `abeon-app-store-plan.md`
