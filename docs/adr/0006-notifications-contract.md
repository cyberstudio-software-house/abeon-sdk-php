# ADR-0006: Notifications contract (REST + WebSocket fan-in)

**Status:** Superseded by [ADR-0019](0019-abeon-unified-service.md) (2026-08-12)
**Date:** 2026-05-15

> **Superseded — but the contract below is still current.** The owning service was renamed and widened:
> `abeon-notifications` became **AbeonUnified**, which also owns the app registry, organisation↔app
> assignment and app data. Every REST endpoint, the `NotificationDto`, the `*.notification.requested`
> fan-in and the `user.{id}` Reverb channel specified here carry forward **unchanged** — read this
> document for the contract and [ADR-0019](0019-abeon-unified-service.md) for the service that owns it.
> ADR-0019 also records the gap this ADR left open: §5A of the architecture doc promises email and push
> channels, but the schemas here are in-app + WebSocket only.

## Context

The federated chrome (see ADR-0010 + arch doc §3.4) renders a `<NotificationCenter>` bell in the topbar of every service. The bell shows a unified, cross-app feed of user notifications with unread badge, optimistic mark-as-read, and live updates via Reverb.

Without a single contract, 16 services would either:

- emit notifications directly into their own UIs (no cross-app aggregation),
- or each invent their own per-user persistence + WebSocket fan-out (drift, duplication).

The chrome's `useNotifications()` hook (in `@abeon/shared/react`, Sprint D) already presumes a stable REST + WS shape — this ADR formalises it so Phase 1 can implement it.

## Decision

A dedicated **`abeon-notifications` service** (Laravel API-only) owns persistence, REST API, and Reverb broadcasting for the unified notification feed. Other services emit RabbitMQ events; `abeon-notifications` consumes them, persists per-user rows, and broadcasts.

### REST endpoints

All endpoints under `/api/v1/notifications` on the `abeon-notifications` service. All require user JWT via `Authorization: Bearer` (per ADR-0001). Responses follow ADR-0004 envelope.

| Method | Path | Purpose | Body | Response |
|---|---|---|---|---|
| `GET` | `/api/v1/notifications` | List paginated notifications for the current user, newest first. Query: `?cursor=<id>&limit=20&unread_only=true`. | — | `{ data: NotificationDto[], meta: { next_cursor?: string, unread_count: int } }` |
| `GET` | `/api/v1/notifications/unread-count` | Lightweight badge poll. | — | `{ data: { unread_count: int } }` |
| `PATCH` | `/api/v1/notifications/{id}/read` | Mark a single notification read. Idempotent. | — | `{ data: NotificationDto }` |
| `POST` | `/api/v1/notifications/mark-all-read` | Mark every unread for the current user as read. | — | `{ data: { marked: int } }` |
| `POST` | `/api/v1/notifications` | **Internal only** — service-to-service via service JWT (ADR-0005). Creates one notification for a target user. | `{ user_id, type, title, body, icon?, action_url?, source_app, metadata? }` | `{ data: NotificationDto }` |

Direct internal POST is the **synchronous** path. The **asynchronous** path is RabbitMQ fan-in (see below). Services should prefer the asynchronous path; the synchronous one exists for cases where the emitter must know the notification ID immediately (rare).

### `NotificationDto` schema

Canonical schema: [`schemas/dto/notification.json`](../../schemas/dto/notification.json).

| Field | Type | Notes |
|---|---|---|
| `id` | string (UUIDv4) | Stable. |
| `user_id` | integer | Target user (matches `User.id`). |
| `type` | string | Free-form per emitter, e.g. `crm.deal.assigned`, `finance.invoice.overdue`. |
| `title` | string | Short headline (≤ 80 chars recommended). |
| `body` | string | Markdown allowed, ≤ 500 chars. |
| `icon` | string \| null | Lucide icon name or URL. Defaults rendered by `<NotificationCenter>`. |
| `action_url` | string \| null | Absolute or path-prefixed URL the bell links to. Use `Abeon\SDK\Support\PathPrefix::absolute()` server-side. |
| `source_app` | string | Emitting service identifier (matches `AppDescriptor.name`). Used by chrome for per-app filtering. |
| `read_at` | string (ISO 8601) \| null | Null = unread. |
| `created_at` | string (ISO 8601) | Set by `abeon-notifications`. |
| `metadata` | object | Free-form, opaque to chrome. Persisted as JSON. |

