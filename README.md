# abeon/sdk

Shared SDK for Abeon Unified microservices — PHP / Laravel side.

Each microservice in the Abeon Unified platform installs `abeon/sdk` to get
a consistent contract for cross-service communication, identity,
observability, and operational concerns.

---

## Scope

### What this SDK provides

- **Auth** — JWT validator (RS256 + JWKS), `AuthMiddleware`, request-scoped `AuthContext`, Laravel Gate bridge, `abeon_user()` global helper.
- **Service-to-service HTTP** — config-driven `ServiceClient` (`$client->service('crm')->get(...)`), service-JWT auto-issuance with in-process caching, correlation header propagation, RFC 7807 errors lifted into typed exceptions.
- **Events (RabbitMQ)** — `EventPublisher` writes to outbox in the business transaction; `OutboxDrainer` worker drains to RabbitMQ asynchronously; `EventConsumer` with idempotency via `abeon_processed_events`, topic wildcards, DLX wiring.
- **Service registry + self-registration** — `ServiceRegistry::register()` POSTs the service's `AppDescriptor` to Auth on demand (Artisan command).
- **Permissions federation** — service declares its permissions in config; `abeon:permissions:declare` publishes `service.permissions.declared` to Auth.
- **Correlation ID** — `X-Correlation-ID` end-to-end across HTTP and events.
- **Health checks** — `/health` (liveness) + `/health/ready` (readiness) with pluggable `Check` interface. Built-ins: db, rabbitmq, jwks, outbox_lag.
- **Errors** — RFC 7807 `application/problem+json` renderer, typed `AbeonException` hierarchy.
- **API response helpers** — `{data, meta}` envelope, paginated responses.
- **API versioning** — `VersionHeadersMiddleware` for `X-API-Version` + optional `Deprecation` / `Sunset`.
- **Schema federation** — services declare their event payload schemas via composer metadata; SDK discovers and aggregates at runtime.
- **JSON logging** — Loki-friendly structured logs with `correlation_id` + `service` fields (scaffold, full Monolog integration in v0.2).
- **In-memory event publisher** — test double, shipping artifact.

### What this SDK does NOT provide

- Business logic — that's the consumer service's responsibility.
- Auth service implementation — see `abeon-auth` (Faza 1).
- Notifications service implementation — see `abeon-notifications` (Faza 1).
- Frontend code — see `@abeon/shared` (TypeScript counterpart in sibling repo `abeon-shared/`).
- Boilerplate Laravel application — separate workstream.
- @abeon/ui design system — separate workstream (existing `abeon-ui` repo).

---

## Installation

```bash
composer require abeon/sdk
```

Laravel's package auto-discovery picks up `Abeon\SDK\AbeonServiceProvider`. No manual provider registration needed.

```bash
php artisan vendor:publish --tag=abeon-config
php artisan migrate    # abeon_event_outbox + abeon_processed_events
```

