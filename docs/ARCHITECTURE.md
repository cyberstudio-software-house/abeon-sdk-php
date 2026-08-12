# `abeon/sdk` — Architecture

**Version:** 0.2 (Phase 0.5 chrome additions on top of Sprint 0–4 baseline)
**Status:** Implementation in progress; 138/138 PHPUnit green; 252 assertions
**Date:** 2026-05-15

This document is the source of truth for how the SDK is structured, what it
contracts to do for its consumers, and how its subsystems interact at
runtime. It complements:

- [`README.md`](../README.md) — installation, quick examples, env reference.
- [`docs/usage.md`](usage.md) — end-to-end walkthrough for a new service.
- [`docs/events-catalog.md`](events-catalog.md) — event schema federation.
- [`docs/adr/`](adr/) — ten ADRs covering every cross-cutting contract.

Audience: backend engineers integrating the SDK into a new Abeon service,
SDK maintainers, and platform reviewers evaluating SDK changes.

---

## 1. Purpose

`abeon/sdk` is a **Composer package** installed by every PHP/Laravel service
in the Abeon Unified platform. It is the **bottom half** of the
cross-service contract (the top half is `@abeon/shared` for TypeScript
frontends). Its job is to make 16+ independent microservices behave like
one coherent system — without imposing a service mesh, a shared monolith, or
a runtime container.

The SDK **does**:

- Validate inbound user JWTs and expose the decoded `User` to application code.
- Issue outbound service-to-service JWTs and inject them into HTTP calls.
- Propagate `X-Correlation-ID` across HTTP boundaries, RabbitMQ events, and structured logs.
- Provide an outbox-based event publisher and an idempotent event consumer.
- Standardise REST envelopes (`{data, meta}`), RFC 7807 errors, and health probes.
- Self-register the service with Auth so the federated chrome can discover it.
- Authorise WebSocket subscriptions (Reverb) using the same cookie/JWT model.
- Ship reference controllers for `/api/v1/auth/{user,apps,me/preferences}`.

The SDK **does not**:

- Implement business logic, persistence schemas beyond its own infrastructure tables, or domain models.
- Implement the Auth service itself (`abeon-auth` repo, Phase 1).
- Implement AbeonUnified — notifications, app registry, org↔app assignment (`abeon-unified` repo, Phase 0.5 / 1; ADR-0019).
- Ship a frontend — that's `@abeon/shared` + `@abeon/ui`.
- Manage Kubernetes resources, Helm charts, or deploy pipelines.

---

## 2. Architectural principles

Five rules that drive every decision in this codebase:

1. **The SDK is a library, not a framework.** It auto-registers a Laravel
   service provider for ergonomics, but every primitive is callable from
   plain PHP. No magic — no facades, no global state, no lifecycle hooks
   beyond what Laravel's IoC already gives the consumer.

2. **Layers descend.** A higher layer (Services → Client → Events → Auth →
   Core) may call lower layers; the reverse never holds. This lets us
   factor sub-packages later (e.g. extract `abeon/auth-middleware` for a
   leaner Inertia-only install) without archaeology.

3. **Contracts before code.** Every cross-service surface is captured first
   as an ADR + a JSON Schema, then implemented. The ADR is the law; the
   code follows. Ten ADRs live in [`docs/adr/`](adr/).

4. **Idempotency is the default.** Service-to-service POSTs auto-inject
   `Idempotency-Key`; event consumers dedup on `event_id`; outbox publish
   inside the business transaction means the outbox row commits with the
   write (or not at all). Replays are safe.

5. **Operational concerns are first-class.** Health probes, correlation IDs,
   structured logs, retry budgets, dead-letter queues, JWKS rotation
   tolerance — all wired into the core primitives, not bolted on.

---

## 3. Layered architecture