### Asynchronous fan-in (RabbitMQ)

Every service that wants to emit a notification publishes an event matching topic pattern `*.notification.requested` to exchange `abeon.events`. Envelope conforms to ADR-0002. Payload schema: [`schemas/events/notification-requested.json`](../../schemas/events/notification-requested.json).

```json
{
  "routing_key": "crm.notification.requested",
  "data": {
    "target": { "user_id": 42 },
    "type": "crm.deal.assigned",
    "title": "New deal assigned",
    "body": "Acme Inc. — 50 000 PLN",
    "icon": "briefcase",
    "action_url": "/crm/deals/abc-123",
    "source_app": "crm",
    "metadata": { "deal_id": "abc-123" }
  }
}
```

`abeon-notifications` binds a single queue `abeon-notifications.requested` to `abeon.events` with key `#.notification.requested`. Handler creates a row, broadcasts on Reverb, returns ack. Handler MUST be idempotent on `(source_app, metadata.deal_id, type)` if `metadata` is present (best-effort dedup).

### Live updates (Reverb)

On row creation, `abeon-notifications` broadcasts on private channel **`user.{user_id}`** with event **`NotificationCreated`**. Payload = `NotificationDto`. Channel authorisation is per-service, mounted via the SDK helper in ADR-0008 (`/broadcasting/auth`).

The chrome's `useNotifications()` consumes this channel by default. Other services do not need to subscribe — chrome lives in every app.

### Retention

Notifications older than **90 days** are hard-deleted by a daily job. Read notifications older than **30 days** are hard-deleted earlier. Both retention windows are config keys on `abeon-notifications` (no SDK config).

### Bulk caps

