# Outbox drainer

Notes behind `src/Events/OutboxDrainer.php`.

The drainer polls `abeon_event_outbox` in batches, publishes each row to the configured
exchange with the event's routing key, and marks `processed_at` on success. On failure it
increments `attempts`, sets `last_error`, and schedules `next_attempt_at` with
exponential backoff (2^n seconds). Rows exceeding `max_attempts` stay in the table with
their last error for manual triage — there is no automatic DLQ at the publisher side, DLX
is consumer-side only.

## Three steps, and the publish is not inside a transaction

It used to be: the batch was fetched `FOR UPDATE` and published to RabbitMQ while those
rows stayed locked until commit, so an unreachable broker held `abeon_event_outbox`
locked for the whole of `read_write_timeout`, times the batch size.

That is the exact thing this platform's own code warns against elsewhere —
`OrganisationMirror` and `abeon:registry:import` both go out of their way to keep an HTTP
call off a row lock, at some length, and the SDK did the opposite in its core loop.

So: claim the batch under a short lease, publish with nothing locked, then mark each row.
A crash between publish and mark republishes on the next pass once the lease expires —
which is what `ProcessedEvents` is for on the consumer side, and it is already the
guarantee ADR-0002 promises (at-least-once, never exactly-once).

## The channel first, then the claim

Connecting is the step most likely to fail, and failing after the claim would lease a
batch for a failure that happened before a single publish was attempted — rows held back
for the lease with no `attempts` increment and no `last_error` to explain it. Nothing is
claimed unless there is somewhere to publish to.

## Publisher confirms

Without them `basic_publish()` is a write to a socket buffer: it returns before the broker
has the message, and it returns just as happily when the broker is refusing it — a full
disk, a missing exchange, a connection that dies in the same millisecond. The row was then
marked processed for a message nobody ever accepted, and the outbox's whole promise — the
event and the write that caused it are both durable or neither is — was a hope.

## `confirm()` waits per batch, not per message

One round trip for a batch instead of one each, and the batch is already the unit the
lease is taken in. A timeout or a `nack` leaves every row of the batch unmarked, so the
next pass republishes them — at-least-once, which is what ADR-0002 promises and what
`ProcessedEvents` absorbs on the consumer side.

## `claimBatch()`: the lease is `next_attempt_at`

The lease uses the column the fetch already filters on — no new column and no new state. A
drainer that dies mid-publish leaves rows leased rather than locked, and they become
eligible again when it expires; a lock would have died with the connection, which sounds
better until you notice it also means a hung broker holds them for as long as it hangs.

`attempts` is deliberately not incremented here. A claim is not an attempt, and burning
one on a process that was killed would push events toward `max_attempts` for a reason that
has nothing to do with them.

## `leaseSeconds()` is derived, not configured

The lease only has to outlast a publish, and a publish cannot outlast
`read_write_timeout`. Erring short is the safe direction — a lease that expires early
costs a duplicate delivery, which `ProcessedEvents` already absorbs, while one that is too
long delays recovery after a worker is killed.

## `applyLock()`

The lock keeps a concurrent drainer replica off the rows being claimed. It is held only
for the length of the claim transaction, which does no I/O beyond the database — the
publish happens after it commits.

`SKIP LOCKED` (opt-in) lets peers move past locked rows on MariaDB 10.6+/MySQL
8/PostgreSQL. SQLite and SQL Server have no row-level locking; there the lease written by
the claim is what keeps replicas apart, and a brief overlap costs a duplicate delivery
rather than a lost event.
