# ADR-0002: Event envelope (RabbitMQ)

**Status:** Accepted
**Date:** 2026-05-14

## Context

Every business event in the platform travels through RabbitMQ. With 16+ services as potential producers and consumers, the message shape must be standardized:

- A consumer must decode any event from any service without consulting the producer's documentation.
- Correlation must propagate end-to-end (incoming HTTP → publish → consume → outbound HTTP).
- Actor (who triggered the event) must be machine-readable for audit, not just human prose in `data`.
- Idempotency must be possible without trust: consumers dedupe by a unique `event_id` the producer chose.

Without an envelope contract, each service repeats this logic differently and consumers grow N parsers.

## Decision

Every event uses the **generic envelope** defined in [`schemas/events/_envelope.json`](../../schemas/events/_envelope.json). The SDK enforces the shape: `Abeon\SDK\Events\EnvelopeBuilder` always produces it, `Abeon\SDK\Events\EventConsumer` rejects messages that don't conform.

### Envelope fields

```json
{
  "event_id":   "uuid",
  "event_type": "string (routing key pattern)",
  "timestamp":  "ISO-8601 UTC with milliseconds",
  "source":     "publishing service name",
  "version":    "1.0",
  "org_id":     123,
  "actor": {
    "type":         "user | service | system",
    "user_id":      "string (when type=user)",
    "service_name": "string (when type=service)"
  },
  "data":     { /* event-specific payload */ },
  "metadata": {
    "correlation_id": "uuid",
    "causation_id":   "uuid (optional — for event chains)"
  }
}
```

### `org_id` — the tenant the event belongs to (ADR-0016)

**Top-level, not inside `actor`.** An event caused by a scheduled job or by the system itself has no
actor worth speaking of but still belongs to exactly one organisation: the tenant is a property of *the
event*, not of *who triggered it*. Nesting it under `actor` would make it unavailable precisely when
`actor.type = "system"`.

`org_id` is **required and non-null** for events arising from organisation-scoped work, which in
practice is all business events. It is `null` only for genuinely platform-level events (registry
self-registration, maintenance). `null` means "no organisation" — never "all organisations". A consumer
that requires a tenant and receives `null` must refuse the message rather than process it unscoped
(ADR-0018).