```
┌──────────────────────────────────────────────────────────────────────┐
│  Layer 5: Endpoints                                                  │
│    Auth/Endpoints (UserController, AppsController, PreferencesController) │
│    Broadcasting (BroadcastingAuthController)                         │
│    Services (ServiceRegistry — POSTs descriptor to Auth)             │
│                                                                      │
│  Reference HTTP controllers + bootstrap helpers. Optional — each     │
│  service mounts only what it needs. Lives at the top because they    │
│  compose every layer below.                                          │
├──────────────────────────────────────────────────────────────────────┤
│  Layer 4: Async I/O — Events                                         │
│    OutboxPublisher  →  abeon_event_outbox table                      │
│    OutboxDrainer    →  RabbitMQ topic exchange                       │
│    EventConsumer    →  RabbitMQ queues + abeon_processed_events      │
│    EnvelopeBuilder, RoutingKey, ProcessedEvents, RabbitMq            │
├──────────────────────────────────────────────────────────────────────┤
│  Layer 3: Sync I/O — Client                                          │
│    ServiceClient            → outbound HTTP w/ retries, idempotency  │
│    ServiceTokenProvider     → cached RS256 service JWTs              │
│    ServiceCallException     → RFC 7807 lifted from upstream          │
├──────────────────────────────────────────────────────────────────────┤
│  Layer 2: Identity — Auth                                            │
│    JwksClient               → JWKS fetch + cache (key rotation)      │
│    JwtValidator             → RS256 verify + claim assertions        │
│    AuthMiddleware           → Bearer header → AuthContext            │
│    AuthContext (scoped)     → request-bound User                     │
│    PermissionsServiceProvider + PermissionsDeclarator (Laravel Gate) │
├──────────────────────────────────────────────────────────────────────┤
│  Layer 1: HTTP plumbing                                              │
│    CorrelationIdMiddleware  → X-Correlation-ID in/out                │
│    Cors                     → config-driven allow-list               │
│    VersionHeadersMiddleware → X-API-Version + Deprecation/Sunset     │
│    ApiResponse              → {data, meta} + paginated helpers       │
│    ProblemDetailsRenderer   → RFC 7807 from any AbeonException       │
├──────────────────────────────────────────────────────────────────────┤
│  Layer 0: Core — no framework deps beyond Laravel container          │
│    Config/AbeonConfig       → typed config accessors                 │
│    DTO/*                    → immutable value objects                │
│    Exceptions/*             → typed AbeonException hierarchy         │
│    Health/* (Check, Result) → probe primitives                       │
│    Logging/CorrelationContext, JsonFormatter                         │
│    Support/Uuid, PathPrefix                                          │
└──────────────────────────────────────────────────────────────────────┘
```

