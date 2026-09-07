# ADR-0022: Organisation provisioning and the registration round trip

**Status:** Accepted
**Date:** 2026-08-13

## Context

ADR-0016 makes a user's membership of an organisation the platform's authorization dimension, and
ADR-0019 splits ownership: Auth owns *users, memberships, roles and permissions*; AbeonUnified owns
*apps and their assignment* (`tenant_apps`). Neither ADR says how an organisation comes into existence
in the first place, nor who creates the first user inside it.

The concept-meeting graph does describe it, in two side notes:

> **1. Rejestracja aplikacji** → 1. Nadanie ID · 2. Rozgłoszenie rejestracji (`id`, `owner_email`)
> **2. Jeśli nowy user — nadanie dostępów** → 1. Odebranie eventu o rejestracji · 2. stworzenie
> użytkownika-ownera tenanta · 3. wysłanie danych dostępowych
> **2'. Jeśli istnieje — przypisanie nowej aplikacji** → do tenanta zostaje dopisana kolejna aplikacja

And the contract for the first half already exists: `schemas/events/unified.app.registered.json` specifies
`unified.app.registered` with `{ id, name, owner_email, org_id, metadata }` and states in its own
description that Auth consumes it *"to provision the owner user for a new organisation, or to attach the
application to an existing one"*.

**Nothing implements it.** Verified 2026-08-13: `grep -rn "app\.registered\|AppRegistered"` across
`abeon-sdk-php/src`, `abeon-sdk-ts/src`, `abeon-auth-stub/app` and `abeon-boilerplate-inertia/app`
returns **zero hits**. The schema is a contract with a publisher that does not exist yet and a consumer
nobody has written.

Three things are genuinely undecided, and all three bite at the same moment:

1. **Who creates the organisation row?** Unified holds `tenant_apps`, keyed on `org_id` — but a
   registration with `org_id: null` means the organisation does not exist yet.
2. **How does Unified learn the `org_id` it must key on?** If Auth creates the organisation, the
   identifier is born on the far side of the seam from the service that needs it.
3. **What tenant does the handler run under?** The envelope's `org_id` is `null` for this event, by
   design — registration is platform-level work that happens before an organisation context exists. But
   ADR-0018 makes a missing tenant an error, never a wildcard.

## Decision

### 1. Auth creates the organisation

Auth is the source of truth for the organisation row (id, name, slug, logo, status), consistent with it
owning memberships: an organisation with no users is not a thing the platform can do anything with, and
splitting "the organisation exists" from "who belongs to it" across two services buys nothing.

Unified keeps whatever projection it needs to satisfy foreign keys on `tenant_apps` — a mirror, not a
second source of truth.

### 2. Provisioning is a service class, not event-handler code

```
ProvisionOrganisation(ownerEmail, appName, ?orgId): ProvisioningResult
```

The event handler is a thin adapter over it, and a console command
(`abeon:org:provision`) is a second adapter over the same class.

This is not gold-plating. Per the architecture doc §14 slice order, Auth is *"HTTP-done in Slice 1 and
events-done only in Slice 4"*, and AbeonUnified — the publisher — comes after Auth entirely. A design
that puts provisioning logic inside a RabbitMQ handler cannot onboard anybody until two later services
exist. The console command is how the platform onboards its first organisations.

### 3. The round trip: `auth.org.created` carries the registration id back

When provisioning creates a new organisation, Auth publishes:

```json
{
  "org_id": 42,
  "name": "Acme Sp. z o.o.",
  "slug": "acme",
  "owner_user_id": "1",
  "registration_id": "01J8Z…"
}
```

`registration_id` echoes `unified.app.registered.id`. Unified correlates on it and writes its
`tenant_apps` row for `(42, crm)`. Envelope `org_id` is the new organisation (this event, unlike the
registration that triggered it, does have a tenant).

Per ADR-0002 domain event schemas are federated into their owning service's repository, so
`org-created.json` ships with `abeon-auth` when that repository exists. It is specified here rather
than in the SDK because — unlike `unified.app.registered.json`, which had to live somewhere to be a contract at
all — this one has a named home in the same slice that first emits it.

**Rejected: Auth calls Unified synchronously** with the new `org_id`. It makes the dependency a cycle
(Unified → Auth → Unified), fails provisioning outright when Unified is down, and puts a network call
in the middle of a transaction that has already created a user. The event is asynchronous, retryable
and already the platform's idiom.

### 4. Idempotency is keyed on the registration

`organisations.source_registration_id` is unique. A replayed `unified.app.registered` finds the existing
row and does nothing — in addition to the SDK's `abeon_processed_events` guard, because the two protect
different things: the processed-events table protects against redelivery of *this* message, and the
unique key protects against a second registration for the same onboarding.

