# ADR-0019: AbeonUnified service

**Status:** Accepted
**Date:** 2026-08-12
**Supersedes:** ADR-0006 (notifications contract) — its contract is carried forward unchanged, see below

## Context

ADR-0006 specified a dedicated `abeon-notifications` service owning the unified notification feed. It
was never built; only its contract exists, plus a stub inside the boilerplate.

Meanwhile ADR-0016 (multi-tenancy) and the app-registry work created a set of responsibilities with no
owner: who persists the app registry, who records which organisation has which app, who provisions a
new organisation's resources. ADR-0010 currently has services self-register **to Auth**
(`ServiceRegistry::register()` posts to `service('auth')`), which loads the identity service with
catalogue duties that have nothing to do with identity.

The conclusion from the concept meeting was that notifications alone is too narrow a scope for a
service that must also own registration, organisation↔app assignment and app data. Rather than adding a
third platform service, `abeon-notifications` is **renamed and widened**.

**This is not a consolidation of the microservice model.** The ~16 business applications remain
separate services with their own databases. It is the *platform tier* that is two services: Auth, and
AbeonUnified.

## Decision

**`abeon-notifications` becomes `abeon-unified` (AbeonUnified), owning four responsibilities.**

### 1. Notifications — ADR-0006's contract, carried forward unchanged

Every REST endpoint, the `NotificationDto`, the `*.notification.requested` RabbitMQ fan-in, the
per-user Reverb channel `user.{id}`, and the preference model transfer verbatim. Superseding ADR-0006
must not silently drop a working contract; consider its `## Decision` section incorporated here by
reference, with the service name changed.

**Known gap, inherited and recorded rather than closed:** architecture doc §5A promises email
(Mailgun/SMTP) and push channels, but the implemented contract — `schemas/dto/notification.json` and
`schemas/events/notification-requested.json` — is **in-app + WebSocket only**: there is no channel
selection field and no email-specific payload. Closing it is Unified's work, deferred deliberately, and
it becomes more pressing because credential delivery (ADR-0016 organisation provisioning) needs a mail
path.

### 2. App registry

Unified persists the app catalogue and owns self-registration. The registry endpoint moves off Auth —
see the ADR-0010 amendment for the endpoint paths and the decision on whether Auth proxies.

### 3. Organisation↔app assignment

Which organisation has which app (`tenant_apps`, ADR-0016 §3), and the store API that changes it
(ADR-0015 as amended). Auth still owns *users, memberships, roles and permissions*; Unified owns *apps
and their assignment*.

### 4. App data

Reserved. The concept graph names it; its meaning is **not yet defined** and is deliberately left open
here rather than invented. Whatever it becomes, it determines whether Unified is a control-plane
service only or also a data-plane one.

### Doctrine: Unified fronts the platform's outbound integrations

Stated here because Unified is what holds the credentials.

> **Shared external infrastructure gets a service in front of it; business applications talk events or
> REST.**

The platform already works this way. Architecture doc §5A: *"**Żadna aplikacja biznesowa nie wysyła
powiadomień bezpośrednio** — zamiast tego publikuje event do RabbitMQ, a Notifications Service decyduje
co, komu i jakim kanałem dostarczyć."* §8.5, for ElasticSearch: *"Każdy serwis **nie pisze
bezpośrednio** do ES"* — a dedicated indexer consumes events, so *"serwisy biznesowe nie mają
zależności od ES"*.

Applied to the three external services in the concept graph:

| | Attachment | SDK impact |
|---|---|---|
| **Mailgun** | Behind Unified — a notification channel, never called by an app | None; `EventPublisher` already covers it |
| **OpenRouter** | Behind Unified — see ADR-0020 | A thin client over `ServiceClient` |
| **OCS** | **The deliberate exception** — see ADR-0021 | A new `Storage` component |

The reason the rule holds is credential blast radius: the SDK is a library compiled into every
application, so teaching it to speak a provider's API means that provider's per-organisation
credentials must reach 16 services × N organisations, with rotation and spend limits living in sixteen
places. Bulk object bytes are worth an exception (ADR-0021); control-plane calls are not.

## Consequences

**Positive:**
- One platform service owns the catalogue, the organisation↔app relation and provisioning, instead of
  spreading them across Auth and nothing.
- Auth stays an identity service: users, memberships, roles, permissions, tokens.
- Exactly one component holds third-party credentials, so rotation, quotas and cost attribution have
  one home.

**Negative / accepted:**
- ADR-0010's registry ownership changes, and `ServiceRegistry::register()` currently targets
  `service('auth')` — a one-line change plus configuration, but it is a contract move and must be
  amended explicitly, not assumed.
- Unified becomes a service with four fairly different concerns. If "app data" grows into a data plane,
  splitting it back out is a live option; the contracts are kept split-ready for that reason.
- The name `abeon-notifications` appears in ADR-0006, the architecture doc §5A and the boilerplate's
  stub routes. Those references need updating, and until they are, both names are in circulation.

## References

- Supersedes: ADR-0006 (notifications contract — carried forward verbatim)
- Depends on: ADR-0016 (multi-tenant organisations)
- Amends downstream: ADR-0010 (registry ownership), ADR-0015 (store acts per organisation)
- Elaborated by: ADR-0020 (AI gateway), ADR-0021 (object storage layout)
- Doctrine sources: `abeon-unified-architecture.md` §5A, §8.5; the OCS exception at §8.4
- Status account: `abeon-concept-status-2026-08-12.md` §5
- **Amended 2026-08-13 by [ADR-0022](0022-organisation-provisioning.md)** (organisation provisioning):
  the ownership split is sharpened at the one point where it is ambiguous. Unified owns *apps and their
  assignment*, but **Auth creates the organisation row** — so a registration with `org_id: null` is
  completed by Auth and the resulting identifier travels back on `auth.org.created`, correlated by the
  registration's own `id`. Unified holds a projection, not a second source of truth. ADR-0022 also
  records that the email gap noted in this ADR's Decision §1 blocks self-service onboarding: credential
  delivery waits for Unified's email channel rather than giving Auth its own Mailgun credentials.