Each layer in this picture is realised by one PSR-4 sub-namespace under
`Abeon\SDK\`. The diagram **matches the source tree** under `src/`.

### Direction-of-dependency rule

A class in Layer N may type-hint and call Layer 0 … N-1. It may **not**
type-hint a class above its own layer. CI keeps this honest with a PHPStan
rule (planned) that flags upward arrows.

The two exceptions called out explicitly:

- `BroadcastingAuthController` (Layer 5) takes `Broadcaster` from Laravel
  (outside the SDK layers) — that's the design.
- `EnvelopeBuilder` (Layer 4) reads `AuthContext` (Layer 2) for the default
  `Actor` claim. Layer 4 → Layer 2 is downward, allowed.

---

## 4. Public namespace map

| Namespace | Purpose | Key public classes |
|---|---|---|
| `Abeon\SDK\Auth` | Identity, JWT, permissions | `AuthMiddleware`, `JwtValidator`, `JwksClient`, `AuthContext`, `PermissionsServiceProvider`, `PermissionsDeclarator` |
| `Abeon\SDK\Auth\Endpoints` | Reference controllers for Auth-owned endpoints (ADR-0010) | `UserController`, `AppsController`, `PreferencesController` |
| `Abeon\SDK\Broadcasting` | Reverb `/broadcasting/auth` (ADR-0008) | `BroadcastingAuthController` |
| `Abeon\SDK\Client` | Service-to-service HTTP | `ServiceClient`, `ServiceTokenProvider`, `ServiceCallException` |
| `Abeon\SDK\Config` | Typed config + `abeon:config:validate` | `AbeonConfig`, `Commands\ValidateConfigCommand` |
| `Abeon\SDK\DTO` | Immutable value objects mirrored in JSON Schemas | `User`, `AppDescriptor`, `Actor`, `Permission`, `Role`, `Pagination`, `ProblemDetails` |
| `Abeon\SDK\Events` | Outbox + consumer | `EventPublisher` (alias `OutboxPublisher`), `EventConsumer`, `OutboxDrainer`, `EnvelopeBuilder`, `RoutingKey`, `EventCatalog`, `SchemaDiscovery`, `ProcessedEvents`, `RabbitMq`, `EventHandler` interface, `Event` DTO, `InMemoryEventPublisher` (test double), three Artisan commands |
| `Abeon\SDK\Exceptions` | Typed errors → RFC 7807 | `AbeonException`, `AuthException`, `ContractViolationException` |
| `Abeon\SDK\Health` | K8s probes | `Check` interface, `DbCheck`, `RabbitMqCheck`, `OutboxLagCheck`, `HealthController`, `CheckResult` |
| `Abeon\SDK\Http` | Plumbing middlewares + helpers | `CorrelationIdMiddleware`, `Cors`, `VersionHeadersMiddleware`, `ApiResponse`, `ProblemDetailsRenderer` |
| `Abeon\SDK\Logging` | JSON logs + correlation | `CorrelationContext` (scoped), `JsonFormatter` |
| `Abeon\SDK\Services` | App registry | `ServiceRegistry`, `Commands\RegisterCommand` |
| `Abeon\SDK\Support` | Utilities | `Uuid`, `PathPrefix` |

`Abeon\SDK\helpers.php` defines `abeon_user(): ?User` for ergonomic access
to the request-scoped `AuthContext`.

---

## 5. Runtime contracts

The SDK enforces these on-the-wire contracts. Every Abeon service speaks
them; the SDK is what makes that automatic.

| Contract | Where | Spec |
|---|---|---|
| User JWT (RS256) | `Authorization: Bearer <jwt>` header | [ADR-0001](adr/0001-jwt-format.md), schema `schemas/auth/jwt-user.json` |
| Service JWT (RS256) | Same header, inter-service calls | [ADR-0001](adr/0001-jwt-format.md), schema `schemas/auth/jwt-service.json`, [ADR-0005](adr/0005-service-to-service-auth.md) |
| Event envelope | RabbitMQ message body | [ADR-0002](adr/0002-event-envelope.md), schema `schemas/events/_envelope.json` |
| Routing key grammar | `{service}.{entity}.{action}` | [ADR-0002](adr/0002-event-envelope.md), enforced by `RoutingKey::assertValid()` |
| Correlation ID | `X-Correlation-ID` (HTTP), `metadata.correlation_id` (envelope) | [ADR-0003](adr/0003-correlation-id.md) — UUIDv4, validated on inbound |
| REST envelope | `{data, meta}` JSON body | [ADR-0004](adr/0004-rest-envelope-and-errors.md) |
| REST errors | `application/problem+json` (RFC 7807) | [ADR-0004](adr/0004-rest-envelope-and-errors.md), schema `schemas/http/problem-details.json` |
| Notifications | `*.notification.requested` events + REST + Reverb `user.{id}` | [ADR-0006](adr/0006-notifications-contract.md) |
| Command palette | Per-service in-memory registry; no cross-app FT in Phase 0.5 | [ADR-0007](adr/0007-search-and-command-registry.md) |
| Broadcasting auth | `POST /broadcasting/auth`, cookie → JWT → Reverb sig | [ADR-0008](adr/0008-broadcasting-auth.md) |
| User preferences | `GET/PATCH /api/v1/auth/me/preferences`, versioned JSON blob | [ADR-0009](adr/0009-user-preferences.md), schema `schemas/dto/preferences.json` |
| Auth user + apps | `GET /api/v1/auth/{user,apps}` | [ADR-0010](adr/0010-auth-me-and-apps-endpoints.md) |

---

## 6. HTTP request lifecycle

What happens when an authenticated request hits an Abeon service. Read
top-to-bottom; the SDK owns the boxes marked **★**.

```
HTTP request
   │
   ▼
┌────────────────────────────────────────────────┐
│ Laravel: web/api middleware stack              │
│   - throttle, cookies, CSRF (web)              │
│                                                │
│ ★ abeon.correlation                            │
│     X-Correlation-ID inbound (UUIDv4) or new   │
│     CorrelationContext::set($cid)              │
│                                                │
│ ★ abeon.cors (optional, opt-in)                │
│     reads abeon.cors.allowed_origins           │
│                                                │
│ ★ abeon.auth                                   │
│     extract Bearer → JwtValidator::decodeUser  │
│       │                                        │
│       └── JwksClient::findKey($kid)            │
│             │                                  │
│             ├─ cache hit → verify              │
│             └─ miss → flush + refetch JWKS     │
│     AuthContext::set($user)                    │
│                                                │
│ ★ abeon.version (optional)                     │
│     attach X-API-Version / Deprecation         │
│                                                │
│ App route handler                              │
│   - reads abeon_user()                         │
│   - calls $client->service('finance')->get()   │
│       │                                        │
│       ▼                                        │
│   ★ ServiceClient                              │
│     - ServiceTokenProvider::token() (cached)   │
│     - inject X-Correlation-ID                  │
│     - inject Idempotency-Key (POST/PUT/PATCH)  │
│     - retry on 5xx + connection (max_retries)  │
│     - throw ServiceCallException on non-2xx    │
│                                                │
│   - DB::transaction()                          │
│       - Eloquent writes                        │
│       ★ EventPublisher::publish('crm.foo')     │
│         (writes to abeon_event_outbox)         │
│                                                │
│ ★ ApiResponse::data($result)                   │
│     wraps as {data, meta}, 200/201/204         │
│                                                │
│ ★ ProblemDetailsRenderer (exception handler)   │
│     any AbeonException → application/problem+json │
│                                                │
│   X-Correlation-ID echoed on response          │
└────────────────────────────────────────────────┘
   │
   ▼
