# ADR-0016: Multi-tenant organisations

**Status:** Accepted
**Date:** 2026-08-12
**Supersedes:** ADR-0012 (single-tenant per instance)

## Context

ADR-0012 (Accepted, 2026-06-23) declared the platform single-tenant: one running instance == one
organisation, `org_id` informational only, no tenant switcher, no per-request tenant scoping. That
decision is now reversed. The reversal deserves its provenance, because the record reads as if
multi-tenancy is a new idea introduced by a concept meeting. It is not — it is the **original** model,
and the single-tenant reading was the departure.

**The MVP was explicitly multi-tenant.** `abeon-shell-unified`'s first migration opens with:

```sql
-- Tabela tenantów (widoki klientów)
CREATE TABLE public.tenants  ( id UUID PRIMARY KEY, name TEXT, slug TEXT UNIQUE, logo_url TEXT, … );
CREATE TABLE public.tenant_apps  ( tenant_id → tenants, app_id → apps,      UNIQUE(tenant_id, app_id) );
CREATE TABLE public.tenant_users ( tenant_id → tenants, user_id → profiles, is_active BOOLEAN );
```

*Tenant = a client's workspace.* The MVP's own shell spec states the rule directly: *"Tenant = a
workspace ('widok'); a user belongs to ≥1 tenants via `tenant_users`. Available apps =
`tenant_apps(currentTenant)` intersected with the user's role permissions."* Its App Store "install"
wrote a `tenant_apps` row.

**The architecture document then contradicted itself.** §1.1 declares *"Single-tenant — jedna instancja
= jedna organizacja"*. But §5B (Marketplace), in the same document, describes *"aktywnych modułów **per
klient/organizacja**"*, *"Status per klient"* and *"jaki plan/pakiet klient posiada"*, with **Auth
Service as source of truth** for active modules per client. A per-client module catalog with plans has
no meaning on a single-org deployment. §16 decision 5 (*"Greenfield — bez migracji z istniejącego
systemu"*) additionally rules out migrating the existing client base, which is now the point of the
platform.

**And the carrier never left.** ADR-0001 has always described the claim as *"`org_id` — Integer
organization ID for **multi-org accounts**"*, and it is wired end to end today: both JWT schemas,
`JwtValidator`, both `User` DTOs, the SSR auth context, the contract fixtures — plus
`BroadcastingAuthController`, which already enforces `private-org.{id}` channel equality. ADR-0012 did
not remove the dimension; it declared it non-authorizing.

The business need is explicit: existing clients migrate onto the platform, each is provisioned with
instances of apps (CMS, CRM, mail), and each client's users and roles are their own — one business
suite, many clients.

## Decision

**One deployment serves many client organisations.**

1. **`org_id` is an authorization and data-scoping dimension.** This is the direct reversal of
   ADR-0012. Every domain table carries a tenant key; every query is scoped (ADR-0018).
2. **A user belongs to one or more organisations.** Membership is a relation (the MVP's
   `tenant_users`), owned by Auth. Roles and permissions are held **per membership**, not globally —
   a user may be an admin in one organisation and a read-only user in another.
3. **Each organisation holds its own set of apps** (the MVP's `tenant_apps`). This replaces ADR-0015's
   instance-global `enabled` flag; see the amendment there.
4. **`org_id` is the wire name; "tenant" is the domain word.** They mean the same thing. The claim is
   not renamed to `tenant_id`: it already appears in both JWT schemas, both DTO schemas, both SDKs, the
   golden contract fixtures, the test suites and a live broadcasting channel guard. Renaming buys
   nothing and touches everything. Prose says "tenant"; the wire says `org_id`.
5. **`org_id` becomes required on user tokens** — see the ADR-0001 amendment. An optional
   authorization dimension is not coherent.
6. **Switching organisations re-issues the token** — see ADR-0017.
7. **The SDK owns scope enforcement** — see ADR-0018.

### What this does not decide

- **A cross-organisation vendor back-office** (which clients, which plans, which apps) is *not*
  specified here. The architecture doc's §5B Marketplace implies one. The constraint this ADR imposes
  is only that the control plane must remain *able* to answer such questions later without a data-model
  migration — do not bake "exactly one organisation per request" into the registry's own storage.
- **Billing and plans** remain out of scope, as ADR-0015 already recorded.

## Consequences

**Positive:**
- Matches how the business actually works: one platform, many client organisations, apps provisioned
  per client. The MVP's model, restored.
- The claim that carries it already exists end to end, so this is a re-activation rather than a
  from-scratch retrofit.
- Taken while **zero domain tables exist**, which is the cheapest this decision will ever be. ADR-0012
  warned in its own Consequences that reversal is *"a cross-cutting migration, not an additive
  feature"* — that warning is correct and is precisely why this is being decided now rather than later.

**Negative / accepted:**
- Every domain table gains a tenant key and every query gains a scope. The failure mode of getting it
  wrong is cross-client data disclosure — the worst bug class this platform can have. ADR-0018 exists
  to make the safe path the default one, and is explicit about where it does not reach.
- Three contract changes fall out: the event envelope must carry the tenant (ADR-0002), the service JWT
  must be able to act on behalf of one (ADR-0005), and `org_id` becomes required (ADR-0001).
- Documents that now read false and need correcting: architecture doc §1.1 and §16 decision 5,
  `abeon-phase0-summary.md` A6. ADR-0012 is superseded rather than edited, so its reasoning survives
  for anyone who later asks why the platform went single-tenant for three months.
- Per-membership roles mean a user's effective permissions change on tenant switch — which is the
  reason ADR-0017 re-issues the token rather than trusting a client-side selection.

## References

- Supersedes: ADR-0012 (single-tenant per instance)
- Depends on: ADR-0017 (tenant switching), ADR-0018 (tenant scoping)
- Amends downstream: ADR-0001 (`org_id` required), ADR-0002 (envelope tenant), ADR-0005 (service JWT),
  ADR-0009 (per-tenant preferences), ADR-0010 (`tenant_apps` ∩ permissions), ADR-0015 (per-tenant install)
- Provenance: `abeon-shell-unified/supabase/migrations/20251210133745_*.sql`, `unified-shell-spec.md`
  (FR-1, FR-2, FR-9), `abeon-unified-architecture.md` §1.1 / §5B / §16
- Status account: `abeon-concept-status-2026-08-12.md` §3 and §4/D1