**This field is what makes the asynchronous half of the platform scopable at all.** `EventConsumer`
does not populate `AuthContext` — a handler runs with no request context and no authenticated user (see
`AuthContext`'s class PHPDoc) — so the envelope is a consumer's *only* possible source of tenant.
Without it, ADR-0018's scoping cannot reach any event handler.

### Routing key grammar

Routing key (also stored as `event_type` for self-describing messages):

```
{service}.{entity}.{action}
```

Regex: `^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$`

- Three segments, lowercase, underscores allowed inside segments.
- **Actions are facts in past tense**: `created`, `updated`, `won`, `paid`. NOT `create_x`, `send_x` — see ADR-0007 (future, REST vs Events usage).

Examples:
- `crm.contact.created` ✓
- `finance.invoice.paid` ✓
- `helpdesk.ticket.escalated` ✓
- `mailing.send_campaign` ✗ (`send_campaign` is a command, not a fact)
- `CRM.contact.created` ✗ (uppercase)

Enforced by `Abeon\SDK\Events\RoutingKey::assertValid()` — throws `ContractViolationException` on publish.

### Topology

- **Exchange:** `abeon.events` (topic, durable). Configurable via `ABEON_RABBITMQ_EXCHANGE`.
- **Dead-letter exchange:** `abeon.events.dlx` (topic, durable). Configurable via `ABEON_RABBITMQ_DLX`.
- **Queue per consumer:** `{queue_prefix}.{routing_key}` — declared by `EventConsumer` with `x-dead-letter-exchange = abeon.events.dlx`.
- **DLQ per consumer queue:** `{queue}.dlq` bound to DLX with the same routing key.

### Publish flow — outbox pattern

The default `EventPublisher` (= `OutboxPublisher`) does **not** push to RabbitMQ directly. It writes the envelope JSON to `abeon_event_outbox` table within the **same DB transaction** as the business write. Background worker `OutboxDrainer` (Artisan `abeon:events:outbox-drain`) drains the table to RabbitMQ asynchronously with exponential backoff on failure.

This trades a small delivery latency (default 1s poll + RabbitMQ roundtrip) for **exactly-at-least-once delivery**: business writes and event publishes commit atomically. A crash between commit and publish is recovered by the next drainer pass.

### Consume flow — idempotency

**`EventHandler` implementations MUST be idempotent.** This is a hard contract requirement, not a defence-in-depth nice-to-have. Re-running a handler with the same `Event` must produce the same state — no duplicate emails, no double-charged invoices, no incremented counters that move twice.

`EventConsumer` deduplicates by `event_id` via `abeon_processed_events` table:

1. Receive message.
2. If `event_id` already in `processed_events` → ack and skip.
3. Hydrate `CorrelationContext` from `metadata.correlation_id`.
4. Dispatch to handlers tagged `abeon.event_handler` whose `subscribesTo()` matches the routing key (topic wildcards `*` and `#` supported per AMQP spec).
5. On success: insert into `processed_events`, ack. (SQLSTATE 23000 unique-constraint violation is caught and treated as success; all other DB errors rethrow so the message is nacked instead of being silently acked.)
6. On handler exception: nack with `requeue=false` → message routed via DLX to DLQ.

#### Why idempotency is mandatory

The dedup table is the **second** line of defense. It does NOT prevent concurrent duplicate handler execution because step 1 (`isProcessed`) and step 5 (`markProcessed`) are not atomic. Concrete race:

- Drainer publishes the same event twice (e.g., outbox row picked up by two replicas without `SKIP LOCKED`).
- Broker delivers both messages.
- Worker A: `isProcessed=false` → starts handler.
- Worker B (or A again, after restart): `isProcessed=false` → starts handler.
- Both handlers run side effects.
- Worker A marks processed; Worker B's `markProcessed` hits unique-constraint, ack.

If the handler is non-idempotent, this is a real production bug. If the handler is idempotent, the duplicate execution is harmless.

#### Idempotency patterns

- **Inline upsert.** `Invoice::updateOrCreate(['id' => $event->data['invoice_id']], [...])` — replaying yields the same row.
- **Event-id-keyed external state.** Use `event_id` as idempotency key in downstream POST (e.g., Stripe `Idempotency-Key: $event_id`). Provider deduplicates.
- **Mark-then-act with own table.** Insert a row into `your_event_log` with unique `event_id` BEFORE side effects; if INSERT fails, skip. Stronger than `abeon_processed_events` because it's inside YOUR transaction.

#### Anti-patterns

- `$user->credit_balance += $event->data['amount']` — replays double-credit.
- `Mail::send($welcome)` without an idempotency check — double email.
- `Http::post('https://api.partner.io/charge', ...)` without idempotency key — double charge.

### Payload schemas — federation (M2/M3)

The SDK ships **only the generic envelope** schema. Per-event payload schemas (the contents of `data`) live in **each owning service's repository** and are discovered at bootstrap from `composer.json`:

```json
{
  "name": "abeon/crm",
  "extra": {
    "abeon": {
      "event-schemas": "schemas/events/"
    }
  }
}
```

`Abeon\SDK\Events\SchemaDiscovery` scans `vendor/composer/installed.json`, loads `*.json` files from each package's declared directory, indexes by routing key (filename basename). `Abeon\SDK\Events\EventCatalog::all()` returns the aggregated map.

In v1 the payload is **not** validated automatically — only the envelope is. Schema-validated publish is opt-in v0.2. This trades early-stage iteration speed for type safety; schemas exist to document intent, not yet to enforce.

### Versioning

Envelope is version 1.0. The `version` field exists for future-proofing: when a breaking payload change is required for a specific event type, options are:


1. **Suffix the routing key** with `.v2` (e.g. `crm.contact.created.v2`) — consumers bind to the new key explicitly.
2. **Bump `version` in envelope** (e.g. `"version": "2.0"`) — consumers branch on `version` in the handler.

Either path is supported by the contract. The choice depends on whether the new shape is "the same event, evolved" (envelope bump) or "logically a different event" (routing key suffix).

#### Why adding `org_id` took neither path (2026-08-12)

Adding a required field to a schema declared `"additionalProperties": false` is a breaking change, so
by the rules above it should have bumped the envelope to 2.0. **It amended 1.0 in place instead, and
that was a deliberate choice made at the only moment it was free:** no service consumes this envelope
yet. Zero producers, zero consumers, zero messages in flight — so there is no population to migrate and
no branch for a handler to take.

The alternative was to ship 1.0 without the tenant, then bump to 2.0 the moment the first service needed
scoping — paying for a dual-version consumer path to migrate a contract nobody had implemented. Recorded
explicitly because the same reasoning will **not** apply next time: once service #1 is live, the
versioning rules above are binding again.

## Consequences

**Positive:**
- One parser per consumer instead of N.
- Outbox guarantees at-least-once without distributed transactions.
- Topic wildcards allow consumer-side fan-in (`crm.contact.*`, `crm.#`).
- Federation = CRM team owns CRM event schemas, no platform-team bottleneck.

**Negative / accepted:**
- Outbox adds ~1s default delivery latency (configurable via `ABEON_OUTBOX_POLL_INTERVAL`).
- Schema not enforced on publish in v1 — caller responsibility. Mitigated by JSON Schema in service repo + contract tests.
- Per-queue DLQs require manual triage tooling (out of scope v1 — Grafana RabbitMQ dashboard suffices for now).

## References

- Implementation: `src/Events/EnvelopeBuilder.php`, `src/Events/RoutingKey.php`, `src/Events/OutboxPublisher.php`, `src/Events/OutboxDrainer.php`, `src/Events/EventConsumer.php`, `src/Events/SchemaDiscovery.php`, `src/Events/ProcessedEvents.php`
- Schema: `schemas/events/_envelope.json`
- Migrations: `database/migrations/2026_01_01_000001_create_abeon_event_outbox_table.php`, `..._000000_create_abeon_processed_events_table.php`
- Related: ADR-0003 (correlation), arch doc sekcja 6
- **Amended 2026-08-12 by ADR-0016** (multi-tenant organisations): the envelope gained a top-level
  `org_id`. **ADR-0018 (tenant scoping) depends on it** — without it, event handlers have no source of
  tenant at all. The amendment was applied to envelope 1.0 in place rather than bumping to 2.0; the
  reasoning is recorded under *Versioning* above.
- **2026-08-15 — the federation mechanism was never switched on.** This ADR federates payload schemas
  through `extra.abeon.event-schemas`, and `SchemaDiscovery` keys the catalog by **file name**. Neither
  half was true in the tree: this package declared no `extra.abeon`, so `EventCatalog::all()` returned
  an empty array in every service; and the two schemas that existed were named `app-registered.json`
  and `notification-requested.json`, neither of which is a routing key, so they would not have been
  discovered even with the declaration in place. Meanwhile three keys were being published with no
  schema at all — `auth.user.created`, `auth.membership.created`, `service.permissions.declared`.

  Nothing failed, because "schema not enforced on publish in v1" (above) means the catalog is unread
  today. That is precisely what made it invisible: the cost of the gap is zero until validation is
  turned on, at which point every publisher breaks at once.

  Now: the declaration exists, the three missing schemas are written, `app-registered.json` is
  `unified.app.registered.json`, and two tests hold it — one in this package asserting that every
  published key has a discoverable schema and that no routing-key-shaped file is an unlisted promise,
  and one in `abeon-auth` driving the real discovery path through `vendor/composer/installed.json`
  and comparing what actually lands in the outbox against the schema's declared fields. The second one
  can only live in a service: this package is not in its own vendor tree.

  `notification-requested.json` keeps its human-readable name deliberately. Its key,
  `*.notification.requested`, is a subscription pattern and `RoutingKey` refuses a wildcard, so it
  cannot be a discoverable file — each emitting service publishes `{service}.notification.requested`
  and ships its own copy under that name.

  Still open, and not fixed here: nothing drains the outbox. There is no broker, `abeon:events:outbox-drain`
  runs nowhere, and no `EventHandler` implementation exists — so this ADR's at-least-once guarantee has
  never been exercised end to end. `abeon-auth` now mounts `OutboxLagCheck`, so readiness reports
  `degraded` once rows accumulate instead of staying green against a promise nothing keeps.
