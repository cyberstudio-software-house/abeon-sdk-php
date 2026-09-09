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
- AbeonUnified implementation — notifications, app registry, org↔app assignment; see `abeon-unified` (Faza 1, ADR-0019).
- Frontend code — see `@abeon/sdk-ts` (TypeScript counterpart in sibling repo `abeon-sdk-ts/`).
- Boilerplate Laravel application — separate workstream.
- @abeon/ui design system — separate workstream (existing `abeon-ui` repo).

---

## Installation

This package is **not on packagist.org**. Add the repository, then require a released version:

```jsonc
// composer.json
"repositories": [
  { "type": "vcs", "url": "https://github.com/cyberstudio-software-house/abeon-sdk-php.git" }
],
"require": { "abeon/sdk": "^0.3.0" }
```

```bash
composer install --ignore-platform-req=ext-sockets
```

The repository is public, so this needs no credentials. The flag is needed because neither the
`composer:2` nor the `php:8.4-cli` image ships `ext-sockets`, which `php-amqplib` declares —
**the runtime image must install it** (`docker-php-ext-install sockets`); the event consumer needs
it at run time.

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

> **`EventHandler` implementations must be idempotent.** Replaying a handler with the same `Event` must produce the same state — no double emails, no double-charged invoices, no incremented counters that move twice. See [ADR-0002](docs/adr/0002-event-envelope.md#consume-flow--idempotency) for the rationale and concrete patterns.

### Scope a model to an organisation

```php
class Invoice extends Model
{
    use Abeon\SDK\Tenancy\BelongsToTenant;
}

// Migration — NOT NULL and indexed. Both matter, see below.
$table->unsignedBigInteger('org_id')->index();
```

Reads are constrained to the current organisation and inserts are stamped with it, so application code
never writes `org_id` by hand:

```php
Invoice::all();                              // only this organisation's rows
Invoice::create(['number' => 'FV/1']);       // org_id stamped automatically
Invoice::withoutTenantScope(fn () => …);     // the one sanctioned way to read across organisations
```

**A missing tenant throws** (`AuthException`, 403) rather than returning every organisation's rows. That
rule matters more than the mechanism: any scoping can be bypassed, so what makes the system safe is that
the unsafe state is loud — in development, on the first query.

In an HTTP request the organisation follows from the authenticated user with no wiring. Everywhere else
there is no auth context to fall back on, so enter it explicitly:

```php
// In an event handler — the tenant comes from the envelope (ADR-0002), because
// EventConsumer does not populate AuthContext.
$tenants->runFor($event->orgId, fn () => $this->process($event));
```

> **Known limits, by design.** The scope does not reach `DB::table()`, raw SQL or query-builder joins;
> migrations, seeders and console commands run tenant-less; and `saveQuietly()` suppresses the stamp —
> which is why the column must be **NOT NULL**, so the database is the backstop. See
> [ADR-0018](docs/adr/0018-tenant-scoping.md) for the full list and the reasoning.

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
│  Tenancy (TenantContext, Scope, trait)       │  ← organisation isolation
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

The TypeScript counterpart `@abeon/sdk-ts` lives in a separate repository (`abeon-sdk-ts/`).

---

## Documentation

| Document | Purpose |
|---|---|
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Comprehensive architectural reference — layers, contracts, lifecycle, extension points |
| [`docs/usage.md`](docs/usage.md) | Getting-started walkthrough + cookbook + troubleshooting |
| [`docs/events-catalog.md`](docs/events-catalog.md) | How services declare and discover event schemas (federation) |
| [`docs/adr/`](docs/adr/README.md) | **27 decision records**, indexed. The four that everything else rests on: [0001](docs/adr/0001-jwt-format.md) JWT format · [0002](docs/adr/0002-event-envelope.md) event envelope · [0004](docs/adr/0004-rest-envelope-and-errors.md) REST envelope and RFC 7807 errors · [0005](docs/adr/0005-service-to-service-auth.md) service-to-service authentication |

Where an ADR and this code disagree, the ADR is the intent and the code is the bug.

Higher-level project docs (Phase 0 plan, architecture):

- `../abeon-unified-architecture.md` — overall platform architecture
- `../abeon-sdk-phase0-plan.md` — detailed Phase 0 SDK plan
- `../abeon-shared-phase0-plan.md` — the TypeScript counterpart plan. It keeps that filename on
  purpose: the package was called `@abeon/shared` when it was written, and is `@abeon/sdk-ts` now
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
| `abeon:config:validate` | Smoke-test required SDK config keys. Flags: `--require-jwt-key`, `--require-rabbitmq`. Run as CI gate or post-deploy. |

The two long-running workers handle `SIGTERM` / `SIGINT` cleanly via `pcntl_signal`.

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
- `php` ^8.4
- `firebase/php-jwt` ^7.0 — JWT sign + verify (JWKS). 6.x is not an option: every stable
  release of it is subject to CVE-2025-45769 and Composer refuses to install one.
- `php-amqplib/php-amqplib` ^3.7 — RabbitMQ AMQP client
- `illuminate/{contracts,support,console,http,database}` ^11.0|^12.0 — Laravel framework

Dev:
- `phpunit/phpunit` ^11.0
- `phpstan/phpstan` ^1.11
- `orchestra/testbench` ^10.0 — Laravel 12, the same major every consumer runs

---

## Status

**Released and in use.** `v0.3.0` is the current tag; four applications consume it —
`abeon-auth`, `abeon-auth/auth-ui`, `abeon-unified` and `abeon-boilerplate-inertia` — each through
the `vcs` repository above rather than a path to a sibling directory, so each of them installs on
its own.

Not published to any registry. It does not need to be: Composer resolves a tag straight from the
public repository, and the one registry the platform has available requires a token even for public
packages.

## License

Proprietary — internal to the Abeon Unified platform.
