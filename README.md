# abeon/sdk

Shared SDK for Abeon Unified microservices — PHP / Laravel side.

Each microservice in the Abeon Unified platform installs `abeon/sdk` to get
a consistent contract for cross-service communication, identity,
observability, and operational concerns.

---

## Scope

### What this SDK provides

- **Auth** — JWT validator (RS256 + JWKS), `AuthMiddleware`, request-scoped `AuthContext`, Laravel Gate bridge.
- **Service-to-service HTTP** — config-driven `ServiceClient` (`$client->service('crm')->get(...)`), service-JWT auto-issuance with in-process caching, correlation header propagation, RFC 7807 errors lifted into typed exceptions.
- **Events (RabbitMQ)** — `EventPublisher` writes to outbox in the business transaction; `OutboxDrainer` worker drains to RabbitMQ asynchronously; `EventConsumer` with idempotency via `abeon_processed_events`, topic wildcards, DLX wiring, and version gating (major 1).
- **Transactional e-mail** — `Messages::send(MessageRequest)` publishes `{service}.message.requested`; AbeonUnified renders the template and delivers it (ADR-0030). No mail credentials in any service.
- **Correlation ID** — `X-Correlation-ID` end-to-end across HTTP and events.
- **Health checks** — `/health` (liveness) + `/health/ready` (readiness) with pluggable `Check` interface. Built-ins: db, rabbitmq, jwks, outbox_lag.
- **Errors** — RFC 7807 `application/problem+json` renderer, typed `AbeonException` hierarchy.
- **API response helpers** — `{data, meta}` envelope, paginated responses.
- **List queries** — `QueryParser` for `filter[field]`, `sort=-a,b`, `page`, `per_page` with per-endpoint allowlists (ADR-0004).
- **API versioning** — `VersionHeadersMiddleware` for `X-API-Version` + optional `Deprecation` / `Sunset`.
- **Schema federation** — services declare their event payload schemas via composer metadata; SDK discovers and aggregates at runtime.
- **JSON logging** — Loki-friendly structured logs with `correlation_id` + `service` fields (scaffold, full Monolog integration in v0.2).
- **In-memory event publisher** — test double, shipping artifact.

### What this SDK does NOT provide

- Business logic — that's the consumer service's responsibility.
- Auth service implementation — see `abeon-auth` (Faza 1).
- AbeonUnified implementation — notifications, app registry, org↔app assignment; see `abeon-unified` (Faza 1, ADR-0019).
- Frontend code — see `@abeon/sdk-ts`, the frontend half of the contract (sibling repo `abeon-sdk-ts/`). Backend capabilities exist only here (ADR-0029).
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
            Contact::where('org_id', app(AuthContext::class)->requireOrgId())->paginate(25)
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

### Notify a user

```php
DB::transaction(function () use ($deal, $notifier) {
    $deal->assignTo($user);
    $notifier->notify(new NotificationRequest(
        userId: $user->id,
        type: 'crm.deal.assigned',
        title: 'Przypisano Ci szansę sprzedaży',
        body: $deal->name,
        actionUrl: "/crm/deals/{$deal->id}",
        channels: [NotificationChannel::InApp, NotificationChannel::Email],
    ));
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

### Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `ABEON_SERVICE_NAME` | — (required) | This service's identifier. Used in JWT `iss`/`service_name`, routing keys, logs. |
| `ABEON_AUTH_URL` | `http://auth-service.abeon.svc.cluster.local` | Auth service base URL (cluster-internal). |
| `ABEON_AUTH_JWKS_URL` | derived | JWKS endpoint. Default `${auth.url}/.well-known/jwks.json`. |
| `ABEON_AUTH_ISSUER` | `abeon-auth` | Expected `iss` claim on user JWTs. |
| `ABEON_AUTH_AUDIENCE` | `abeon` | Expected `aud` claim. |
| `ABEON_SERVICE_JWT_PRIVATE_KEY` | — | PEM-encoded RS256 private key. K8s Secret recommended. |
| `ABEON_SERVICE_JWT_KID` | — | Key ID matching this service's entry in Auth JWKS. |
| `ABEON_RABBITMQ_DSN` | — | `amqp://user:pass@host:port/vhost`. |
| `ABEON_RABBITMQ_EXCHANGE` | `abeon.events` | Topic exchange name. |
| `ABEON_RABBITMQ_DLX` | `abeon.events.dlx` | Dead-letter exchange. |
| `ABEON_OUTBOX_RETENTION_DAYS` | `30` | How long `abeon:events:prune` keeps a processed outbox row or a recorded processed event. |
| `ABEON_CONSUMER_SLOW_HANDLER_SECONDS` | `5` | A handler slower than this is logged. Nothing is interrupted. |
| `ABEON_TRUSTED_PROXIES` | `*` | Which proxies this service believes about `X-Forwarded-*`. Pin it to the ingress CIDR where the network does not already guarantee it — every per-address guard reads the result. |
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
| `abeon:events:outbox-drain` | Long-running worker draining `abeon_event_outbox` to RabbitMQ. `--once` for a single pass. |
| `abeon:events:consume` | Long-running consumer dispatching to tagged `abeon.event_handler` services. |
| `abeon:events:dlq` | Counts the events this service dead-lettered; `--replay` republishes them once the handler is fixed. |
| `abeon:events:prune` | Deletes processed outbox rows and processed-event records past `ABEON_OUTBOX_RETENTION_DAYS`. Schedule it daily. |

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

### This repository's own tests

The database tests run against **MariaDB 11.8**, the production engine — not SQLite. Start the
suite-wide container with `./db-up.sh` in the `abeon-suit` root (database `abeon_sdk_test`, user
`abeon_sdk`), or point `ABEON_TEST_DB_HOST`, `_PORT`, `_DATABASE`, `_USERNAME` and `_PASSWORD` at
another server. Each test empties the database before it starts. PHP needs `pdo_mysql`:

```bash
docker run --rm --network host -v "$PWD":/app -w /app abeon-php:8.4 vendor/bin/phpunit
```

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
