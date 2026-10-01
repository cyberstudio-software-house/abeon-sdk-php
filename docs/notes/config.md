# SDK configuration

Notes behind `src/Config/AbeonConfig.php`. One section per accessor whose value or
validation is not obvious from the line that returns it.

## `instanceOrgId()`

The organisation this instance is bound to, or null for a service that serves every
organisation (ADR-0031 §4).

A value that is set but not a positive integer is refused rather than read as "unbound": a
typo in `ABEON_ORG_ID` would otherwise open the instance to every organisation, silently.

## `authMaxTokenLifetime()`

A backstop against a token that never expires or expires in a decade. Not a TTL policy —
issuers set their own, far shorter (ADR-0001: 15 minutes for users, ADR-0005: 5 for
services). This only bounds what a *validator* will believe. Default 86400.

## `authLeewaySeconds()`

Clock-skew tolerance when validating `exp` / `nbf`, in seconds (ADR-0001 rule 5).

Nodes drift independently, so a validator with zero tolerance rejects tokens that were just
minted by an issuer whose clock is a second ahead. Configurable because the right value
depends on how well the cluster's clocks are kept. Default 60.

## `authJwksCacheTtl()`

How long a consumer caches the JWKS document, in seconds. Default 3600.

This is the quantity that binds key rotation: a consumer that never misses its cache keeps
a retired `kid` usable for a full TTL, so the grace period before removing a key must cover
this plus one access-token lifetime (ADR-0005 as amended by ADR-0025).

## `outboxRetentionDays()`

How long a processed outbox row, or a recorded processed event, is kept. Default 30.

A floor of one day rather than zero: a retention that deletes what was written this second
would take out the very rows `ProcessedEvents` exists to keep — a redelivery arriving after
the prune would be handled a second time.

## `outboxSkipLocked()`

When true, the drainer claims rows with `FOR UPDATE SKIP LOCKED` so a second replica skips
locked rows instead of blocking. Requires a driver that supports it (MariaDB
10.6+/MySQL 8/PostgreSQL); ignored on SQLite. Set with `ABEON_OUTBOX_SKIP_LOCKED`.

## `consumerQueuePrefix()`

Instances of one application bound to different organisations get different queues by
default (ADR-0031 §6). Sharing the service name would make them competing consumers, each
silently receiving about half of what it subscribed to.

## `consumerSlowHandlerSeconds()`

How long one handler may take before the consumer says so. Default 5.0.

Not a limit — nothing is interrupted. Every subscription runs in one loop on one channel,
so a handler that waits holds up every other event this service consumes, and without this
line the symptom is silence: no dead letters, no errors, a queue that simply stops moving.

## `storagePublicBaseUrl()`

Base address of this instance's object container, for public files (ADR-0034).

Required rather than defaulted: an empty base still concatenates, so the mistake would
surface as a page of broken images instead of an error at the line that made one.
