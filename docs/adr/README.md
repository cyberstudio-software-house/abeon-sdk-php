# Architecture Decision Records

The contract of record for the Abeon platform. Where an ADR and the code disagree, the ADR is the
intent and the code is the bug — except where an ADR is marked **Superseded**, in which case follow the
arrow.

**Reading order for someone new:** ADR-0016 (tenancy — it shapes everything else), then ADR-0001 (JWT),
ADR-0004 (REST shape), ADR-0002 (events). The rest are reachable from those.

## Index

| # | Title | Status | What it decides |
|---|---|---|---|
| [0001](0001-jwt-format.md) | JWT format | Accepted · *amended by 0016* | User and service token claims, RS256, lifetimes, cookie names |
| [0002](0002-event-envelope.md) | Event envelope (RabbitMQ) | Accepted · *amended by 0016* | Envelope shape, routing-key grammar, outbox publish, idempotent consume |
| [0003](0003-correlation-id.md) | Correlation ID | Accepted | End-to-end correlation propagation across HTTP, events and logs |
| [0004](0004-rest-envelope-and-errors.md) | REST envelope and errors | Accepted | Response envelope, RFC 7807 problem details |
| [0005](0005-service-to-service-auth.md) | Service-to-service auth | Accepted · *amended by 0016* | Per-service RS256 keys, short-lived service tokens, JWKS validation |
| [0006](0006-notifications-contract.md) | Notifications contract | **Superseded by [0019](0019-abeon-unified-service.md)** | *The contract itself is still current* — only the owning service changed |
| [0007](0007-search-and-command-registry.md) | Search and command registry | Accepted | Cmd+K command registry, per-service search providers |
| [0008](0008-broadcasting-auth.md) | Broadcasting auth | Accepted | Reverb channel authorisation, `private-org.{id}` guard |
| [0009](0009-user-preferences.md) | User preferences | Accepted · *amended by 0016* | `/auth/me/preferences`, versioned blob, per-user-per-organisation |
| [0010](0010-auth-me-and-apps-endpoints.md) | `/auth/user` + `/auth/apps` | Accepted · *amended by 0015, 0016, 0019* | Chrome data plane; app visibility = `tenant_apps` ∩ permissions |
| [0011](0011-cross-app-search-service.md) | Cross-app search service | Accepted (Phase 2) | Federated search service; contract now, service later |
| [0012](0012-single-tenant-per-instance.md) | Single-tenant per instance | **Superseded by [0016](0016-multi-tenant-organisations.md)** | Reversed. Its "retrofit cost" paragraph is worth reading anyway |
| [0013](0013-full-page-navigation.md) | Full-page navigation | Accepted | Cross-app navigation is a full page load, not client-side routing |
| [0014](0014-suite-dashboard-composition.md) | Suite dashboard composition | Accepted (Phase 2) | How the suite home page composes per-app widgets |
| [0015](0015-app-store-and-entitlement.md) | App store and entitlement | Accepted · *amended by 0016, 0019* | `AppDescriptor.enabled`, store API; enablement is a `tenant_apps` row |
| [0016](0016-multi-tenant-organisations.md) | **Multi-tenant organisations** | Accepted | One deployment, many client organisations. `org_id` is an authorization dimension |
| [0017](0017-tenant-switching.md) | Tenant switching | Accepted | Switching organisation re-issues the token; clients never assert their own tenant |
| [0018](0018-tenant-scoping.md) | Tenant scoping | Accepted | The SDK owns row scoping; a missing tenant throws rather than returning everything |
| [0019](0019-abeon-unified-service.md) | AbeonUnified service | Accepted | `abeon-notifications` renamed and widened; the outbound-integration doctrine |
| [0020](0020-ai-gateway.md) | AI gateway | Accepted | OpenRouter only via Unified, platform key, per-organisation metering |
| [0021](0021-object-storage-layout.md) | Object storage layout | Accepted | One OCS container per organisation, `{service}/` prefixes |

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

## Conventions

- **Amend in place** for changes that refine a decision; add a dated note to `## References` naming the
  amending ADR. The reader of an ADR must see the current rule in the body, not a trail of appendices.
- **Supersede with a new ADR** for reversals. Never edit the original's reasoning — the record of why a
  decision looked right at the time is the most useful thing in it. Set its status to
  `Superseded by ADR-00NN` and add a short banner explaining what changed.
- **Sections:** `Context` · `Decision` · `Consequences` · `References`. Consequences must include the
  negative ones and the rejected options, with reasons.
- Update this index in the same change. An index that lags is worse than none.
