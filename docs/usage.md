# Usage guide

This document walks through wiring `abeon/sdk` into a Laravel service from `composer require` to first request and first event. Read [`adr/`](./adr/) for *why* the design is the way it is; this document covers *how* to use it.

## Prerequisites

- PHP 8.3+
- Laravel 11 or 12
- Access to `app.abeon.pl` Auth service (or running locally)
- Access to RabbitMQ (if the service publishes/consumes events)
- A K8s Secret with this service's RS256 private key (for outbound s2s calls)

## 1. Install

```bash
composer require abeon/sdk
```

Laravel's package auto-discovery picks up `Abeon\SDK\AbeonServiceProvider`. Nothing else to register.

## 2. Publish config

```bash
php artisan vendor:publish --tag=abeon-config
php artisan vendor:publish --tag=abeon-migrations  # optional; migrations are also autoloaded
php artisan migrate                                 # creates abeon_event_outbox + abeon_processed_events
```

The published `config/abeon.php` is a fully-commented stub — edit it to your service's needs, or set the env vars below.

## 3. Set environment variables

Minimum required:

```dotenv
ABEON_SERVICE_NAME=crm

ABEON_AUTH_URL=http://auth-service.abeon.svc.cluster.local
# ABEON_AUTH_JWKS_URL is derived from ABEON_AUTH_URL by default

# For outbound service-to-service calls (any service that uses ServiceClient):
ABEON_SERVICE_JWT_PRIVATE_KEY="-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
ABEON_SERVICE_JWT_KID=crm-2026-05

# For event publish/consume:
ABEON_RABBITMQ_DSN=amqp://user:pass@rabbitmq.abeon-infra.svc.cluster.local:5672/abeon
```

Optional:

```dotenv
ABEON_AUTH_ISSUER=abeon-auth      # default
ABEON_AUTH_AUDIENCE=abeon         # default
ABEON_RABBITMQ_EXCHANGE=abeon.events     # default
ABEON_RABBITMQ_DLX=abeon.events.dlx      # default
ABEON_HEALTH_CHECKS=db,rabbitmq,jwks     # which checks the /health/ready endpoint runs
ABEON_OUTBOX_POLL_INTERVAL=1             # seconds between drainer polls
ABEON_OUTBOX_MAX_ATTEMPTS=5              # before row is marked failed
ABEON_OUTBOX_LAG_THRESHOLD=60            # health degrades after this many seconds of lag
ABEON_JWT_COOKIE_NAME=abeon_token        # canonical cookie name (frontend reads)
ABEON_REFRESH_COOKIE_NAME=abeon_refresh
```

Plus the **service registry** (which other services THIS one calls):

```php
// config/abeon.php
return [
    // ...
    'services' => [
        'auth'    => ['url' => 'http://auth-service.abeon.svc.cluster.local'],
        'finance' => ['url' => 'http://finance-service.abeon.svc.cluster.local'],
        // Only the ones this service actually uses.
    ],

    // RBAC permissions THIS service exposes:
    'permissions' => [
        'crm.contacts.read',
        'crm.contacts.write',
        'crm.deals.manage',
    ],

    // For self-registration in Auth:
    'app_descriptor' => [
        'name'  => env('ABEON_SERVICE_NAME'),
        'label' => 'CRM',
        'path'  => '/crm',
        'icon'  => 'users',
    ],
];
```

## 4. Wire middleware

Edit `bootstrap/app.php`:

```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web:     __DIR__.'/../routes/web.php',
        api:     __DIR__.'/../routes/api.php',
        commands:__DIR__.'/../routes/console.php',
        health:  '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Apply correlation ID to every API request
        $middleware->api(prepend: [
            \Abeon\SDK\Http\CorrelationIdMiddleware::class,
        ]);
    })
    ->create();
```

Per-route middleware aliases provided by the SDK:

| Alias | What it does |
|---|---|
| `abeon.correlation` | Reads / generates `X-Correlation-ID`; auto-applied above if you put it in the API group. |
| `abeon.auth` | Validates `Authorization: Bearer <jwt>` and populates `AuthContext` with the user. |
| `abeon.version` | Tags response with `X-API-Version` (and optional `Deprecation` / `Sunset`). Args: `version[,sunset_date]`. |

## 5. Protect an API route

```php
// routes/api.php
use Abeon\SDK\Http\ApiResponse;

Route::middleware(['abeon.auth', 'abeon.version:v1'])
    ->prefix('api/v1')
    ->group(function () {
        Route::get('/contacts', function () {
            $user = abeon_user(); // or auth()->user()
            $contacts = Contact::where('org_id', $user->orgId)->paginate(25);
            return ApiResponse::paginated($contacts);
        });
    });
```