Set the required env vars (see [`docs/usage.md`](docs/usage.md#3-set-environment-variables) for the full list):

```dotenv
ABEON_SERVICE_NAME=crm
ABEON_AUTH_URL=http://auth-service.abeon.svc.cluster.local
ABEON_RABBITMQ_DSN=amqp://user:pass@rabbitmq:5672/abeon
# For service-to-service calls:
ABEON_SERVICE_JWT_PRIVATE_KEY="..."
ABEON_SERVICE_JWT_KID=crm-2026-05
```

Full walkthrough: [`docs/usage.md`](docs/usage.md).

---

## Quick examples

### Protect a route with JWT

```php
use Abeon\SDK\Http\ApiResponse;

Route::middleware(['abeon.auth', 'abeon.version:v1'])
    ->prefix('api/v1')
    ->group(function () {
        Route::get('/contacts', fn () => ApiResponse::paginated(
            Contact::where('org_id', abeon_user()->orgId)->paginate(25)
        ));
    });
```

### Call another service

```php
$response = $client->service('finance')->get('/api/v1/invoices', ['contact_id' => 42]);
// Throws Abeon\SDK\Client\ServiceCallException with typed RFC 7807 on non-2xx.
```

### Publish an event (outbox-backed)

```php
DB::transaction(function () use ($data, $publisher) {
    $contact = Contact::create($data);
    $publisher->publish('crm.contact.created', [
        'contact_id' => $contact->id,
        'email'      => $contact->email,
    ]);
});
```

### Consume an event

```php
class OnContactCreated implements EventHandler
{
    public function subscribesTo(): array { return ['crm.contact.created']; }
    public function handle(Event $event): void { /* ... */ }
}

// Tag in AppServiceProvider::register()
$this->app->tag([OnContactCreated::class], 'abeon.event_handler');
```

Run: `php artisan abeon:events:outbox-drain` and `php artisan abeon:events:consume`.

---

## Architecture — internal layering

```
┌──────────────────────────────────────────────┐
│  Services (ServiceRegistry, AppRegistry)     │  ← discovery + self-registration
├──────────────────────────────────────────────┤
│  Client (ServiceClient, ServiceTokenProvider)│  ← sync communication
├──────────────────────────────────────────────┤
│  Events (Publisher, Consumer, OutboxDrainer) │  ← async communication
├──────────────────────────────────────────────┤
│  Auth (JwtValidator, AuthMiddleware, Gate)   │  ← identity
├──────────────────────────────────────────────┤
│  Core (Config, DTO, Health, Errors, Logging) │  ← fundament, no external deps
└──────────────────────────────────────────────┘
```

Each higher layer may depend on lower layers, **never the reverse**. This makes a future subtree split (e.g., extracting `abeon/auth-middleware` as a standalone package for frontend boilerplate) mechanical rather than an archaeology project.

---

## Naming

- **Folder name:** `abeon-sdk-php` — the `-php` stack suffix leaves room for a parallel `abeon-sdk-ts` (or similar) for a future TypeScript counterpart variant.
- **Composer package name:** `abeon/sdk` — stable, matches the architecture document. Folder name and package name are intentionally decoupled.

The TypeScript counterpart `@abeon/shared` lives in a separate repository (`abeon-shared/`).

---

## Documentation

| Document | Purpose |
|---|---|
| [`docs/usage.md`](docs/usage.md) | Getting-started walkthrough + cookbook + troubleshooting |
| [`docs/events-catalog.md`](docs/events-catalog.md) | How services declare and discover event schemas (federation) |
| [`docs/adr/0001-jwt-format.md`](docs/adr/0001-jwt-format.md) | JWT format (user + service tokens) |
| [`docs/adr/0002-event-envelope.md`](docs/adr/0002-event-envelope.md) | Event envelope contract |
| [`docs/adr/0003-correlation-id.md`](docs/adr/0003-correlation-id.md) | Correlation ID propagation |
| [`docs/adr/0004-rest-envelope-and-errors.md`](docs/adr/0004-rest-envelope-and-errors.md) | REST `{data, meta}` envelope + RFC 7807 errors |
| [`docs/adr/0005-service-to-service-auth.md`](docs/adr/0005-service-to-service-auth.md) | Service-to-service authentication |

Higher-level project docs (Phase 0 plan, architecture):

- `../abeon-unified-architecture.md` — overall platform architecture
- `../abeon-sdk-phase0-plan.md` — detailed Phase 0 SDK plan
- `../abeon-shared-phase0-plan.md` — TypeScript counterpart plan
- `../abeon-phase0-summary.md` — consolidated decisions snapshot

---

## Configuration reference

### Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `ABEON_SERVICE_NAME` | — (required) | This service's identifier. Used in JWT `iss`/`service_name`, routing keys, logs. |
| `ABEON_AUTH_URL` | `http://auth-service.abeon.svc.cluster.local` | Auth service base URL (cluster-internal). |
| `ABEON_AUTH_JWKS_URL` | derived | JWKS endpoint. Default `${auth.url}/.well-known/jwks.json`. |
| `ABEON_AUTH_ISSUER` | `abeon-auth` | Expected `iss` claim on user JWTs. |
| `ABEON_AUTH_AUDIENCE` | `abeon` | Expected `aud` claim. |
| `ABEON_JWT_COOKIE_NAME` | `abeon_token` | Canonical access-token cookie. Frontends read; backend ignores cookies. |
| `ABEON_REFRESH_COOKIE_NAME` | `abeon_refresh` | Canonical refresh-token cookie. |
| `ABEON_SERVICE_JWT_PRIVATE_KEY` | — | PEM-encoded RS256 private key. K8s Secret recommended. |
| `ABEON_SERVICE_JWT_KID` | — | Key ID matching this service's entry in Auth JWKS. |
| `ABEON_RABBITMQ_DSN` | — | `amqp://user:pass@host:port/vhost`. |
| `ABEON_RABBITMQ_EXCHANGE` | `abeon.events` | Topic exchange name. |
| `ABEON_RABBITMQ_DLX` | `abeon.events.dlx` | Dead-letter exchange. |
| `ABEON_HEALTH_CHECKS` | `db` | Comma list of checks for `/health/ready`. |
| `ABEON_OUTBOX_BATCH_SIZE` | `100` | Max rows fetched per drainer pass. |
| `ABEON_OUTBOX_POLL_INTERVAL` | `1` | Seconds drainer sleeps when outbox is empty. |
| `ABEON_OUTBOX_MAX_ATTEMPTS` | `5` | Before row is permanently failed. |
| `ABEON_OUTBOX_LAG_THRESHOLD` | `60` | Health check degrades after this many seconds of unprocessed lag. |
| `ABEON_CONSUMER_PREFETCH` | `10` | RabbitMQ prefetch count for the consumer. |
| `ABEON_QUEUE_PREFIX` | `${service.name}` | Prefix for consumer queue names. |
| `ABEON_LOG_CORRELATION_FIELD` | `correlation_id` | Log field name. |

### Config file keys (beyond env)

After `vendor:publish --tag=abeon-config`:

- `services` — map of service-name → `{url}` for `ServiceClient::service($name)`.
- `permissions` — list of `{app}.{resource}.{action}` strings this service declares.
- `app_descriptor` — self-registration metadata (`name`, `label`, `path`, `icon`, `version`).
- `events.consumer.subscriptions` — explicit routing keys this service consumes (otherwise inferred from `EventHandler::subscribesTo()`).

---

## Artisan commands

| Command | Purpose |
|---|---|
| `abeon:registry:register` | POSTs `AppDescriptor` to Auth's service registry (idempotent). Run as post-deploy step. |
| `abeon:permissions:declare` | Publishes `service.permissions.declared` event so Auth updates the RBAC catalog. |
| `abeon:events:outbox-drain` | Long-running worker draining `abeon_event_outbox` to RabbitMQ. `--once` for a single pass. |
| `abeon:events:consume` | Long-running consumer dispatching to tagged `abeon.event_handler` services. |

All four handle `SIGTERM` / `SIGINT` cleanly via `pcntl_signal`.

---

## Middleware aliases

| Alias | Class | Purpose |
|---|---|---|
| `abeon.correlation` | `Abeon\SDK\Http\CorrelationIdMiddleware` | Reads/generates `X-Correlation-ID`, propagates. |
| `abeon.auth` | `Abeon\SDK\Auth\AuthMiddleware` | Validates `Authorization: Bearer <jwt>`, populates `AuthContext`. |
| `abeon.version` | `Abeon\SDK\Http\VersionHeadersMiddleware` | Tags response with `X-API-Version` (+ optional `Deprecation`/`Sunset`). |

---

## Versioning

`abeon/sdk` follows [SemVer](https://semver.org/).

- **MAJOR.MINOR.PATCH** — breaking changes bump MAJOR; additive features bump MINOR; bug fixes bump PATCH.
- **Compatibility window:** SDK 1.x supports N and N-1 of itself. Event schemas may only evolve additively within a major version. Breaking payload changes require either a new routing key suffix (`crm.contact.created.v2`) or a bump of the envelope `version` field with consumer branching.
- **Phased upgrade:** new minor → first to Auth + one non-critical service → bake for a week → rest of the platform.
- **Composer constraint:** `"abeon/sdk": "^1.0"` in consuming services. `composer.lock` committed.
- **Compatibility matrix:** maintained in [`CHANGELOG.md`](CHANGELOG.md) (per-release notes).

---

## Testing the SDK in your service

Unit tests can swap the real publisher with `InMemoryEventPublisher`:

```php
use Abeon\SDK\Events\{EventPublisher, InMemoryEventPublisher, EnvelopeBuilder};

$this->app->instance(
    EventPublisher::class,
    new InMemoryEventPublisher($this->app->make(EnvelopeBuilder::class))
);

// In test:
$publisher = app(EventPublisher::class);
expect($publisher->publishedForRoutingKey('crm.contact.created'))->toHaveCount(1);
```

For integration tests with real RabbitMQ + DB, the SDK's `tests/integration/` (planned) provides a `docker-compose.yml` with RabbitMQ + MariaDB.

---

## Dependencies

Runtime:
- `php` ^8.3
- `firebase/php-jwt` ^6.10 — JWT sign + verify (JWKS)
- `php-amqplib/php-amqplib` ^3.7 — RabbitMQ AMQP client
- `illuminate/{contracts,support,console,http,database}` ^11.0|^12.0 — Laravel framework

Dev:
- `phpunit/phpunit` ^11.0
- `phpstan/phpstan` ^1.11
- `orchestra/testbench` ^9.0

---

## Status

**Phase 0 implementation in progress.** SDK Sprint 0-2 complete; Sprint 3 (this current work — VersionHeadersMiddleware + ADRs + docs) wraps up the PHP side.

This is a private platform package — published to GitHub Packages, not to packagist.org.

## License

Proprietary — internal to the Abeon Unified platform.