HTTP response
```

After the response, the **OutboxDrainer worker** (separate process) picks
up the row written in step `EventPublisher::publish()` and emits it to
RabbitMQ. That asynchronous half is detailed in §9 below.

---

## 7. Auth subsystem

### 7.1 JWT validation flow

`AuthMiddleware::handle()` is intentionally minimal: it extracts the
Bearer token, hands it to `JwtValidator::decodeUser()`, sets
`AuthContext`. It does **not** consult cookies — the cookie→header
translation is the responsibility of the frontend backend (Inertia / Next
SSR), keeping the SDK transport-agnostic. See ADR-0001 §"Cookie ↔
Authorization translation".

`JwtValidator::decode()` enforces:

1. Header `kid` resolves against `JwksClient::findKey($kid)`. On miss, the
   client flushes its cache and retries once — covers the propagation
   window when a service rotates its signing key.
2. RS256 signature verifies against the resolved JWK.
3. `iss` matches the configured issuer (default `abeon-auth`).
4. `aud` matches the configured audience (default `abeon`).
5. `exp` is in the future.
6. `type` claim matches the expected token kind (`user` for `decodeUser`,
   `service` for `decodeService`).

Failures throw `AuthException::unauthenticated($reason)` which the renderer
turns into a 401 RFC 7807 problem.

### 7.2 `AuthContext` lifecycle

```php
$this->app->scoped(AuthContext::class);  // AbeonServiceProvider
```

`scoped` means: one instance per HTTP request in PHP-FPM; one instance per
Octane/Swoole *request* (Octane resets scoped bindings between requests).
Long-running workers that bypass the HTTP kernel **must call
`AuthContext::clear()` between units of work** — `EventConsumer` does this
in its `finally` block; custom long-lived runners must follow suit.

`abeon_user()` (in `src/helpers.php`) is just `app(AuthContext::class)->user()`
with an autoload-eager registration.

### 7.3 Permissions federation

Each service declares its permissions in `config('abeon.permissions')`:

```php
'permissions' => [
    'crm.contacts.read',
    'crm.contacts.write',
    'crm.deals.read',
],
```

Running `php artisan abeon:permissions:declare` (typically a CI/CD step
on deploy) publishes a `service.permissions.declared` event that Auth
consumes to update the federated RBAC catalog. The permission JWT claim is
authoritative at request time; declaration is for *display* in the UI
(role editor) and for `AppsController` filtering (ADR-0010).

`PermissionsServiceProvider::attach()` is wired through `Gate::resolving`
so `Gate::allows('crm.contacts.read')` consults `AuthContext::user()->permissions`.

---

## 8. Service-to-service HTTP

### 8.1 `ServiceClient`

```php
$client->service('finance')->get('/api/v1/invoices', ['contact_id' => 42]);
```

`->service($name)` returns a Laravel `PendingRequest` pre-configured with:

| Aspect | Source |
|---|---|
| Base URL | `config('abeon.services.<name>.url')` |
| `Authorization: Bearer ...` | `ServiceTokenProvider::token()` (cached, RS256) |
| `X-Correlation-ID` | `CorrelationContext::ensure()` (inherits or generates) |
| `Idempotency-Key` | `Uuid::v4()` per call (POST/PUT/PATCH/DELETE) — MD-10 |
| Timeouts | `clientTimeoutSeconds`, `clientConnectTimeoutSeconds` |
| Retries | `clientMaxRetries` attempts on connection errors + 5xx only — MD-4 |
| Throw on non-2xx | `ServiceCallException::fromResponse()` — lifts RFC 7807 body |
| 401 handling | `ServiceTokenProvider::flush()` so the next call mints a fresh token — MD-5 |

The retry predicate excludes 4xx by design: those are caller bugs, not
transient issues. Connection errors and 5xx are retried with a flat delay
(`clientRetryDelayMs`, default 500ms). Exponential backoff is reserved for
the outbox; sync calls live in tight latency budgets.

### 8.2 `ServiceTokenProvider`

Token caching is **per process**, not per request. A singleton binding
means an Octane/Swoole worker reuses the cached token across thousands of
requests, only re-signing when the TTL (default 5 min) approaches with
30s of refresh margin.

Implications:

- A process restart (deploy, OOM, scaling) is the natural rotation event.
- A downstream 401 calls `flush()`, so a rotated public key on the
  downstream invalidates the cache immediately — see ADR-0005.
- Tokens are never persisted; no Redis, no DB. The cost of "lose them on
  crash" is one re-sign (cheap; RS256 with a small payload).

---

## 9. Event subsystem

### 9.1 Why outbox?

Publishing an event must be **atomic** with the business write. Two
naïve approaches fail:

- *Publish-then-write*: if the write fails, you've leaked a phantom event.
- *Write-then-publish*: if the broker is down or the process crashes, you've
  silently lost the event.

The outbox pattern atomically persists "I owe an emission" alongside the
business state, and a separate process drains it to the broker. Failure
modes become: a row stuck in the outbox (visible, retriable) or a duplicate
emission on retry (consumer-side idempotency handles it).

### 9.2 `OutboxPublisher`

```php
DB::transaction(function () use ($publisher) {
    $contact = Contact::create([...]);
    $publisher->publish('crm.contact.created', [
        'contact_id' => $contact->id,
    ]);
});
```

`publish()` **requires** an enclosing transaction. Without it, the outbox
row commits independently from the business state — defeating the whole
point of the pattern. We enforce this:

```php
if ($connection->transactionLevel() === 0) {
    throw ContractViolationException::publishOutsideTransaction($routingKey);
}
```

(HI-1 fix; ADR-0002 §"Outbox semantics".)

The envelope is built by `EnvelopeBuilder` from `RoutingKey::assertValid()`-d
input plus:

- `event_id`: fresh UUIDv4.
- `timestamp`: UTC ISO-8601 with milliseconds.
- `source`: this service name (`abeon.service.name`).
- `actor`: provided, or derived from `AuthContext` (User → `Actor::user`)
  or service name (`Actor::service`) when none.
- `metadata.correlation_id`: from `CorrelationContext`.
- `metadata.causation_id`: passed in when emitting in response to another event.

### 9.3 `OutboxDrainer`

A long-running worker (Artisan `abeon:events:outbox-drain`) that:

1. Polls `abeon_event_outbox` in batches of `batch_size` (default 100),
   ordered by `id`, filtered by `processed_at IS NULL` and `next_attempt_at
   <= now()` and `attempts < max_attempts`.
2. For each row: publishes to the configured exchange with the row's
   routing key. On success, sets `processed_at`. On failure, increments
   `attempts`, records `last_error`, and schedules `next_attempt_at` with
   **exponential backoff** capped at 5 minutes (`min(300, 2^attempts)`).
3. Rows exceeding `max_attempts` (default 5) park in the table with their
   last error for manual triage. There is **no automatic DLQ on the
   publisher side** — DLX is consumer-side only (asymmetry by design: a
   stuck publish is almost always a misconfiguration, not a poison payload).
4. Empty-batch sleeps for `poll_interval` seconds, then resumes. Handles
   SIGTERM/SIGINT cleanly via `pcntl_signal`.

### 9.4 `EventConsumer`

Same Artisan-bound model on the consume side (`abeon:events:consume`).
Key behaviours:

- **Subscriptions are inferred** from `EventHandler::subscribesTo()` of
  every service tagged `abeon.event_handler` in the container. Override via
  `config('abeon.events.consumer.subscriptions')` if the static set differs
  from the runtime set (e.g. tooling-driven subscriptions).
- **Each routing key maps to a durable queue** named `{queue_prefix}.{key}`
  with a corresponding `*.dlq` queue and `x-dead-letter-exchange` pointing
  at `abeon.events.dlx`.
- **Idempotency via `abeon_processed_events`**: before dispatching, the
  consumer checks `event_id` against the dedup table. If processed, ack
  and skip. After successful dispatch, `markProcessed()` writes
  `(event_id, routing_key)` — race-safe because the PRIMARY KEY on
  `event_id` makes concurrent inserts fail with SQLSTATE 23000, caught by
  ProcessedEvents (HI-3 fix).
- **Wildcard support** (`*` = one segment, `#` = zero or more) follows the
  AMQP topic spec (HI-4 fix).
