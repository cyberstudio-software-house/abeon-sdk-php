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

`EventConsumer` deduplicates by `event_id` via `abeon_processed_events` table:

1. Receive message.
2. If `event_id` already in `processed_events` → ack and skip.
3. Hydrate `CorrelationContext` from `metadata.correlation_id`.
4. Dispatch to handlers tagged `abeon.event_handler` whose `subscribesTo()` matches the routing key (topic wildcards `*` and `#` supported).
5. On success: insert into `processed_events`, ack. (Unique-constraint violation on duplicate is caught and treated as success.)
6. On handler exception: nack with `requeue=false` → message routed via DLX to DLQ.

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