An `owner_email` that already belongs to a user **links** — add a membership, do not create a second
user row. A person who owns two client organisations is one person.

### 5. The platform-scope exception to ADR-0018

The handler starts with no tenant, legitimately. ADR-0018's fail-closed rule stands everywhere else;
this handler is the documented exception, and it discharges the obligation by entering a tenant scope
as soon as it has one:

```php
$orgId = $this->provisioner->resolveOrCreate($payload);
$this->tenants->runFor($orgId, fn () => /* memberships, roles, preferences */);
```

Written down because the alternative readings are both wrong: a handler that calls
`TenantContext::require()` at entry throws on every registration, and one that quietly runs unscoped is
the cross-organisation disclosure bug ADR-0018 exists to prevent.

### 6. Credential delivery goes through Unified, and is therefore later

The owner is notified by an **invitation link**, never a generated plaintext password.

Delivery is Unified's job — ADR-0019's doctrine is that shared external infrastructure (here, Mailgun)
sits behind a service, and its `## Decision` §1 already records the open gap that the implemented
notification contract is *in-app + WebSocket only, with no channel selection*. So:

- **Until Unified has an email channel**, `abeon:org:provision` prints the invitation link and it is
  delivered out of band. Onboarding works; it is simply not self-service.
- **Auth does not get its own Mailgun credentials** to route around this. That is precisely the
  blast-radius argument ADR-0019 makes, and Auth is the last service that should hold a credential it
  does not need.

**Generalised 2026-08-13 — the link is returned, not only printed.** The same mechanism covers an
organisation admin creating an account for a new user, which is required from the first release even
though bulk import of an existing user base is not:

- **User creation returns the one-time invitation link to its caller** — the console command prints it,
  the admin endpoint returns it in the response body — instead of depending on delivery. The admin
  passes it on by whatever channel they already use.
- The link is single-use, stored hashed, and expires. **The user sets their own password**; no plaintext
  password is generated, transmitted or logged, on any path.
- When Unified gains an email channel, the same endpoint additionally sends the link. **The contract does
  not change** — delivery becomes an added behaviour, not a new response shape, so nothing that consumes
  it today has to be revisited.

This keeps account creation working from Slice 1 without giving Auth a mail credential and without
reordering the platform so that Unified ships first.

## Consequences

**Positive:**

- The onboarding flow in the concept graph gets an owner and a written contract, instead of living in a
  schema description that nothing reads.
- Provisioning works from the console in Slice 1, before RabbitMQ and before Unified — the platform can
  onboard its first client without the whole event fabric standing up first.
- The correlation id makes the seam auditable: for any organisation, the registration that created it
  is a column, not an inference.

**Negative / accepted:**

- **Two services must agree on an eventual-consistency window.** Between `auth.org.created` and
  Unified's projection, the organisation exists with no apps assigned, so `GET /api/v1/auth/apps`
  returns an empty list. The chrome must render that state rather than treat it as an error — an
  organisation whose apps have not landed yet looks exactly like one that has been assigned none.
- **Self-service onboarding is blocked on Unified's email channel** (§6). This is the sequencing risk
  named in `abeon-auth-plan.md` Decision 3, and it is recorded rather than solved.
- **Auth gains a write path that no user request drives.** Provisioning is invoked by a consumer and a
  console command, so its authorization story is "trust the broker and the operator" rather than a
  permission check. That is acceptable for platform-scope work and is why §5 is explicit.
- Rejected alongside the synchronous call: **letting Unified own the organisation row** and Auth mirror
  it. Membership is the authorization dimension and lives in Auth; putting the organisation's identity
  in the service that does not know who belongs to it inverts the dependency ADR-0016 sets up.

## References

- Depends on: [ADR-0016](0016-multi-tenant-organisations.md) (organisations, memberships),
  [ADR-0019](0019-abeon-unified-service.md) (registry and `tenant_apps` ownership),
  [ADR-0002](0002-event-envelope.md) (envelope, outbox, idempotent consume)
- Exception recorded against: [ADR-0018](0018-tenant-scoping.md) — *Decision → "Where the tenant comes from"*
- Contract consumed: `schemas/events/unified.app.registered.json` (`unified.app.registered`)
- Contract introduced: `auth.org.created` — schema ships with `abeon-auth` per ADR-0002 federation
- Plan: `abeon-auth-plan.md` §A0 (capability), §D5–D6 (the decisions this closes)
- **Amended 2026-08-13:** §6 generalised from "the provisioning command prints the link" to "any user
  creation returns the link to its caller", so an organisation admin can create accounts before Unified
  has an email channel. See `abeon-auth-spec.md` FR-16 and finding A-10.
