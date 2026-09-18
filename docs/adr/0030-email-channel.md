# ADR-0030: The e-mail channel and transactional messages

**Status:** Accepted
**Date:** 2026-09-18

## Context

ADR-0028 put `channels` in the notification contract and preference rules in AbeonUnified, but nothing
sends mail: `email` is recorded on the row and dropped. Everything downstream of that gap is stuck —
FR-3 (password reset), FR-4 (address verification) and the delivery half of FR-16 (invitations), which
today hands the administrator a link to pass on by hand.

Two things still had to be decided.

**A notification is not a message.** A notification targets a user who has an account, and the user's
preferences decide whether it also goes out by mail. An invitation targets an address that may have no
account at all, and a password reset must arrive whatever the recipient switched off. Squeezing both
into one shape breaks each of them: preferences would suppress a reset, and a notification would need
a recipient it cannot have.

**A redelivered event must not send a second mail.** Events are at-least-once (ADR-0002), the outbox
drains after a crash, and `ServiceClient` mints a fresh `Idempotency-Key` per call — so nothing in the
platform stops a second copy of the same message on its own.

## Decision

### 1. Transactional messages are their own contract

`{service}.message.requested`, published through the outbox, payload
[`schemas/events/message-requested.json`](../../schemas/events/message-requested.json):

```json
{
  "template": "auth.invitation",
  "to": { "email": "anna@acme.pl", "name": "Anna Nowak", "user_id": null },
  "locale": "pl",
  "data": { "organisation": "Acme", "link": "https://auth.abeon.pl/invitation/…" },
  "idempotency_key": "auth.invitation:1842"
}
```

- **The template name carries its service** (`auth.invitation`, never `invitation`), so two services
  cannot fight over one name in AbeonUnified.
- **`idempotency_key` comes from the business fact** that caused the message, never from a random
  generator. AbeonUnified stores it unique: the second arrival returns the first row and sends nothing.
- **`locale` is `pl` or `en`** — the two the platform renders. A third means a third set of templates,
  which is a decision, not a parameter.
- **`data` never carries a password.** ADR-0022's rule stands: accounts are set up through a one-time
  link, and no plaintext credential is generated, transmitted or logged.
- Preferences (ADR-0028 §2) **do not apply**. They govern notifications; a reset link is not one.

`Abeon\SDK\Messaging\Messages::send(MessageRequest)` is how a service publishes it, inside the
transaction that caused it. No service ever talks to the mail provider — that is ADR-0019's doctrine,
and it is why Auth has no mail credentials of its own (ADR-0022 §6).

### 2. AbeonUnified owns rendering and delivery

- Templates live in Unified (`resources/views/mail/{locale}/…`) behind a registry that names the
  required `data` keys. An unknown template is **dead-lettered**, not rendered with holes.
- A `messages` row records `template`, recipient, `status` (`queued`, `sent`, `failed`, `suppressed`),
  `error` and `sent_at`. Sending is a queued job, so a slow provider cannot block the consumer.
- A **suppression list** (bounces, complaints, opt-outs) is checked before sending; a suppressed
  address records `suppressed` rather than failing the caller.
- **Nothing is mailed retroactively.** Rows recorded before the channel existed — notifications with
  `email` in `channels` from ADR-0028 — are not swept up when it lands.

### 3. Notifications reach the same path

When `RecordNotification` resolves `email` among the effective channels, it queues a message with the
`unified.notification` template. One delivery mechanism, one place where a provider is configured.

## Consequences

**Positive:**

- FR-3, FR-4 and FR-16's delivery half are unblocked; the administrator stops being the transport.
- A service asks for a message in one call inside its own transaction, and survives Unified being down.
- The provider stays behind one service: rotation, spend limits and reputation have one home.

**Negative / accepted:**

- **The queue worker is now load-bearing.** Without `queue:work` on the `mail` queue, messages stay
  `queued` and nobody is told. It joins the drainer and the consumer as a process the deployment layer
  must run.
- **Templates are Unified's.** A new message means a change in Unified, not only in the calling service.
  The alternative — each service sending rendered HTML — gives every application its own look and puts
  the mail layout in sixteen repositories.
- **Bounce webhooks are not built yet.** The suppression list exists and is honoured, but only entries
  written by hand or by a later webhook handler land in it.

## References

- ADR-0019 (Unified fronts outbound integrations), ADR-0022 §6 (Auth gets no mail credentials),
  ADR-0028 (channels and preferences), ADR-0002 (outbox, at-least-once delivery).
- Architecture doc §5A.2; `abeon-auth-spec.md` FR-3, FR-4, FR-16.
