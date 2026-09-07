# Changelog

All notable changes to `abeon/sdk` are documented here.
Format follows [Keep a Changelog](https://keepachangelog.com/); this package is pre-1.0.

## [Unreleased]

Nothing yet.

## [0.1.0] — 2026-09-07

First tagged version. Everything below was already in use — four services and the
boilerplate consume this package through a symlink, so the code has been exercised
continuously — but nothing pointed at a fixed reference, so a fresh checkout could only
track a moving branch. The tag exists so the boilerplate can pin.

### 2026-08-12 — per-organisation preferences, registry ownership, config

#### Changed
- **Preferences are per user per organisation** (ADR-0009 as amended by ADR-0016). `user_preferences`
  is keyed `(user_id, org_id)` with a unique constraint; the organisation comes from the caller's
  token, so **the URL is unchanged** — switching organisation simply makes the same request resolve to
  a different row. Reading or writing with no organisation is refused rather than falling back to a
  shared row (ADR-0018).
  7 tests added, since this path had none: isolation between organisations, that a write in one does
  not clobber the other, separation between users in the same organisation, refusal without an
  organisation, and that PATCH still merges.
- **`ServiceRegistry::register()` posts to `service('unified')`** instead of `service('auth')`
  (ADR-0019). Auth owns users, memberships, roles and permissions; Unified owns applications and their
  assignment. Read paths keep their URLs with Auth proxying, because the chrome and both SDKs already
  call them — only self-registration moves, since nothing outside this SDK depends on it.
- **`config/abeon.php`** resolves the two platform-tier services by name: `auth` (`ABEON_AUTH_URL`) and
  `unified` (`ABEON_UNIFIED_URL`). An unconfigured service still throws `unknownService` rather than
  silently resolving to null.

#### Not built, deliberately
The AI client (delta item 11) is a wrapper around one `ServiceClient` call, for a gateway that does not
exist yet, and ADR-0020 leaves the request shape as "OpenRouter's, narrowed to what the platform
supports". Building it now would mean guessing that shape and shipping something untestable. It belongs
with the gateway.

### 2026-08-12 — `Tenant` DTO (ADR-0017)

#### Added
- **`schemas/dto/tenant.json` + `src/DTO/Tenant.php`** — a client organisation, as returned by
  `GET /api/v1/auth/tenants` for the chrome's switcher.

  Deliberately thin: id, name, slug, optional logo, and `current`. **No roles or permissions** — those
  are per membership and arrive in the re-issued JWT (ADR-0017). A client that could read its own
  authorisation from this list would be deriving authorisation from a response it can influence; a
  contract test asserts the schema rejects a `permissions` key.

  ADR-0017 left the shape open ("whether it is a separate endpoint"). Settled here in favour of a
  separate endpoint rather than widening `GET /api/v1/auth/user`: the `User` DTO is contract-frozen,
  mirrored in `@abeon/sdk-ts` and covered by golden fixtures shared byte-for-byte, so nesting a
  collection into it is a breaking change to a working contract — for data with a different cardinality
  and refresh cadence.

### 2026-08-12 — `Tenancy`: row scoping (ADR-0018)

The mechanism ADR-0018 specified. Lands before the first domain table exists, which is the cheapest
this will ever be.

#### Added
- **`Abeon\SDK\Tenancy\TenantContext`** — the organisation the current unit of work belongs to. In an
  HTTP request it falls back to `AuthContext`, so the common path needs no wiring. Elsewhere there is
  nothing to fall back on — `EventConsumer` deliberately does not populate `AuthContext` — so consumers,
  queued jobs and console commands enter a tenant explicitly with
  `runFor($orgId, fn () => …)`, which restores the previous value afterwards **including on exceptions**,
  so a failing handler cannot leave a worker pinned to one organisation. Bound `scoped()`, like
  `AuthContext`.
- **`Abeon\SDK\Tenancy\TenantScope`** — global query scope. **Fails closed:** with no tenant in context
  it throws rather than returning every organisation's rows.
- **`Abeon\SDK\Tenancy\BelongsToTenant`** — `use` it on a model to get the scope, automatic stamping of
  `org_id` on create, and `Model::withoutTenantScope(fn () => …)` — one greppable token, so auditing
  cross-organisation access is a search rather than a code review. Nested calls use a counter, so an
  inner block cannot re-enable scoping for an outer one.

#### Known limits, documented rather than papered over
- Raw access (`DB::table()`, SQL, query-builder joins) bypasses Eloquent scopes entirely.
- Migrations, seeders and console commands run tenant-less by nature.
- **`saveQuietly()` / `withoutEvents()` suppress the stamp**, inserting an unstamped row. This cannot be
  closed from inside the trait, so **the tenant column must be `NOT NULL`** — the database is the
  backstop. Found while building the component; there is a test asserting the constraint fires.

23 tests covering scoping, cross-organisation invisibility by id, fail-closed reads and writes, the
escape hatch (including restoration after a throw and correct nesting), and the consumer shape — run
against real SQLite rather than a mocked builder, since the thing under test is the SQL that comes out.

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
`@abeon/sdk-ts` **154/154** with `sync-schemas:check` in sync and `tsc --noEmit` clean.

### 2026-05-27 — hardening & contract freeze

Made the package "stable enough" to build the Auth Service on. Full stage report:
`../abeon-base-hardening-2026-05-27.md`.

#### Added
- **`JwtValidatorTest`** (11 cases) and **`JwksClientTest`** (5 cases) — first coverage of the JWT/auth trust anchor, including an alg-confusion case that proves RS256 is pinned (HS256 rejected), unknown-`kid` flush+retry, and key-rotation recovery.
- **`OutboxDrainerTest`** (4 cases) — publish-once + mark-processed, no-republish, failure recording, and the SQLite skip-lock no-op path.
- **`SchemaContractTest`** (9 cases) — validates `User`, `AppDescriptor`, `ProblemDetails`, and the event envelope DTO serialization **against the JSON Schemas** in `schemas/` (via `opis/json-schema`), plus round-trip parity with golden fixtures shared with `@abeon/sdk-ts`.
- `tests/fixtures/contract/*.json` — golden fixtures (byte-identical to `@abeon/sdk-ts`'s).
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
