# ADR-0042: Every service keeps an append-only audit log, written through the SDK

**Status:** Accepted (implemented 2026-10-30 in SDK 0.16.0)
**Date:** 2026-10-30
**Builds on:** ADR-0001 (`act` for impersonation), ADR-0003 (correlation id), ADR-0025 §5 (Auth's audit log)

## Context

The CMS PRD (§9 point 3) needs a log of who changed what, with the change itself, the actor and the
person they acted for when impersonating. Revisions record content, but not who moved a page, renamed
it, deleted a category or rotated a webhook secret. The CMS pilot had a minimal `sensitive_actions`
table; manngo, PCC and geoglobe need the same thing, so it belongs in the SDK, not in each application.

Auth already has an audit log (FR-33): actor, `on_behalf_of`, action, target, IP, correlation id,
append-only through a database GRANT (ADR-0025 §5). The GRANT is the right control in production, but
nothing enforces it locally or in CI, and an application user that owns its schema can grant itself
the rights back.

## Decision

### 1. One table per service, in its own database

`abeon_audit_log`, published by the SDK (`vendor:publish --tag=abeon-audit-migrations`): time, actor
(type, id, name), `on_behalf_of`, organisation, action, subject (type, id, label), changes, context,
IP, user agent, correlation id. Per service, like the outbox (ADR-0002) and like an instance's data
(ADR-0031). A platform-wide view is a separate decision.

### 2. Append-only in the database, by triggers

The migration creates two triggers: every `UPDATE` is refused, and every `DELETE` of an entry younger
than 365 days. They hold in every environment, whatever code path asks. `abeon:audit:check` writes a
probe and tries to change and delete it; it is a deploy gate. In production the Helm chart also takes
`UPDATE` and `DELETE` from the application role, as in ADR-0025 §5; with binary logging on, creating
the triggers needs `log_bin_trust_function_creators` or a migration role allowed to create them.

### 3. Retention

`abeon.audit.retention_days`, 730 by default, never below 365. `abeon:audit:prune` is the only path
out of the log.

### 4. What is written

- `AuditLog::record($action, $subjectType, $subjectId, $label, $changes, $context)` inside the
  transaction that made the change.
- The `Audited` trait records a model's creation, changes (field by field, before and after) and
  deletion. A model lists in `$auditExcept` what is not worth an entry; a change to nothing else
  writes nothing.
- Fields whose name says secret, password, token, `key_hash`, API key or private key are recorded as
  `[changed]`. Values longer than 2,000 characters too: the log says what changed, not all it was.
- No personal data from what a service stores on behalf of others (form submissions): the log records
  that someone opened or erased one, not its content. An erasure therefore never has to touch the log.

### 5. Impersonation

With an `act` claim (ADR-0001), the actor is `act.sub`, the person really acting, and `on_behalf_of`
is the token's subject. `User::$actorId` carries it; it is read from the token and is not part of the
user's wire shape. Issuing such tokens is Auth's task (FR-30).

## Consequences

- An application adds the trait to its models and a few explicit `record()` calls for actions that are
  not a model change (a publication, an export, a view of personal data).
- Every entry has a correlation id, so it joins the logs of the request in every service it touched.
- Auth keeps its own `audit_log` for now; moving it onto this module is a later, separate change.
