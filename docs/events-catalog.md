# Event catalog and federation convention

This document describes **how services declare and discover event payload schemas** across the platform. It complements ADR-0002 (event envelope) with the operational mechanics of the federation pattern (M2/M3 from the Phase 0 plan).

## The principle

**Schemas are federated, not centralized.** The SDK ships only the **generic envelope** schema (`schemas/events/_envelope.json`). The shape of `data` for each specific event — say `crm.contact.created` or `finance.invoice.paid` — lives in the **owning service's repository**, not in `abeon/sdk`.

Why: the CRM team owns the semantics of CRM events. Forcing them to PR every shape change into `abeon/sdk` makes the SDK a bottleneck and entangles release cycles.

## How a service declares its event schemas

### 1. Add a `schemas/events/` directory to your service repo

Each file is named after the routing key it describes:

```
abeon-crm/
└── schemas/
    └── events/
        ├── crm.contact.created.json
        ├── crm.contact.updated.json
        ├── crm.deal.won.json
        └── crm.deal.stage_changed.json
```

The base name must match a valid routing key (`{service}.{entity}.{action}`, per ADR-0002 regex). Files with invalid names are skipped by discovery.

### 2. Each file is a JSON Schema 2020-12 document

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "https://schemas.abeon.pl/events/crm.contact.created.json",
  "title": "crm.contact.created payload",
  "description": "Emitted by CRM when a new contact is created.",
  "type": "object",
  "required": ["contact_id", "email", "created_by"],
  "properties": {
    "contact_id": { "type": "integer" },
    "email":      { "type": "string", "format": "email" },
    "name":       { "type": ["string", "null"] },
    "company_id": { "type": ["integer", "null"] },
    "created_by": { "type": "integer", "description": "User ID" }
  },
  "additionalProperties": false
}
```

The schema describes **only the `data` portion** of the envelope — not the envelope itself. The envelope is already covered by `abeon-sdk-php/schemas/events/_envelope.json` and is enforced at publish time by `EnvelopeBuilder`.

### 3. Declare the schemas directory in `composer.json`

```json
{
  "name": "abeon/crm",
  "type": "project",
  "extra": {
    "abeon": {
      "event-schemas": "schemas/events/"
    }
  }
}
```

The path is relative to the package root. Only `abeon.event-schemas` under `extra` is honored.

### 4. Done — discovery is automatic

When any service installs your package (via Composer) and boots, `Abeon\SDK\Events\SchemaDiscovery` scans `vendor/composer/installed.json`, finds packages with `extra.abeon.event-schemas`, and aggregates the schemas. The result is exposed via `Abeon\SDK\Events\EventCatalog`:

```php
use Abeon\SDK\Events\EventCatalog;

$catalog = app(EventCatalog::class);

$schema = $catalog->for('crm.contact.created');
// → array decoded from crm.contact.created.json

$allKeys = $catalog->routingKeys();
// → ['crm.contact.created', 'crm.deal.won', 'finance.invoice.paid', ...]

$all = $catalog->all();
// → ['crm.contact.created' => [...schema...], ...]
```

The catalog is **cached per process** — refreshed only on `refresh()` call or process restart.

## Conventions

### Naming
- Routing key = filename (without `.json`).
- Verb in `action` segment must be **past tense** (`created`, `updated`, `won`, `paid`, `escalated`). Producer-driven imperative names (`create_x`, `send_x`) are command-as-event anti-patterns — they'll be rejected in PR review (see ADR-0002).
- Namespace by domain, not by team. `crm.*` is the CRM domain, not "the CRM team's stuff".

### `$id` URI
Use `https://schemas.abeon.pl/events/{routing-key}.json` as the canonical `$id`. The URI doesn't need to resolve to a real document — it's an identifier. (A future centralized docs site at `docs.abeon.pl` may serve them, see TODO below.)

### Versioning
- **Additive changes** (new optional field) — no version bump.
- **Breaking changes** (rename field, change type, remove field) — two options:
  - Suffix the routing key: new file `crm.contact.created.v2.json` + producers publish to `crm.contact.created.v2`. Old key consumed by old consumers until sunset.
  - Bump envelope `version` from `"1.0"` to `"2.0"` and put both in the schema's `oneOf`. Consumers branch on `version` in the handler.

The choice is per-event — depends on whether the new shape is "evolution of the same event" (envelope bump) or "a different event in practice" (routing key suffix). Document the choice in the event's PR.

### Validation
v1 of the SDK validates **only the envelope**, not the payload. The schemas you declare exist for:

- Documentation (other teams reading them).
- Contract tests in your repo (validate fixtures against schema with `ajv` / `justinrainbow/json-schema`).
- TypeScript codegen for `@abeon/{service}-events` npm packages (Faza 1+).

Opt-in payload validation on publish is planned for SDK v0.2. When enabled, `EventPublisher` will validate `data` against the matching schema before writing to the outbox; mismatches throw `ContractViolationException`.

## TypeScript counterpart

For the TS side, each service ALSO publishes an npm package `@abeon/{service}-events` from the same repo, containing:

- TypeScript type definitions for each event (`type CrmContactCreated = { contact_id: number; ... }`).
- The same JSON Schemas (so contract tests in TS can validate fixtures with `ajv`).
- A typed event union (`type CrmEvent = CrmContactCreated | CrmDealWon | ...`).

Build with `tsup`, publish to GitHub Packages. The Next.js / Inertia frontend installs the relevant `@abeon/{service}-events` packages it consumes. See `abeon-shared-phase0-plan.md` (TS plan v1.1) for the build pipeline convention.

## Future: centralized read-only catalog

A future docs site (`docs.abeon.pl`, planned for Phase 1+ when 2-3 services exist) aggregates all `event-schemas` declarations across repos and renders a searchable browser-friendly catalog. **The aggregated site is a VIEW**, not the source of truth — schemas continue to live in service repos. The CI job that builds the docs runs on any change in any service repo.

Until that exists, dev workflow is:

```bash
# Find which service owns an event
grep -r "extra.abeon.event-schemas" ../*/composer.json

# List all events declared in a service
ls path/to/service/schemas/events/

# Discover at runtime from a Laravel tinker session
php artisan tinker
>>> app(\Abeon\SDK\Events\EventCatalog::class)->routingKeys();
```

## Pitfalls

1. **Schema in wrong place.** If a schema file ends up in `abeon-sdk-php/schemas/events/` instead of the owning service's repo, the platform team becomes a bottleneck. The SDK ships ONLY the generic envelope schema. Per-event schemas don't belong in this repo.

2. **`extra.abeon.event-schemas` typo.** No CI check catches this in v1 — your schemas silently fail to register. Run `php artisan tinker` + `EventCatalog::routingKeys()` after `composer install` to verify your declarations show up.

3. **Stale schema files for retired events.** When an event is removed, also remove its schema file. Otherwise `EventCatalog::all()` still advertises it, misleading consumers.

4. **Different schema for the same routing key in two packages.** Discovery doesn't merge — last loaded wins (undefined order). Don't declare another service's events; consume them as opaque envelopes if you need their data.

## Related

- ADR-0002 (event envelope) — the wrapping schema.
- `src/Events/SchemaDiscovery.php` — implementation.
- `src/Events/EventCatalog.php` — runtime accessor.
- Phase 0 SDK plan, sekcja M (modularity decisions M2, M3) — rationale for federation.
