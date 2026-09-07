# ADR-0003: Correlation ID propagation

**Status:** Accepted
**Date:** 2026-05-14

## Context

Debugging a request that touches 5+ services requires correlating logs, events, and outbound calls across processes. Full distributed tracing (OpenTelemetry) is the long-term answer but introduces operational cost: SDK instrumentation, sampling decisions, exporter configuration, span context propagation rules. We defer that to a later phase (v0.3).

The minimum viable substitute — covering ~80% of debug value — is a **single correlation identifier** that travels with the request from inbound HTTP through any number of service-to-service calls and event publishes, and ends up in every log line emitted along the way.

## Decision

A UUIDv4 **correlation ID** propagates end-to-end via:

- HTTP header `X-Correlation-ID` on inbound and outbound requests.
- `metadata.correlation_id` field on every event envelope (see ADR-0002).
- `correlation_id` field on every structured log line (Loki-friendly).

### Lifecycle

1. **Ingress.** `Abeon\SDK\Http\CorrelationIdMiddleware` reads `X-Correlation-ID` from the inbound request. If absent, generates a fresh UUIDv4. The value is stored in `Abeon\SDK\Logging\CorrelationContext` (request-scoped singleton via Laravel's `$app->scoped()`).
2. **Outbound HTTP (service-to-service).** `Abeon\SDK\Client\ServiceClient` auto-injects the header on every `service(name)` call via `CorrelationContext::ensure()`.
3. **Event publish.** `Abeon\SDK\Events\EnvelopeBuilder` writes the correlation ID into `metadata.correlation_id` of every envelope.
4. **Event consume.** `Abeon\SDK\Events\EventConsumer` reads the correlation from the envelope and hydrates `CorrelationContext` for the duration of the handler — log lines emitted during handling pick it up automatically.
5. **Response.** `CorrelationIdMiddleware` echoes the header back on the outgoing response so clients (including the frontend) can record it for support tickets.
6. **Logging.** `Abeon\SDK\Logging\JsonFormatter` includes `correlation_id` (field name from `ABEON_LOG_CORRELATION_FIELD`, default `correlation_id`) in every log record.

### Where it's stored

- **In-process:** `CorrelationContext` is `$app->scoped()` — fresh instance per HTTP request, but persists across the request's lifetime.
- **Across processes:** header on HTTP, envelope field on events.
- **No persistence layer** — correlation IDs are tied to a request flow, not to durable entities.

### UUID generation

`CorrelationContext::generate()` produces UUIDv4 using `random_bytes(16)` with bit-twiddling for version+variant. No external dependency (`ramsey/uuid` not required).

### Frontend SSR forwarding

Next.js SSR forwards the inbound `X-Correlation-ID` from `headers()` when making downstream calls, generating a new UUID only when the inbound is absent. This matches V3 (Next.js readiness) decision in `@abeon/sdk-ts` plan v1.1. Without forwarding, Browser↔SSR↔backend correlation is severed.

### Forward-compatibility with OpenTelemetry

The envelope's `metadata` object is open (no `additionalProperties: false` at that level). When we add OpenTelemetry in v0.3:

- New field `metadata.trace.traceparent` (W3C Trace Context header value).
- New field `metadata.trace.tracestate` (optional).

Existing consumers continue to work — they read `correlation_id` as today. New consumers may use `traceparent` for full distributed tracing.

This is **NOT** added in v1 (YAGNI — no consumer is asking for OTEL yet) but is intentionally compatible.

## Consequences

**Positive:**
- One field to grep across all logs in Loki: `{service="crm"} | json | correlation_id="abc-123-..."`.
- Trivial DX — middleware is opt-in via route group, everything else is automatic.
- Works for both sync (HTTP) and async (events) paths.
- Cookie-friendly: correlation ID is not sensitive, no privacy concern.

**Negative / accepted:**
- No span-level granularity. Can't tell "which call took 800ms" from logs alone — only "which request" and timestamps.
- No automatic context propagation across `Queue::dispatch()` or thread boundaries — only the explicit paths SDK controls.
- A consumer that does heavy fan-out (one event → many sub-jobs) loses correlation unless it explicitly re-propagates.

These are accepted in v1 because the alternative (OTEL) costs disproportionately to the debugging benefit at <10 services.

## References

- Implementation: `src/Http/CorrelationIdMiddleware.php`, `src/Logging/CorrelationContext.php`, `src/Logging/JsonFormatter.php`
- Used by: `src/Client/ServiceClient.php`, `src/Events/EnvelopeBuilder.php`, `src/Events/EventConsumer.php`
- Helper: `abeon_correlation_id(): ?string` global function
- Related: ADR-0002 (envelope), arch doc sekcja 10.2