- **Per-message lifecycle**: pull correlation_id from envelope → set
  `CorrelationContext` → invoke matching handlers → `markProcessed` →
  `basic_ack`. On handler exception, `basic_nack` without requeue (→ DLX)
  and the correlation context clears in `finally`.

**Handlers must be idempotent.** This is the load-bearing assumption.
There is no "exactly-once" delivery on top of AMQP; the dedup table makes
"at-least-once + idempotent handler = effectively once" tractable. ADR-0002
§"Consume flow & idempotency" has concrete patterns (upsert, optimistic
locking, processed-marker rows).

### 9.5 Envelope shape

```json
{
  "event_id": "550e8400-e29b-41d4-a716-446655440000",
  "event_type": "crm.contact.created",
  "timestamp": "2026-05-15T10:00:00.123Z",
  "source": "crm",
  "version": "1.0",
  "actor": { "type": "user", "id": "42" },
  "data": { "contact_id": 1234, "email": "..." },
  "metadata": {
    "correlation_id": "...",
    "causation_id": "..."
  }
}
```

Schema: `schemas/events/_envelope.json`. Each per-event payload schema lives
under the owning service's repo and is discovered via `SchemaDiscovery`
(see [`docs/events-catalog.md`](events-catalog.md)).

---

## 10. Health probes

