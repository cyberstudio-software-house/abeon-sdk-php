# Architecture Decision Records

The contract of record for the Abeon platform. Where an ADR and the code disagree, the ADR is the
intent and the code is the bug — except where an ADR is marked **Superseded**, in which case follow the
arrow.

**Reading order for someone new:** ADR-0016 (tenancy — it shapes everything else), then ADR-0001 (JWT),
ADR-0004 (REST shape), ADR-0002 (events). The rest are reachable from those.

## Index

| # | Title | Status | What it decides |
|---|---|---|---|
| [0001](0001-jwt-format.md) | JWT format | Accepted · *amended by 0016, 0023, 0024, 0025* | User and service token claims, RS256, lifetimes, cookie names, 60 s clock skew |
| [0002](0002-event-envelope.md) | Event envelope (RabbitMQ) | Accepted · *amended by 0016* | Envelope shape, routing-key grammar, outbox publish, idempotent consume |
| [0003](0003-correlation-id.md) | Correlation ID | Accepted | End-to-end correlation propagation across HTTP, events and logs |
| [0004](0004-rest-envelope-and-errors.md) | REST envelope and errors | Accepted | Response envelope, RFC 7807 problem details |
| [0005](0005-service-to-service-auth.md) | Service-to-service auth | Accepted · *amended by 0016, 0025* | Per-service RS256 keys, short-lived service tokens, JWKS validation, rotation grace |
| [0006](0006-notifications-contract.md) | Notifications contract | **Superseded by [0019](0019-abeon-unified-service.md)** | *The contract itself is still current* — only the owning service changed |
| [0007](0007-search-and-command-registry.md) | Search and command registry | Accepted | Cmd+K command registry, per-service search providers |
| [0008](0008-broadcasting-auth.md) | Broadcasting auth | Accepted | Reverb channel authorisation, `private-org.{id}` guard |
| [0009](0009-user-preferences.md) | User preferences | Accepted · *amended by 0016* | `/auth/me/preferences`, versioned blob, per-user-per-organisation |
| [0010](0010-auth-me-and-apps-endpoints.md) | `/auth/user` + `/auth/apps` | Accepted · *amended by 0015, 0016, 0019, 0025, 0026* | Chrome data plane; app visibility = `tenant_apps` ∩ permissions; the token gates the UI |
| [0011](0011-cross-app-search-service.md) | Cross-app search service | Accepted (Phase 2) | Federated search service; contract now, service later |
| [0012](0012-single-tenant-per-instance.md) | Single-tenant per instance | **Superseded by [0016](0016-multi-tenant-organisations.md)** | Reversed. Its "retrofit cost" paragraph is worth reading anyway |
| [0013](0013-full-page-navigation.md) | Full-page navigation | Accepted | Cross-app navigation is a full page load, not client-side routing |
| [0014](0014-suite-dashboard-composition.md) | Suite dashboard composition | Accepted (Phase 2) | How the suite home page composes per-app widgets |
| [0015](0015-app-store-and-entitlement.md) | App store and entitlement | Accepted · *amended by 0016, 0019* | `AppDescriptor.enabled`, store API; enablement is a `tenant_apps` row |
| [0016](0016-multi-tenant-organisations.md) | **Multi-tenant organisations** | Accepted · *amended by 0022, 0024* | One deployment, many client organisations. `org_id` is an authorization dimension |
| [0017](0017-tenant-switching.md) | Tenant switching | Accepted | Switching organisation re-issues the token; clients never assert their own tenant |
| [0018](0018-tenant-scoping.md) | Tenant scoping | Accepted · *amended by 0022* | The SDK owns row scoping; a missing tenant throws rather than returning everything |
| [0019](0019-abeon-unified-service.md) | AbeonUnified service | Accepted · *amended by 0022* | `abeon-notifications` renamed and widened; the outbound-integration doctrine |
| [0020](0020-ai-gateway.md) | AI gateway | Accepted | OpenRouter only via Unified, platform key, per-organisation metering |
| [0021](0021-object-storage-layout.md) | Object storage layout | Accepted | One OCS container per organisation, `{service}/` prefixes |
| [0022](0022-organisation-provisioning.md) | Organisation provisioning | Accepted · *amended 2026-08-13* | `unified.app.registered` → Auth creates the organisation + owner → `auth.org.created` carries the id back; user creation returns the invitation link |
| [0023](0023-token-revocation-and-sessions.md) | Token revocation and sessions | Accepted | Refresh rotation, reuse kills the family, a stated ≤ 15-min revocation window |
| [0024](0024-permission-expansion.md) | Permission expansion | Accepted | No wildcards on the wire; Auth expands roles at issue time; 4 KB token budget |
| [0025](0025-auth-service-invariants.md) | Auth service invariants | Accepted | Six edge behaviours: no-organisation refusal, default org, over-budget refusal, JWKS degradation, audit immutability, rate-limit precedence |
| [0026](0026-administration-is-an-sdk-surface.md) | Administration surface | Accepted | Auth ships no frontend: API + hooks in the packages, screens in the boilerplate |
| [0027](0027-pre-authentication-screens.md) | Pre-authentication screens | Accepted | Login, reset and invitation acceptance live in one dedicated app; the return-to parameter is allowlisted |