A single emitter may not exceed **100 notifications/sec for one user**. `abeon-notifications` rate-limits per `(user_id, source_app)` and drops with a logged warning; no DLX (it's load shedding, not data loss — the notification is non-essential).

## Consequences

**Positive:**

- Single source of truth for the bell — every chrome instance reads the same feed.
- Asynchronous emit decouples business services from notification latency.
- Per-user-channel Reverb scope keeps fan-out cheap.
- Optimistic mark-as-read in chrome is safe — duplicate `PATCH /{id}/read` is idempotent.

**Negative / accepted:**

- Adds one service dependency to chrome — degraded mode (bell empty + grey) when `abeon-notifications` is down. Chrome must not block on this fetch.
- 100/s per-user cap may bite during bulk imports — emitters needing bulk should batch notifications client-side or use a dedicated digest type (out of scope).
- Cross-app filtering UI (only show notifications from CRM) is enabled by `source_app` but not built in Phase 0.5 chrome.

## References

**2026-08-13 — implemented in `abeon-unified`, with three notes where reality differs from the text above.**

1. **`unread-count` returns `unread_count`, as specified here.** The chrome hook
   (`abeon-shared/src/react/use-notifications.ts`) read `data.count` from its first day, and the
   boilerplate's dev stub was written against the hook rather than against this ADR — so the two agreed
   with each other and with nothing else, and no test could see it while both sides were ours. The hook
   now reads `unread_count` and treats the old field as a missing answer; its fallback of counting the
   loaded page is a *degraded* answer, not an equivalent one, since it only ever sees the first page.
2. **`cursor` is opaque, not an id.** The table above writes `?cursor=<id>`, which cannot work as
   stated: the feed is ordered by time and a UUID carries no order, so "everything after this id" has no
   meaning. The cursor encodes the sort key `(created_at, id)` and is base64url'd to keep clients from
   reconstructing it. The response shape is unchanged — `next_cursor` was only ever typed as `string`.
3. **Ids are UUIDv7, not v4.** `schemas/dto/notification.json` says `format: uuid` and nothing else, so
   this is inside the contract. v7 is time-ordered, which suits an append-only feed; the cursor still
   does not rely on that, because an id scheme is a bad thing for pagination to depend on silently.

Also implemented but not specified here: the internal POST takes `source_app` from the **verified
service token** rather than the body, so a service cannot attribute a notification to another
application; the per-user rate limit and the retention job are not built yet.

**2026-08-14 — the rate limit and the retention job are built, and the cap sheds loudly rather than
silently on the synchronous path.**

- **Retention** (`abeon:notifications:prune`, scheduled daily) applies both windows as written: 90 days
  on age, 30 days on when a notification was read. Config keys are on `abeon-unified`, as this ADR
  requires. Deletion is chunked — a single `DELETE` across ninety days of rows is one long transaction
  holding locks inside `notifications_unread_index`, which is the index every concurrent `unread-count`
  is reading, so the cleanup would stall the bell platform-wide while it ran. `mark-all-read` was
  chunked for the same reason. A retention window of zero refuses to run instead of emptying the table.
- **Bulk caps** are enforced at 100/s per `(user_id, source_app)`, as specified. **The response differs
  from what this ADR says.** "Drops with a logged warning; no DLX" describes the *asynchronous* fan-in,
  where a consumer can drop a message and no caller is waiting on an answer. The synchronous POST is
  kept here "for cases where the emitter must know the notification ID immediately", and the only way to
  drop silently on that path is to answer `201 Created` with an id for a row that was never written —
  a caller that stores or links to that id then fails later and somewhere else. So the synchronous path
  answers **429** and logs the warning; the broker consumer, when it exists, can drop silently on its own
  path, where nobody is waiting. The two are not the same decision and should not share one sentence.
- A ceiling on the user-facing feed was added as well. It is not a contract term — it guards against a
  runaway client loop — and it is keyed by the Abeon user id, not by address, because this service
  authenticates with a platform JWT and never with a Laravel guard, so Laravel's stock throttle would
  put every user on the platform in one address-keyed bucket. Making that keying actually work required
  putting `AuthMiddleware` ahead of `ThrottleRequests` on Laravel's middleware priority list: declaring
  auth first on the route does not make it run first, and until that was fixed the limiter read an empty
  `AuthContext` and fell back to the address on every request, with correct-looking headers throughout.

- Schema: `schemas/dto/notification.json`, `schemas/events/notification-requested.json`
- Chrome consumer: `abeon-shared/src/react/use-notifications.ts`
- Related: ADR-0002 (event envelope), ADR-0004 (REST envelope), ADR-0005 (service-to-service auth), ADR-0008 (broadcasting auth), ADR-0010 (auth /me + /apps)
- Implementation home: `abeon-notifications/` (separate repo, built in Phase 0.5 Sprint S5)

**2026-08-15 — the bell is cross-organisation on purpose, and that is now written down somewhere other
than a migration comment.**

A user who belongs to Acme and to Bravo sees one feed, and switching tenant (ADR-0017) does not change
it. That follows from this ADR describing the bell as a per-user cross-application feed, and from
`schemas/dto/notification.json` having no `org_id` while being `additionalProperties: false` — but the
only place it was ever stated was a comment in `create_notifications_table`, which is not where anyone
looks for a contract.

It is worth knowing what that means in practice: an accountant working in Bravo sees a title and body
about an Acme client, and a user removed from Acme keeps that history in their bell indefinitely, since
nothing in `abeon-unified` learns about membership changes.

**ADR-0018 does not settle this either way.** Its rule — a missing tenant never means "all tenants" —
governs tenant-scoped models, and `Notification` is not one. The migration comment cited it as
justification; it neither permits nor forbids this.

`notifications.org_id` now records which organisation the emitting service was acting for, taken from
the verified service token's `org_id` claim (ADR-0016) and never from the request body. **Nothing reads
it.** It is not in the DTO, nothing filters on it, and there is no index — an index no query uses is a
cost on every insert into the one table here that grows without a ceiling.

It exists so this decision stays reversible. Without the column there is nothing to write a corrective
migration *from*: `source_app` cannot stand in for a tenant, because the same application runs in many
organisations. Scoping the bell is still an ADR decision; it is now one that can be implemented at any
time rather than one that quietly expired.