Two endpoints registered by `AbeonServiceProvider::boot()` via
`routes/health.php`:

| Route | Purpose | Behaviour |
|---|---|---|
| `GET /health` | **Liveness** | Returns 200 `{status: "ok"}` if PHP is breathing. No external checks. |
| `GET /health/ready` | **Readiness** | Runs every `Check` listed in `abeon.health.checks` (env CSV). Returns 200/503 + per-check breakdown. |

Built-in checks (auto-registered):

| Check | Verifies |
|---|---|
| `DbCheck` | `SELECT 1` against the default DB connection. |
| `RabbitMqCheck` | TCP connect + AMQP heartbeat against `ABEON_RABBITMQ_DSN`. |
| `OutboxLagCheck` | Oldest unprocessed `abeon_event_outbox` row is < `outbox.lag_threshold` seconds old. Degraded when over threshold. Returns OK when the table is empty or the migration hasn't run (MD-7). |

The `Check` interface is the extension point — services register custom
checks via the container alias `abeon.health.check.<name>` and add the
name to `ABEON_HEALTH_CHECKS`. The controller aggregates status with
`down > degraded > ok` precedence and HTTP-codes accordingly.

---

## 11. Correlation propagation

Three substrates: HTTP, RabbitMQ, logs. One identifier.

```
inbound HTTP
   X-Correlation-ID: <uuid>
            │
            ▼
   CorrelationContext::set($cid)   ← Logging\CorrelationContext (scoped)
            │
            ├──────────────────────────────────────┐
            │                                      │
            ▼                                      ▼
   outbound HTTP                          OutboxPublisher::publish()
   ServiceClient injects                  envelope.metadata.correlation_id
   X-Correlation-ID                                │
            │                                      ▼
            ▼                              RabbitMQ message
   downstream service                              │
   reads X-Correlation-ID                          ▼
   sets its own CorrelationContext        EventConsumer reads metadata
                                          re-sets CorrelationContext
                                          for handler execution

   JsonFormatter on every Monolog record adds correlation_id field
   → flows to Loki/Jaeger/Elasticsearch with the same UUID throughout.
```

Validation on inbound: UUIDv4 regex match (MD-1). Non-UUID inputs are
silently replaced with a fresh ID — prevents CRLF/log injection. Outbound
headers always carry a valid UUID (existing or generated).

---

## 12. Configuration model

`Abeon\SDK\Config\AbeonConfig` is the only class that touches Laravel's
`config()`. Every accessor:

- Has a return type (`string`, `int`, `?string`, `list<string>`).
- Has a sane default or throws with a precise message naming the env var.
- Coerces ENV strings (e.g. CSV → array, decimal → float with `max(0.1, …)`).

This keeps the consumer of any SDK class one layer away from
stringly-typed `config()->get()` calls. Test fixtures can pass a
`new AbeonConfig(new Repository([...]))` for full isolation.

`php artisan abeon:config:validate` is a startup gate:

- `--require-jwt-key` checks `ABEON_SERVICE_JWT_PRIVATE_KEY` and `_KID` are
  set (for services that issue outbound calls).
- `--require-rabbitmq` checks `ABEON_RABBITMQ_DSN` is set (for services that
  publish/consume).
- Secrets are masked in output (MD-9).