Permission check via Laravel's Gate works out of the box — `PermissionsServiceProvider` registers a `Gate::before` hook that resolves any ability against the JWT's `permissions` array:

```php
if (! \Illuminate\Support\Facades\Gate::allows('crm.contacts.write')) {
    throw \Abeon\SDK\Exceptions\AuthException::forbidden();
}
```

## 6. Call another service

```php
use Abeon\SDK\Client\ServiceClient;
use Abeon\SDK\Client\ServiceCallException;

class FinanceLookup
{
    public function __construct(private readonly ServiceClient $client) {}

    public function invoicesForContact(int $contactId): array
    {
        try {
            $response = $this->client
                ->service('finance')
                ->get('/api/v1/invoices', ['contact_id' => $contactId]);
            return $response->json('data', []);
        } catch (ServiceCallException $e) {
            // $e->problem is a Abeon\SDK\DTO\ProblemDetails — typed RFC 7807
            \Log::warning('finance lookup failed', [
                'status' => $e->problem->status,
                'type'   => $e->problem->type,
            ]);
            return [];
        }
    }
}
```

The client auto-injects `Authorization: Bearer <service-jwt>` and `X-Correlation-ID`. Non-2xx responses become `ServiceCallException` with the upstream Problem Details preserved.

## 7. Publish an event

```php
use Abeon\SDK\Events\EventPublisher;
use Illuminate\Support\Facades\DB;

class CreateContact
{
    public function __construct(private readonly EventPublisher $publisher) {}

    public function execute(array $data): Contact
    {
        return DB::transaction(function () use ($data) {
            $contact = Contact::create($data);
            // Envelope built automatically; written to abeon_event_outbox.
            // Drainer pushes to RabbitMQ asynchronously.
            $this->publisher->publish('crm.contact.created', [
                'contact_id' => $contact->id,
                'email'      => $contact->email,
                'created_by' => abeon_user()->id,
            ]);
            return $contact;
        });
    }
}
```

The publish call is **atomic with the business write** because it goes through the outbox table — no risk of "Contact created but event missing" on crash.

Run the drainer as a long-running process (e.g., K8s `Deployment` with one replica, or via `Supervisor`):

```bash
php artisan abeon:events:outbox-drain
```

## 8. Consume an event

Implement `EventHandler` and tag it:

```php
namespace App\Events\Handlers;

use Abeon\SDK\Events\Event;
use Abeon\SDK\Events\EventHandler;

class OnContactCreated implements EventHandler
{
    public function subscribesTo(): array
    {
        return ['crm.contact.created'];  // exact key
        // OR with wildcards (topic-style):
        // return ['crm.contact.*', 'crm.deal.#'];
    }

    public function handle(Event $event): void
    {
        // Idempotency is enforced by SDK via abeon_processed_events table.
        // CorrelationContext is already populated from envelope.metadata.correlation_id.
        \Log::info('contact created', $event->data);
        // Do your thing...
    }
}
```

Tag handlers in your `AppServiceProvider::register()`:

```php
public function register(): void
{
    $this->app->tag([
        \App\Events\Handlers\OnContactCreated::class,
        \App\Events\Handlers\OnDealWon::class,
    ], 'abeon.event_handler');
}
```

Run the consumer:

```bash
php artisan abeon:events:consume
```

The consumer auto-declares per-subscription queues (`{service-name}.{routing-key}`) with DLQ wired through `abeon.events.dlx`.

## 9. Self-register and declare permissions

After deploy, run (one-shot or in your CI post-deploy step):

```bash
php artisan abeon:registry:register     # POSTs AppDescriptor to Auth
php artisan abeon:permissions:declare    # publishes service.permissions.declared event
```

Both are idempotent. Auth deduplicates by service name.

## 10. K8s probes

Routes are auto-registered:

- `GET /health` — liveness (always 200 if the process is up).
- `GET /health/ready` — readiness. Runs configured checks (`ABEON_HEALTH_CHECKS`). Returns 200 with `{status: "ok"|"degraded", checks: {...}}` or 503 with `{status: "down", ...}`.

Built-in checks (registered via `abeon.health.check.{name}` container bindings):

- `db` — runs `SELECT 1` on the default connection.
- `rabbitmq` — opens a probe AMQP connection.
- `jwks` — fetches the JWKS endpoint.
- `outbox_lag` — `degraded` when the oldest unprocessed outbox row is older than `ABEON_OUTBOX_LAG_THRESHOLD` seconds.

Add a custom check:

