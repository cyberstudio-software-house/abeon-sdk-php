# Changelog

All notable changes to `abeon/sdk` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/); this package is pre-1.0.

## [Unreleased]

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
