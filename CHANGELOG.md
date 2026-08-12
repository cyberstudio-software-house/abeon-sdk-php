# Changelog

All notable changes to `abeon/sdk` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/); this package is pre-1.0.

## [Unreleased]

### 2026-08-12 — multi-tenancy: ADR reconciliation + contract changes

Brings the recorded architecture back in line with the concept meeting. Plan:
`../abeon-adr-reconciliation-plan-2026-08-12.md`. Background: `../abeon-concept-status-2026-08-12.md`.

#### Added
- **ADR-0016 Multi-tenant organisations** (supersedes ADR-0012) — one deployment serves many client
  organisations; `org_id` becomes an authorization and data-scoping dimension. Carries the provenance:
  this restores the original MVP's model rather than inventing one.
- **ADR-0017 Tenant switching** — switching organisation re-issues the token via a new Auth endpoint.
  Clients never assert their own tenant.
- **ADR-0018 Tenant scoping** — the SDK owns row scoping; a tenant-scoped model queried with no tenant
  in context **throws**. Records the blind spots (raw SQL, consumers, console commands) explicitly.
- **ADR-0019 AbeonUnified service** (supersedes ADR-0006) — `abeon-notifications` renamed and widened
  to own the app registry, organisation↔app assignment and app data. ADR-0006's notification contract
  carries forward verbatim. States the outbound-integration doctrine.
- **ADR-0020 AI gateway** — OpenRouter reachable only via Unified, on the platform key, metered per
  organisation. Streaming and BYO keys reserved in the contract, not built.
- **ADR-0021 Object storage layout** — one OCS container per organisation with `{service}/` prefixes,
  superseding arch doc §8.4. Records that Swift ACLs are container-level, so the prefix is an SDK
  convention rather than an enforced boundary.
- `schemas/events/app-registered.json` — the registration event (`id`, `name`, `owner_email`, `org_id`).
- `docs/adr/README.md` — index with statuses, supersession arrows and the ADR conventions.

#### Changed — contract
- **`schemas/events/_envelope.json`: new required top-level `org_id`** (nullable). Top-level rather than
  inside `actor`, because a system-generated event still belongs to an organisation. Applied to envelope
  1.0 **in place** rather than bumping to 2.0 — no service consumes it yet, so the breaking change was
  free now and would have been a migration later. Reasoning recorded in ADR-0002 under *Versioning*.
- **`schemas/auth/jwt-user.json`: `org_id` is now required and non-null.** It was optional and
  informational under the superseded ADR-0012.
- **`schemas/auth/jwt-service.json`: optional `org_id`** for on-behalf-of calls.
- **`schemas/dto/user.json`: `org_id` is now a required key** (still nullable).
- `src/Events/Event.php` — new `?int $orgId`, round-tripped through `fromEnvelope()` / `toEnvelope()`.
- `src/Events/EnvelopeBuilder.php` — emits `org_id` from `AuthContext`. `null` means "no organisation",
  never "all organisations".
- ADRs 0001, 0002, 0005, 0009, 0010, 0015 amended in place, each with a dated note in `## References`.

#### Changed — code, so the contract and the implementation agree
- **`JwtValidator::decodeUser()` rejects a user token without a non-null `org_id`.** It previously
  read `$claims['org_id'] ?? null`, so a token missing the claim became a user with no organisation.
  Failing at the trust boundary keeps a missing tenant out of query scoping, where "no tenant" is one
  mistake away from "every tenant".
- **`AuthContext::orgId()` and `requireOrgId()`** — the accessors ADR-0018 names. `requireOrgId()`
  throws (new `AuthException::noOrganisation()`, 403) rather than returning null, per the fail-closed
  rule.
- **`ServiceTokenProvider::token(?int $orgId)`** — the cache is now keyed per organisation instead of
  one process-wide slot, and the token carries `org_id` when acting on behalf of one. A shared slot
  would have handed one organisation's token to another organisation's request.
- **`ServiceClient` propagates the current organisation** into the service token. It takes a *closure*
  resolving `AuthContext`, not an instance: the client is a singleton while `AuthContext` is
  request-scoped, so a held instance would pin the first request's organisation for the life of the
  process.
- **`AppsController::isVisibleTo()` now hides apps explicitly marked `enabled === false`.** It
  previously ignored `enabled` entirely, so a disabled app stayed visible to anyone using the base
  controller directly. `null` is still not a denial — self-registration leaves it null by contract
  (ADR-0015 §1), so treating null as "not assigned" would render every self-registered catalogue
  empty. Resolving assignment per organisation needs the `tenant_apps` relation and belongs to the
  registry's owning service; ADR-0010's implementation note now says so explicitly.

#### Known gaps (tracked in `../abeon-sdk-delta-2026-08-12.md`)
- The `Tenancy` and `Storage` components are specified (ADR-0018, ADR-0021) but not built.
- The dev stub has one organisation and no memberships, so nothing multi-tenant can be demonstrated
  end to end yet.

**Verified:** PHPUnit **194/194** (362 assertions, up from 171), PHPStan level 8 clean,
`@abeon/shared` **154/154** with `sync-schemas:check` in sync and `tsc --noEmit` clean.

### 2026-05-27 — hardening & contract freeze

Made the package "stable enough" to build the Auth Service on. Full stage report:
`../abeon-base-hardening-2026-05-27.md`.

#### Added
- **`JwtValidatorTest`** (11 cases) and **`JwksClientTest`** (5 cases) — first coverage of the JWT/auth trust anchor, including an alg-confusion case that proves RS256 is pinned (HS256 rejected), unknown-`kid` flush+retry, and key-rotation recovery.
- **`OutboxDrainerTest`** (4 cases) — publish-once + mark-processed, no-republish, failure recording, and the SQLite skip-lock no-op path.
- **`SchemaContractTest`** (9 cases) — validates `User`, `AppDescriptor`, `ProblemDetails`, and the event envelope DTO serialization **against the JSON Schemas** in `schemas/` (via `opis/json-schema`), plus round-trip parity with golden fixtures shared with `@abeon/shared`.
- `tests/fixtures/contract/*.json` — golden fixtures (byte-identical to `@abeon/shared`'s).
- `phpstan.neon` (**level 8**) + `phpstan-baseline.neon` (7 pre-existing findings captured as tracked debt).
- Composer `scripts`: `composer test` (phpunit), `composer stan` (phpstan).
- `.github/workflows/ci.yml` — CI on PHP 8.3 + 8.4 (install → phpstan → phpunit).
- `require-dev`: `opis/json-schema ^2.3`.
- `AbeonConfig::outboxSkipLocked()` — reads `abeon.events.outbox.skip_locked` (default `false`).

#### Fixed
- **CR-1 (OutboxDrainer race):** `drainOnce()` now runs fetch + publish + mark inside a single transaction, and `fetchBatch()` locks the claimed rows (`lockForUpdate()`, or `FOR UPDATE SKIP LOCKED` when `skip_locked` is enabled). Driver-aware: a no-op on SQLite / SQL Server, which fall back to the documented single-replica convention. Prevents a second drainer replica from republishing the same events.

#### Notes
- Pint is intentionally not part of the gate yet (the codebase uses manual `=>` alignment that Pint's default preset would reformat).
- The 138-test baseline at the start of this work already included the HI-1/HI-3/HI-4/HI-5 fixes and the HI-2 idempotency docs from the 2026-05-15 review; suite now totals 167 tests / 317 assertions.