```php
// AppServiceProvider::register()
$this->app->bind('abeon.health.check.elasticsearch', function ($app) {
    return new ElasticsearchHealthCheck($app->make(EsClient::class));
});
```

Then set `ABEON_HEALTH_CHECKS=db,rabbitmq,jwks,elasticsearch`.

## 11. Testing your service in isolation

For unit tests, swap the real publisher with the in-memory test double:

```php
use Abeon\SDK\Events\EventPublisher;
use Abeon\SDK\Events\InMemoryEventPublisher;
use Abeon\SDK\Events\EnvelopeBuilder;

beforeEach(function () {
    $this->app->instance(
        EventPublisher::class,
        new InMemoryEventPublisher($this->app->make(EnvelopeBuilder::class))
    );
});

it('publishes contact.created on create', function () {
    $publisher = app(EventPublisher::class);
    expect($publisher)->toBeInstanceOf(InMemoryEventPublisher::class);

    (new CreateContact($publisher))->execute(['email' => 'jan@example.com']);

    $events = $publisher->publishedForRoutingKey('crm.contact.created');
    expect($events)->toHaveCount(1);
    expect($events[0]['data']['email'])->toBe('jan@example.com');
});
```

For tests that need a real DB but no RabbitMQ, the publisher writes to `abeon_event_outbox` and your assertion is on the table contents rather than RabbitMQ.

## Cookbook

### Forwarding correlation across `dispatch()` and `Bus::chain()`

The SDK propagates correlation through HTTP and events automatically. For Laravel jobs dispatched into the queue, you need to capture the current correlation ID and re-set it inside the job:

```php
class SendWelcomeEmail implements ShouldQueue
{
    public function __construct(public readonly string $correlationId, ...) {}

    public function handle(\Abeon\SDK\Logging\CorrelationContext $ctx): void
    {
        $ctx->set($this->correlationId);
        // ...
    }
}

// Dispatcher
SendWelcomeEmail::dispatch(abeon_correlation_id() ?? '', ...);
```

A future SDK helper may automate this; for now it's manual.

### Disabling autoload of migrations

If you want to manage migrations yourself (rare):

```php
// AppServiceProvider::register()
$this->app['migrator']->path('/path/to/your/migrations/instead');
// And avoid `vendor:publish --tag=abeon-migrations`
```

Migrations are auto-loaded by SDK service provider but they're additive (new table names with `abeon_` prefix), so collision with your own migrations is unlikely.

### Running drainer + consumer in K8s

Two deployments, one replica each (multi-replica drainer would race; consumer can scale but messages would be load-balanced):

```yaml
# outbox-drainer Deployment
spec:
  replicas: 1
  template:
    spec:
      containers:
        - command: ["php", "artisan", "abeon:events:outbox-drain"]
```

```yaml
# events-consumer Deployment
spec:
  replicas: 1  # or more; RabbitMQ load-balances messages
  template:
    spec:
      containers:
        - command: ["php", "artisan", "abeon:events:consume"]
```

Both handle `SIGTERM` cleanly via `pcntl_signal` and will flush in-flight work before exiting.

## Troubleshooting

| Symptom | Likely cause |
|---|---|
| 401 on every request after deploy | `ABEON_AUTH_JWKS_URL` unreachable, or kid mismatch. Check `/health/ready` JWKS check. |
| Events publish OK but consumers don't see them | Drainer process not running. Check `abeon_event_outbox` table for unprocessed rows. |
| Drainer reports `outbox_lag` degraded | Drainer crashed or RabbitMQ unreachable. Check pod logs + RabbitMQ health. |
| Duplicate event delivered to handler | Should not happen — `abeon_processed_events` dedupes. If it does, check that the unique index on `event_id` is present (the migration should ensure it). |
| `ContractViolationException: Unknown service 'X'` | `X` not in `config('abeon.services')`. Add the entry. |
| `abeon_user()` returns null inside `abeon.auth`-protected route | Verify `Authorization: Bearer ...` header is present and signed by an Auth-recognized key. Cookie-only flow needs frontend boilerplate to translate (see ADR-0001 closing section). |

## Related

- [ADR-0001](adr/0001-jwt-format.md) — JWT format and validation
- [ADR-0002](adr/0002-event-envelope.md) — Event envelope contract
- [ADR-0003](adr/0003-correlation-id.md) — Correlation ID propagation
- [ADR-0004](adr/0004-rest-envelope-and-errors.md) — REST envelope and errors
- [ADR-0005](adr/0005-service-to-service-auth.md) — Service-to-service auth
- [events-catalog.md](events-catalog.md) — Federation convention for event schemas
