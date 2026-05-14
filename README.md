# abeon/sdk

Shared SDK for Abeon Unified microservices (PHP / Laravel side).

Each microservice in the Abeon Unified platform installs `abeon/sdk` to get
a common contract for cross-service communication, identity, observability,
and operational concerns.

## Scope (v1, in progress)

- **Auth** — JWT validator, `AuthMiddleware`, `AuthContext`, `Gate` bridge, `abeon_user()` helper.
- **Service-to-service HTTP** — `ServiceClient` (config-driven: `$client->service('crm')->get(...)`), service JWT issuance, correlation header propagation.
- **Events (RabbitMQ)** — `EventPublisher` (writes to outbox table within the business transaction), `OutboxDrainer` worker, `EventConsumer` with idempotency, in-memory test double.
- **Correlation / Logging** — `CorrelationIdMiddleware`, JSON log formatter.
- **Health checks** — `/health` + `/health/ready` with pluggable `Check` interface.
- **Errors** — RFC 7807 problem details renderer.
- **API response helpers** — envelope `{data, meta}`, paginated response.
- **Service Registry** — bootstrap self-registration into Auth.
- **Permissions** — declarative per-service, published as event on bootstrap.

Full design: [`../abeon-sdk-phase0-plan.md`](../abeon-sdk-phase0-plan.md).

## Naming

- **Folder name:** `abeon-sdk-php` — the `-php` stack suffix leaves room for
  a parallel `abeon-sdk-ts` (or similar) for a TypeScript counterpart later.
- **Composer package name:** `abeon/sdk` — stable, matches the architecture
  document. Folder name and package name are intentionally decoupled.

The TypeScript counterpart `@abeon/shared` lives in a separate repository
(see decision #10 in the Phase 0 plan).

## Installation

In a consumer service:

```bash
composer require abeon/sdk
```

Laravel auto-discovery loads `Abeon\SDK\AbeonServiceProvider`.

## Status

**Sprint 0 — skeleton.** No public API stable yet. See the Phase 0 plan
for the sprint breakdown and Definition of Done.

## License

Proprietary — internal to the Abeon Unified platform.
