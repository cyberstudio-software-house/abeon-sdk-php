# ADR-0032: Redis is the platform's shared ephemeral store

**Status:** Accepted
**Date:** 2026-09-23

## Context

PRD §8.3 asked for Redis behind sessions, cache, rate limiting and queues. None of it was built: every
application runs `CACHE_STORE=database`, `SESSION_DRIVER=database` and `QUEUE_CONNECTION=database`, and
the architecture document has carried "Redis — **decyzja otwarta**" in §13 ever since. This ADR closes
that decision.

**The reason is correctness, not speed.** Three places already depend on the store being shared between
processes, and two of them say so in their own docblocks:

1. **`TokenRefresher` in the template.** ADR-0023 rotates refresh tokens and treats a second use of a
   consumed one as a replay: it revokes the whole family and logs the user out. A page fires several
   requests at once, so the refresher single-flights one refresh per token behind a cache lock. With a
   per-process store that single-flight is per process, and two replicas refreshing the same token
   produce exactly the replay the scheme exists to catch. `assertStoreIsShareable()` only *warns*, and
   it warns about `array` and `file` — which means `file`, the default in `abeon-auth-ui`, passes
   unnoticed as a lock store today.
2. **The application catalogue in Auth.** Cached for five minutes per organisation and invalidated when
   an administrator enables or disables an application. On a per-process store that invalidation reaches
   the replica the administrator happened to hit, and the others serve the stale list until the TTL
   expires. The class says the five minutes are only defensible on a shared store.
3. **The client-host lookup in the login screen.** A 60-second answer to "does this host belong to an
   active organisation", asked on the login path. Its store is `file`, so every process asks Auth
   separately — and a transient failure is cached as a refusal, per process.

A database is a shared store, and that is why none of this is broken today. It is also a queue, a lock
manager and a cache in one engine, on the same connection pool as the business writes.

## Decision

### 1. One Redis, for cache, locks and rate limiting

`CACHE_STORE=redis` in every application, and `ABEON_REFRESH_LOCK_STORE=redis` for the template's
refresh lock. That moves, in one step: the application catalogue and its stale fallback, the JWKS
document, the client-host lookup, user preferences, `RateLimiter` counters (route throttles, the emit
cap, the per-address login ceiling) and every `Cache::lock()`.

**Queues and sessions stay on the database for now.** Moving them is a separate decision with its own
operational weight (Horizon, a failed-job story, session eviction), and this ADR is deliberately the
smaller half: the half that is already a correctness requirement.

### 2. What stays in the database, permanently

- **`abeon_event_outbox`** — written in the same transaction as the business row. That transaction is
  the whole point of the outbox, and Redis cannot join it. The drainer also claims rows with
  `FOR UPDATE SKIP LOCKED`, which has no equivalent here.
- **`abeon_processed_events`** — the consumer's dedup ledger. A key with a TTL is the tempting
  substitution and the wrong one: after the TTL, a redelivery replays the handler. The architecture
  document already says "idempotencja przez tabelę DB `abeon_processed_events` + outbox (**nie** Redis
  SETNX)".
- **`login_attempts`** in Auth — lockouts. "A lockout that evaporates is not a lockout": a cache flush
  must not hand an attacker a reset. Only the per-address request ceiling, which exists to bound argon2id
  work rather than to stop guessing, lives in the cache.
- **`messages`, `notifications`, `failed_jobs`** — data and audit trail.

### 3. The prefix is a convention, not a boundary

With a database per service the stores were physically separate. In one Redis they are separated by a
key prefix, and nothing enforces it — the same sentence ADR-0021 makes about object-storage prefixes
applies here verbatim. Therefore:

- every service and every client instance sets `REDIS_PREFIX` and `CACHE_PREFIX` **explicitly**, never
  by the framework's `Str::slug(APP_NAME)` default;
- a client's instance carries its organisation in the prefix (`cms-acme:`), so two clients' caches
  cannot collide even when both run the same application image;
- cache and queues use different logical databases (`REDIS_CACHE_DB`, `REDIS_DB`), so flushing one
  cannot take the other with it.

This matters most for the SDK's JWKS entry, whose key is the flat `abeon.jwks`: without an explicit
prefix per application every service on the platform would share one entry.

### 4. Redis is required, not an accelerator