The full env reference is in [`README.md`](../README.md#configuration-reference).

---

## 13. Persistence — tables owned by the SDK

The SDK ships three migrations under `database/migrations/`. Every Abeon
service runs them (Laravel auto-loads via `loadMigrationsFrom`). These
tables are SDK-private; service code should not query them directly.

### `abeon_event_outbox`

The publisher writes here in the business transaction; the drainer reads.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint AI PK | Drainer ordering. |
| `event_id` | uuid UNIQUE | UUIDv4 from envelope; idempotency anchor. |
| `routing_key` | varchar(255) | AMQP routing key (`crm.contact.created`). |
| `envelope` | LONGTEXT | Full JSON envelope. |
| `created_at` | timestamp | Set on insert. |
| `processed_at` | timestamp nullable | Set when emit succeeds. |
| `attempts` | uint | Increments on failure. |
| `next_attempt_at` | timestamp nullable | Backoff scheduling. |
| `last_error` | text nullable | Truncated to 1000 chars for triage. |

Indexes: `(processed_at, next_attempt_at)` for drainer fetches,
`(routing_key)` for ops triage, `(created_at)` for lag computation.

### `abeon_processed_events`

The consumer writes here after a successful dispatch.

| Column | Type | Notes |
|---|---|---|
| `event_id` | char(36) PK | UUIDv4 from envelope; concurrent inserts collide on PK. |
| `routing_key` | varchar(255) | For ops queries. |
| `processed_at` | timestamp | Set on insert. |

Indexes: `(routing_key)`, `(processed_at)`. **No retention policy ships
with the SDK** — services should run a daily prune (e.g. drop rows older
than 90 days) since the dedup window only needs to span max retry/replay
horizons.

### `user_preferences` (Auth-only)

Per [ADR-0009](adr/0009-user-preferences.md), only the Auth service runs
this migration. It exists in the SDK so `PreferencesController` can query a
stable schema.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint AI PK | |
| `user_id` | bigint UNIQUE | One row per user. |
| `preferences` | JSON | Versioned blob (`{version: 1, chrome: {...}, ...}`). |
| `created_at`, `updated_at` | timestamps | |

Other services that mistakenly run this migration are harmless — the table
sits empty.

---

## 14. Extension points

How services plug into the SDK without forking it.

| Point | Mechanism | Example |
|---|---|---|
| New health check | Bind under alias `abeon.health.check.<name>` and add `<name>` to `ABEON_HEALTH_CHECKS` | Custom Redis check |
| New event handler | Implement `EventHandler` and tag the binding `abeon.event_handler` | `OnContactCreated implements EventHandler` |
| Custom permissions logic | Extend `PermissionsDeclarator` and rebind | Per-tenant permission filtering |
| Custom apps filter | Subclass `Auth\Endpoints\AppsController`, override `isVisibleTo()` | Admin override for hidden apps |
| Custom preferences endpoint | Subclass `Auth\Endpoints\PreferencesController`, override `mergeTopLevel()` | Strict schema validation |
| Custom service descriptor | Override `config('abeon.app_descriptor')` per env | Different label per region |
| Custom broadcasting policy | Mount your own controller + leave SDK's for chrome channels | Tenant-scoped channel auth |
| In-memory publisher (tests) | Replace `EventPublisher` binding with `InMemoryEventPublisher` | Unit-test assertions on published events |
| Schema federation | Declare per-event JSON schemas in `composer.json` metadata | `EventCatalog::discover()` aggregates at runtime |

The SDK never uses `final` on public classes that have plausible extension
needs. Where it does (DTOs, `OutboxPublisher`), composition is the
escape hatch.

---

## 15. Operational concerns

### 15.1 Octane / Swoole compatibility (LO-5)

The SDK was authored from day one with long-lived worker semantics in
mind. The audit table:

| Class | Concern | Resolution |
|---|---|---|
| `AuthContext` | Request-scoped; must reset between requests | `scoped` binding + explicit `clear()` in `EventConsumer::onMessage` finally |
| `CorrelationContext` | Same — request-scoped | `scoped` binding |
| `ServiceTokenProvider` | Process-scoped cache by design | `singleton` + `flush()` on 401 |
| `JwksClient` | Cache backed by Laravel cache, not in-memory | OK for any worker model |
| `JsonFormatter` | Stateless | OK |
| `ProcessedEvents` | DB-backed | OK |

A class-level PHPDoc on every scoped binding documents the contract.

### 15.2 Kubernetes integration

The SDK assumes Helm + standard K8s primitives without depending on them:

- **Probes**: `/health` for liveness, `/health/ready` for readiness, both
  HTTP. Set initial-delay generously for migrations.
- **Secrets**: `ABEON_SERVICE_JWT_PRIVATE_KEY` from a `Secret`, mounted as
  env. JWKS aggregation uses the future M7 label discovery
  (`abeon.io/service=true` + `abeon.io/jwks-url`).
- **Signals**: long-running workers handle SIGTERM/SIGINT for graceful
  pod termination via `pcntl_async_signals`.
- **Init containers**: run `abeon:config:validate --require-jwt-key
  --require-rabbitmq` as a `postStart` hook or init container to fail-fast
  on misconfiguration.

### 15.3 Observability

The SDK contributes:

- **Logs**: every Monolog record gets `correlation_id` and `service` fields
  via `JsonFormatter`.
- **Tracing**: `X-Correlation-ID` propagates through every HTTP and AMQP
  hop. A future Jaeger integration can adopt it directly as the trace ID
  prefix.
- **Metrics**: not shipped (yet). Prometheus client integration is a
  Phase 2 candidate. Consumers can wire their own metrics middleware
  against `Cache`/`OutboxLagCheck` outputs.

### 15.4 Failure modes & runbooks

| Symptom | Likely cause | Resolution |
|---|---|---|
| 401 from downstream services | Service JWT expired in cache during key rotation | SDK auto-flushes on 401 and retries; verify by checking logs for `service-token.flushed`. If persistent, rotate JWKS. |
| Outbox lag > threshold | Drainer worker not running or RabbitMQ unreachable | `kubectl logs <drainer>`; check RabbitMQ readiness. Rows are safe; drainer resumes from `id`. |
| Events DLQ filling | Handler throws repeatedly | Check `event-consumer.handler-failed` log entries; the routing key + event_id pinpoint the consumer service + the offending payload. |
| Outbox rows stuck > max_attempts | Bad schema, missing exchange | Inspect `last_error`; usually a config / topology drift on RabbitMQ. Fix exchange, then `UPDATE … SET attempts = 0, next_attempt_at = NULL` to retry. |
| 503 from `/health/ready` | One probe down | The response body lists which check failed; act on that. Liveness still passes so K8s won't restart unnecessarily. |

---

## 16. ADR index

Ten ADRs cover every cross-service contract. Read in order on first
onboarding; reference by number when a question crosses services.

| ADR | Topic | Status |
|---|---|---|
| [0001](adr/0001-jwt-format.md) | JWT format (user + service, RS256, JWKS) | Accepted |
| [0002](adr/0002-event-envelope.md) | Event envelope + routing key grammar + idempotency | Accepted |
| [0003](adr/0003-correlation-id.md) | Correlation ID propagation (HTTP + AMQP + logs) | Accepted |
| [0004](adr/0004-rest-envelope-and-errors.md) | REST `{data, meta}` + RFC 7807 errors | Accepted |
| [0005](adr/0005-service-to-service-auth.md) | Service-to-service auth flow (JWKS, rotation) | Accepted |
| [0006](adr/0006-notifications-contract.md) | Notifications service contract (REST + WS) | Accepted |
| [0007](adr/0007-search-and-command-registry.md) | Cmd+K per-service registry; defer cross-app FT | Accepted |
| [0008](adr/0008-broadcasting-auth.md) | `/broadcasting/auth` (cookie → JWT → Reverb) | Accepted |
| [0009](adr/0009-user-preferences.md) | Versioned preferences blob in Auth | Accepted |
| [0010](adr/0010-auth-me-and-apps-endpoints.md) | `/api/v1/auth/{user,apps}` schemas + filtering | Accepted |

---

## 17. Versioning & evolution

The SDK follows [SemVer](https://semver.org/) with one additional rule:
**routing keys evolve additively**.

- **PATCH** — bug fixes, log changes, internal refactors. Safe to take
  immediately platform-wide.
- **MINOR** — new exports, new ADRs, additive payload fields. Roll out
  Auth first, then a canary service, then bake one week, then everywhere.
- **MAJOR** — breaking changes. Rare; coordinated rollout in lockstep.

Event payloads may add fields freely within a major (consumers must
ignore unknown fields — `additionalProperties: true` in schemas where
appropriate). Breaking a payload requires either:

1. A new routing key suffix: `crm.contact.created.v2`, OR
2. Bumping the envelope `version` field with consumer-side branching.

Compatibility window: SDK 1.x supports N and N-1 of itself. A platform
running SDK 1.5 may have services on 1.4 and 1.5 simultaneously; once all
are 1.5, the SDK proper can target 1.6.

`CHANGELOG.md` enumerates each release; the SDK does not yet ship to
Packagist (private GitHub Packages registry only).

---

## 18. What this document does not cover

Out of scope, by deliberate choice:

- **Per-service implementation details** — those live in each service's
  repo. The SDK is the *contract*; the services are the *bodies*.
- **Helm charts, Dockerfiles, CI pipelines** — `abeon-infra/` repo
  (planned).
- **Frontend chrome implementation** — `@abeon/shared` and `@abeon/ui` in
  sibling repos.
- **Business-domain event catalogues** — each service ships its own JSON
  schemas; the platform-wide catalogue is `docs/events-catalog.md` plus
  the federation mechanism.
- **Threat model / security audit** — separate document (Phase 2).

If you need any of these and they don't exist yet, that's a Phase 1+
candidate — file an issue against `abeon-suit` referencing this section.