## Supersessions

```
0012  single-tenant per instance   ──►  0016  multi-tenant organisations
0006  notifications contract       ──►  0019  AbeonUnified service   (contract carried forward verbatim)
```

## The 2026-08-12 tenancy set

ADR-0016 to ADR-0021 landed together and are best read as one change. ADR-0016 reverses the
single-tenant decision; 0017 and 0018 make it operable; 0019 gives the platform tier an owner; 0020 and
0021 attach the external services. Six existing ADRs were amended in place to match — 0001, 0002, 0005,
0009, 0010, 0015 — each carrying a note in its `## References`.

Two of those amendments are load-bearing and easy to miss:

- **ADR-0002** gained a top-level `org_id` on the envelope. Event consumers have no request context and
  no `AuthContext`, so the envelope is their only possible source of tenant — ADR-0018 cannot reach any
  handler without it.
- **ADR-0001** made `org_id` **required** on user tokens. It was previously optional and informational.

Background and provenance: `abeon-concept-status-2026-08-12.md` (where the platform stood and why),
`abeon-sdk-delta-2026-08-12.md` (what still has to be built), and
`abeon-adr-reconciliation-plan-2026-08-12.md` (the plan this set executed).

## The 2026-08-13 Auth set

ADR-0022 to ADR-0024 close the three questions that stood between the frozen Auth contract and an Auth
service somebody could actually build. They come out of `abeon-auth-plan.md` (Proposed), which holds the
full capability spec; only the decisions that could not be left to the implementation became ADRs.

- **0022** — how an organisation and its first user come into existence. The concept graph's onboarding
  flow finally has an owner: Unified registers, Auth provisions, `auth.org.created` closes the loop.
- **0023** — what a session *is*. Rotation, reuse detection, and an honest number for how long a revoked
  session keeps working.
- **0024** — the one that was already broken. `*.*.*` satisfies neither the JWT schema nor either SDK's
  `hasPermission()`; the real Auth expands roles instead.

## The 2026-08-13 Auth decision set

ADR-0025 and ADR-0026 close what the plan had left open, together with amendments to 0001, 0005, 0010
and 0022. Read them as one pass over `abeon-auth-spec.md`'s findings:

- **0025** collects six edge behaviours that had no home — including two defects the spec found in
  shipped code: clock-skew tolerance was 0 across the platform (`JWT::$leeway` never set), and the
  JWKS rotation grace period in ADR-0005 was derived from the token lifetime when the binding quantity
  is the consumer's cache TTL. Both are corrected in the ADRs they belong to.
- **0026** settles the administration surface: Auth ships no frontend at all. API and hooks in the
  packages, screens in the boilerplate — the split the App Store already uses.
- **0022** grew a general rule: user creation returns the invitation link to its caller, so accounts can
  be created before Unified has an email channel, without giving Auth a mail credential.

One correction runs the other way. ADR-0010's 2026-08-12 amendment claimed `GET /api/v1/auth/user` gains
the caller's memberships; **ADR-0017 decided the opposite the same day** (a separate `/auth/tenants`
endpoint, because the `User` DTO is contract-frozen), and the code follows ADR-0017. The claim is struck
in place with its provenance.

Still open, deliberately: `tenant_apps` before Unified exists (a build-time detail), the impersonation
`act` claim's implementation (its shape is fixed as RFC 8693 in ADR-0001), and GDPR erasure semantics.

**ADR-0027 was added the same day, but by a different route.** The first three came from reading the plan;
this one came from *running* the platform — pointing the boilerplate at the real Auth and finding that the
redirect to a login screen led to a 404, because ADR-0026 had given Auth no frontend and nobody had named
an owner for the one screen every user meets first.

## Conventions

- **Amend in place** for changes that refine a decision; add a dated note to `## References` naming the
  amending ADR. The reader of an ADR must see the current rule in the body, not a trail of appendices.
- **Supersede with a new ADR** for reversals. Never edit the original's reasoning — the record of why a
  decision looked right at the time is the most useful thing in it. Set its status to
  `Superseded by ADR-00NN` and add a short banner explaining what changed.
- **Sections:** `Context` · `Decision` · `Consequences` · `References`. Consequences must include the
  negative ones and the rejected options, with reasons.
- Update this index in the same change. An index that lags is worse than none.