An empty cache is harmless — the next request re-reads the source. An *unavailable* Redis is not:
the catalogue throws rather than serving an empty application list (ADR-0025 deliberately refuses to
make "no apps" a failure mode), and the refresh single-flight degrades to concurrent refreshes. A
deployment that runs more than one replica of anything requires Redis to be up, and it belongs in the
readiness story of the deployment layer, beside the database and the broker.

### 5. phpredis, in our image

The client is `phpredis`, installed in `abeon-boilerplate-inertia/docker/php` — the image every
container in the estate already builds from — rather than `predis` as a Composer dependency. It is what
`config/database.php` names by default, and the image is ours to extend. The cost is one `pecl install`
line and an extension in CI wherever a Redis-backed test runs.

### 6. Tests stay on `array`, except where the store is the subject

The suites keep `CACHE_STORE=array` in `phpunit.xml`: they assert behaviour, and Laravel's cache
contract is driver-independent. Two things are not driver-independent, and they get a `redis` group that
skips itself when Redis is unreachable:

- the refresh lock and the sharing of a refresh result between two resolutions of the refresher;
- the catalogue invalidation as seen by a second process.

## Consequences

**Positive**

- The refresh single-flight, the catalogue TTL and the host lookup stop depending on how many processes
  happen to be running.
- The hottest counters — a one-second emit window, per-request throttles — leave the database, which
  today serves them from the same engine that handles the business writes.
- `abeon-auth-ui` stops holding a per-process `file` cache on the login path.
- What the PRD promised is now true, and §13 stops carrying an open decision.

**Negative**

- One more piece of infrastructure that must be up, and it is now on the critical path for multi-replica
  deployments. Locally it is a compose service; in production it needs an instance with a failure story.
- Separation between applications becomes a naming convention. A missing `REDIS_PREFIX` is silent until
  two services read each other's `abeon.jwks`.
- The image grows a compiled extension, and anything building from stock `php:8.4-fpm` has to add it.
- With one shared cache the estate misses the JWKS entry once per TTL instead of once per process. That
  is fewer requests to Auth, but it also means a retired `kid` stays usable for the full TTL across the
  whole platform — the grace period in ADR-0005 is now bounded by one shared cache rather than by the
  shortest-lived worker.
- Port 6379 is taken on the development machine by another project, so the suite publishes 6380. One
  more non-default port to remember, for the same reason Mailpit sits on 1026.

**Rejected**

- *Leave everything on the database.* It works and is shared, but it puts locks and one-second rate-limit
  windows on the same engine as the business writes, and it leaves `file` in auth-ui unnoticed.
- *Redis for queues and sessions in the same step.* More moving parts changed at once, and the queue
  switch wants Horizon and a failed-job decision that this change does not need.
- *A Redis instance per service, or per client instance.* Real isolation, but at sixteen applications and
  twenty clients it is hundreds of processes to run for a cache. The prefix convention buys most of the
  separation at a fraction of the cost — and, unlike the database, the data here is reconstructible.
- *Moving `abeon_processed_events` to Redis SETNX.* Already rejected in the architecture document; a TTL
  on the dedup ledger turns a redelivery into a replay.
- *A shared Redis deny-list for revoked tokens.* ADR-0023 rejected that on coupling grounds and this ADR
  does not reopen it: revocation stays a property of the refresh-token family in Auth's database.

## References

- PRD v1.0 §8.3 (Redis behind sessions, cache, rate limiting, queues), §13
- ADR-0005 (JWKS cache TTL bounds key rotation — now one shared cache), ADR-0009 and ADR-0010 (both
  assumed a Redis cache for preferences and the apps endpoint; that assumption is now true), ADR-0021
  (prefix as a convention, not a boundary), ADR-0023 (refresh rotation and reuse detection; its rejection
  of a shared deny-list stands), ADR-0025 (an empty catalogue is not a failure mode), ADR-0031 (a client
  instance prefixes its keys with its organisation)
- Implemented in this change: the compose service on 6380, `phpredis` in the shared image, `CACHE_STORE`
  and `ABEON_REFRESH_LOCK_STORE` for the local stack, explicit prefixes per service, and the `redis` test
  group in `abeon-auth` and the template. Queues, sessions and Horizon are not in it.
