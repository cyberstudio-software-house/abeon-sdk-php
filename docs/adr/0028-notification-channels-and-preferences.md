# ADR-0028: Notification channels, preferences and the default emit path

**Status:** Accepted
**Date:** 2026-09-17

## Context

Architecture doc §5A promises a notification service that decides *what, to whom and through which
channel*: in-app, WebSocket, email and push, with per-user preferences per notification type. The contract
ADR-0006 fixed, and ADR-0019 carried forward, delivers none of that. ADR-0019 recorded the gap and left it
open.

Three things made it worth closing now rather than with the email channel itself:

- **The request had no way to say "email".** `notification-requested.json` and the internal POST are
  `additionalProperties: false`. Adding a field once sixteen services emit is harmless for them, but the
  *meaning* of an absent field is then fixed by whatever they already do.
- **Preferences had no owner.** §5A.6 put them on the notification service; ADR-0009 put user preferences
  in Auth. Nothing was built either way.
- **The preferred emit path did not exist.** ADR-0006 says services should publish
  `*.notification.requested`, but nothing published it and nothing consumed it. The only working path was
  the synchronous POST that ADR-0006 describes as the exception.

No service emits notifications yet, so every decision here is still free.

## Decision

### 1. Channels are part of the request, `in_app` is always one of them

A notification request, on either path, carries an optional `channels` array:

| Value | Meaning |
|---|---|
| `in_app` | The feed row and its live update over Reverb. **Required.** |
| `email` | Also delivered by email, once Unified has an email channel. |

- Absent means `["in_app"]`, which is exactly today's behaviour.
- A request without `in_app` is **rejected**, not repaired. The feed is the record of everything a user
  was told, and a notification that exists only in someone's inbox is one the platform cannot show,
  count or expire. Relaxing this later is a non-breaking change; tightening it would not be.
- WebSocket is not a channel. It is how `in_app` reaches an open tab (§5A.2 listed it separately).
- `push` is not in the enum until something delivers it. Adding a value is additive for emitters.
- `NotificationDto` does **not** change. The feed shape stays what every chrome already reads.
- Unified stores the **effective** channels on the row: the requested ones minus those the user turned
  off (§2). An `email` recorded before the email channel exists is never sent retroactively.

Schema: `schemas/events/notification-requested.json` (`channels`); the internal POST validates the same
rule.

### 2. Preferences live in Unified, per user, as rules

`GET` and `PUT /api/v1/notifications/preferences`, user token, answered by AbeonUnified. Schema:
`schemas/dto/notification-preferences.json`.

```json
{ "preferences": [
    { "type": "*",                 "channels": { "email": false } },
    { "type": "crm.deal.assigned", "channels": { "email": true } }
] }
```

- **Resolution:** a rule for the exact `type` wins over `*`; with neither, the requested channel stays.
  A preference can only remove a channel the emitter asked for — it never adds one. The emitter knows
  whether a notification is worth an email; the user can say they do not want it.
- **`in_app` is not configurable**, for the reason in §1.
- **`PUT` replaces the whole set.** At most 200 rules, one per `type`.
- **Per user, across organisations** — the same scope as the feed (ADR-0006, 2026-08-15). If the bell is
  ever scoped to an organisation, preferences move with it.
- **Not in the ADR-0009 preference blob.** Unified resolves channels when it writes a row, and it must not
  call Auth once per notification to learn them. ADR-0009 stays chrome state.

### 3. The event is the default path; `Notifier` is how a service uses it

A service notifies a user by publishing `{service}.notification.requested` through the outbox:

```php
$notifier->notify(new NotificationRequest(
    userId: 42, type: 'crm.deal.assigned', title: '…', body: '…',
    channels: [NotificationChannel::InApp, NotificationChannel::Email],
));
```

- `Abeon\SDK\Notifications\Notifier` builds the payload, validates it before it is published, and uses
  the service name as the first routing-key segment, with `-` normalised to `_`. It is called inside the
  business transaction like any outbox publish.
- Unified consumes `*.notification.requested` with one handler that shares the write with the
  synchronous path. `source_app` is the envelope's `source`; a payload naming a different application is
  refused and dead-lettered, the same rule the POST learned on 2026-08-14. The organisation is the
  envelope's `org_id`.
- The per-user cap sheds **silently with a warning** on this path, as ADR-0006 specifies. Nobody is
  waiting for an id.
- The synchronous POST stays, for a caller that needs the notification id back. It accepts `channels`
  too.

## Consequences

**Positive:**

- "Send this by email" can be said on the wire before the first emitter exists, with a default that
  changes nothing.
- The email channel, when it comes, is a delivery detail inside Unified: no contract change, no emitter
  change.
- Emitting a notification is one call inside the transaction that caused it, and it survives Unified
  being down.

**Negative / accepted:**

- **The event path needs a running consumer and drainer.** Until the deployment layer runs them,
  notifications wait in the emitter's outbox. They are not lost, but they are not shown either.
- **Preferences are cross-organisation.** A person who wants email from Acme and not from Bravo cannot
  say so. Same trade-off as the feed.
- **No content deduplication.** The consumer drops a redelivered *message* (`abeon_processed_events`);
  ADR-0006's best-effort dedup on `metadata` is still not built.
- **Differs from §5A.3** in three visible ways: the key is `type` (the notification's own field, not
  `event_type`), `in_app` cannot be switched off, and WebSocket is not a channel.

## References

- ADR-0006 (contract), ADR-0019 (owner and the recorded gap), ADR-0002 (envelope), ADR-0018 (tenant from
  the envelope), ADR-0009 (chrome preferences, deliberately separate).
- Architecture doc §5A.2, §5A.3, §5A.6.
