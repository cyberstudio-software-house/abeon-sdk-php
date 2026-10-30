# ADR-0038: Integrations live with their domain, and a client's keys live in a vault in Unified

**Status:** Accepted
**Date:** 2026-10-28
**Amends:** ADR-0019 (the outbound-integration doctrine)

## Context

ADR-0019 stated a doctrine: *"Shared external infrastructure gets a service in front of it; business
applications talk events or REST."* It was applied to Mailgun (e-mail, ADR-0030) and OpenRouter (AI,
ADR-0020), with object storage as the deliberate exception (ADR-0034). Its reason is credential blast
radius: a provider's credentials must not spread to every application and every organisation.

Every provider the doctrine covered so far uses **the platform's** key. The integrations our clients run
today mostly use **the client's** key:

- payments — P24, imoje, Dotpay and Comfino in hafen; PayU and Stripe named by the PRD;
- marketplaces and marketing — Allegro (hafen), Freshmail (telko, hafen), Mailgun per client (PCC);
- documents — Autenti and SignHero e-signature (hr-management), inFakt invoicing (PCC);
- company lookup — GUS by NIP, written four times over (PCC, magnetimarelli, manngo, hr-management),
  but on the platform's account rather than the client's.

Payments are the case that forces the question. In Abeon 4 a shop and its site were one application, so
the payment integration lived in it. The suite splits them — a client may buy the CMS without
E-commerce — and the payment flow belongs to the order: the cart, the order status, the callback that
marks an order paid. Routing that flow through Unified would make Unified a payment service and split one
domain across two services. Keeping each client's keys inside each application's database would give
every application its own settings screen for secrets and repeat the storage and rotation of them.

Three models were compared with the owner on 2026-10-28:

| | 1. All in the application | **2. Logic in the application, keys in Unified** | 3. Gateway in Unified |
|---|---|---|---|
| Talks to the provider | the application | the application | Unified |
| Receives the callback | the application | the application | Unified |
| Where the client enters a key | each application's settings | one Integrations screen | one Integrations screen |
| Where the secret is stored | the instance's database | Unified, encrypted | Unified, encrypted |
| Does the application see the secret | yes | yes, only its own | no |

## Decision

**An integration's logic lives in the application that owns its domain. A client's credentials live in
one encrypted vault in AbeonUnified, entered once, and each application reads only its own.** Model 2.

### 1. Where an integration lives

| Kind | Logic | Key | Examples |
|---|---|---|---|
| Platform provider on the platform's key | Unified, as a gateway | Unified's configuration | e-mail (ADR-0030), AI (ADR-0020), **GUS** |
| Client provider with a domain flow (callbacks, statuses) | the business application | **the vault**, read by that application | **payments** (E-commerce), Allegro, Freshmail, Autenti, SMS |
| Feed or export with no secret | the application | none | Ceneo, Google Merchant |
| Front-end snippet | the CMS site settings | none | GTM, analytics |
| Bulk bytes | signed addresses | Unified | object storage (ADR-0034) |

When a second application needs the same client integration, its code moves into an SDK module — the
rule ADR-0037 applied to files. Payments start in E-commerce; event registrations or Finance would be the
second user.

### 2. The vault

Unified remains one multi-tenant service (ADR-0031 §1), so the vault is one table with a row per client,
not a database per client:

- `integration_secrets`: `org_id`, `service`, `provider`, the secret fields encrypted with a key held
  outside the database, `status`, `last_tested_at`, `updated_by`, timestamps.
- **Writing** — `/api/v1/admin/integrations`, user token, `core.integrations.manage`. The screen ships in
  the template under Admin (ADR-0026). A stored secret is never returned to a browser; the screen shows
  the provider, the status, the last four characters and the last test.
- **Reading** — `GET /api/v1/internal/secrets/{provider}`, service token. **The organisation and the
  service come from the token, never from a parameter**, exactly as `StorageController::callerOf` takes
  them today: E-commerce cannot read the CMS's key, one client cannot read another's, and a token
  without an organisation is refused (ADR-0018).
- **Every read is logged** — organisation, service, provider, time — because a read is the moment a
  secret leaves the vault.
- **The application caches** what it read, in memory or in Redis under its own prefix (ADR-0032), with a
  short TTL. An outage in Unified then does not stop a payment that is already in progress; a cold start
  during an outage does.
- **Rotation** takes effect when the cache expires. "Test connection" on the screen asks the owning
  application, by event, to try the key, and the result lands in `last_tested_at`.

### 3. GUS is a gateway

GUS runs on the platform's account, so it follows ADR-0019 unchanged: `POST /api/v1/gus/lookup` in
Unified, called through `ServiceClient`, with a cache per NIP. It replaces the four copies.

## Consequences

**Positive**

- A client enters each key once, on one screen, whatever applications they have bought.
- Payment flows stay in one domain: the order and its payment are decided in the same service.
- Instance databases hold no client secrets, so an instance backup or a restored copy leaks none.
- Rotation, auditing and "who can read what" have one home, as ADR-0019 intended.

**Negative**

- **This softens ADR-0019.** An application now holds a client's secret in memory. The blast radius is
  limited to one organisation and one service by construction, but it is not zero.
- **One database holds every client's keys.** Mitigation: encryption with a key outside that database,
  no read path to a browser, an audited read path for services, and nothing in the vault on the platform's
  own keys.
- An application cannot start a payment cold while Unified is down.
- The template gains an Admin screen and the SDK a small client with a cache, both before the first
  integration uses them.

**Rejected**

- *Model 1, everything in the application.* Each application would grow its own secrets screen, storage
  and rotation, and a client with three applications would enter keys in three places.
- *Model 3, a payment gateway in Unified.* Unified would own payment state and callbacks for a domain that
  belongs to the order, and every new provider feature would be a change to the platform service.
- *Keys in environment variables per instance.* Changing a key would need a deployment, and the client
  could not manage it.

## References

- Amends ADR-0019 (dated note added there). Builds on ADR-0018 (no organisation, no access), ADR-0026
  (API in the platform, screen in the template), ADR-0031 (Unified is one multi-tenant service), ADR-0032
  (cache), ADR-0037 (rule of two for shared modules).
- Same caller rule as `abeon-unified/app/Http/Controllers/Internal/StorageController.php` (`callerOf`).
- Product documents: `abeon-cms-prd-2026-10-27.md` (payments and products outside the CMS).
- Not implemented on 2026-10-28: the vault, the Integrations screen, the GUS gateway. Unified has no
  `secrets` or `gus` route today.
- **Amended 2026-10-28 by [ADR-0041](0041-outbound-http-to-client-urls.md):** "instance databases hold no
  client secrets" means *third-party credentials*; secrets the platform generates for its own webhooks are
  stored encrypted in the application's database. **Depends on [ADR-0039](0039-instance-service-identity.md):**
  the vault's "each application reads only its own" holds only once tokens are bound to their instance.

## Amendment 2026-10-30: the cache stays in the process

Implemented in AbeonUnified (`integration_secrets`, `/api/v1/admin/integrations`, `/api/v1/internal/secrets/{provider}`)
and in the SDK (`Integrations\Secrets`). The CMS PRD §14 is stricter than §2 above: what an application read is
cached **in its own process memory only**, five minutes, never in Redis. A shared store would keep a secret past its
rotation and expose it to every application sharing the store. A worker that starts during an outage in Unified
therefore cannot use the key until Unified answers; a request already holding it can.

"Test connection" is the application's own call: it tries the key and reports the result with
`POST /api/v1/internal/secrets/{provider}/tested`, which sets `status` and `last_tested_at`. No event is needed.
