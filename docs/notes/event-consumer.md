# Event consumer

Notes behind `src/Events/EventConsumer.php`.

The consumer subscribes to RabbitMQ queues bound to the main exchange and dispatches
each message to handlers tagged `abeon.event_handler` that declare the matching routing
key in `subscribesTo()`. Failed deliveries are nacked without requeue, so the DLX routes
them to the per-queue dead-letter queue for triage.

## The tenant comes from the envelope, and nothing else can supply it

A consumer has no request and therefore no `AuthContext` to fall back on, so before this
a handler ran with no organisation at all — `require()` threw, and any handler that
instead treated "no tenant" as "no filter" read every organisation's rows.
`TenantContext`'s own docblock has always named this call as the way in; nothing made it.

A null `org_id` is passed through rather than refused: a platform-level event
legitimately has none, and it is the *handler* that knows whether it needs a tenant
(ADR-0018).

## Why each handler is timed

**Every subscription shares one loop and one channel.** A handler that waits —
`RecipientDirectory` on an unreachable Auth is ten seconds times the client's retries —
holds up every other event this service consumes, and from outside "slow" and "hung"
look identical: no dead letters, no errors, nothing in the log. Timing each handler is
what tells them apart.

Running them in parallel is a deployment matter: queues are per routing key, so a slow
subscription can be given a consumer of its own through
`abeon.events.consumer.subscriptions`.

## A message with no matching handler is logged, not dropped silently

The broker already matched the message to a queue we bound, and then `matches()`
re-derived the same decision in PHP and disagreed. Those two implementations have
diverged before — `#` meant "one or more segments" here while meaning "zero or more" to
AMQP — and when they disagree the message is acked with nothing done and nothing said.

Not an error: a handler removed while its queue still exists produces this legitimately,
and nacking would dead-letter a message nobody wants. But it should never be silent.

## Accepted event versions

Version 1 for every handler. `AcceptsEventVersions` existed for a handler that accepts
more than one major, and no handler on this platform ever implemented it — the day a 2.0
producer appears, the interface comes back with its first caller.

## Memoizing the resolved handler list

`$container->tagged('abeon.event_handler')` was called on every `matchingHandlers()` and
`subscriptions()` pass. Worse, Laravel's `tagged()` returns a `RewindableGenerator` that
can be one-shot in some container configurations — re-iterating could silently yield
empty. Materializing once into an array is both faster and safer.

## `matches()`: AMQP topic wildcards

- `*` — exactly one segment (e.g. `crm.*.created` matches `crm.contact.created`).
- `#` — zero or more segments (e.g. `crm.#` matches `crm`, `crm.contact`,
  `crm.contact.created`).

`#` was once translated to `.+` (one or more characters), which (a) treated it as "one+
segments" (off-by-one vs AMQP spec) and (b) failed to match the empty suffix case. `.*`
fixed the suffix but not the separator: `crm.#` became `/^crm\..*$/`, which the broker
matches to `crm` and this did not. The dot in front of a trailing `#` is part of the
wildcard, so it goes with it.

When the two disagree the message is acked with nothing done — the broker routed it here
and `matchingHandlers()` found nobody — and the only trace is one warning.

## `envelopeProblem()`: all nine required fields

ADR-0002 says the consumer refuses a message that does not conform to `_envelope.json`,
and the check was two of its nine required fields. The rest were read with a cast, so a
missing `org_id` became a platform-level event and a malformed one became somebody
else's tenant — both silently, both written by a handler as if they were meant.

Present-and-null is the whole point of the `org_id` check: a platform-level event says
so by carrying the key with a null, and a publisher that leaves it out has not said it.

## `deadLetterQueues()` is public on purpose

The queues are this class's naming decision and something has to read them: a message
that lands there is an event this service refused, and until `abeon:events:dlq` there was
nothing on the platform that could even count them.
