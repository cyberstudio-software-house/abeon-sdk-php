# ADR-0041: Calls to client-supplied URLs go through one hardened client

**Status:** Accepted (implemented 2026-10-30, see the note at the end)
**Date:** 2026-10-28
**Amends:** ADR-0036 §3 (webhooks), ADR-0038 (where webhook secrets live)

## Context

The CMS plan makes the platform call URLs that a client types in:

- revalidation webhooks to a front (ADR-0036 §3);
- form actions to any system: Make, Zapier, a CRM;
- event subscriptions for other systems: Slack, a data warehouse;
- a background checker for broken external links in content.

Each of these is a request from inside the cluster to an address chosen by someone outside it. Without
rules, that is server-side request forgery: a "webhook" pointed at Unified's internal API, at MinIO, at
Redis or at a cloud metadata address. If the delivery log shows response bodies, the attacker also gets
to read the answer. The link checker runs on any content an editor with `cms.pages.write` saves.

No ADR covers outbound calls to arbitrary URLs. ADR-0019's doctrine is about platform providers, and
ADR-0038's table has no row for "an address the client entered". The CMS plan also stores webhook
secrets in the instance database, while ADR-0038 says that instance databases hold no client secrets.

## Decision

**Every call to a client-supplied URL goes through `Abeon\SDK\Http\OutboundClient`. It enforces one
policy, and no application calls such URLs with a plain HTTP client.**

### 1. The policy

1. **HTTPS only.** Validation happens when the URL is saved, and again at call time.
2. **Resolve once, check, pin.** The host is resolved, every address is checked, and the connection
   goes to the checked address, so a DNS rebind cannot swap it afterwards. Refused ranges:
   - private networks (RFC 1918 and RFC 4193)
   - loopback and link-local, which includes the metadata address `169.254.169.254`
   - the cluster's pod and service CIDRs (configuration)
   - `0.0.0.0/8` and multicast
3. **No redirects.** A 3xx response is reported as a failure.
4. **Limits:** connect timeout 3 s, total 10 s, and at most 64 KB of the response read.
5. **What is logged:** the status code, the duration and at most 1 KB of the body, shown only to users
   with the permission that configured the URL. The full body is never kept.
6. **Egress:** in production these calls leave through an egress proxy, and network policy blocks
   direct egress from application pods. The address checks still run, because the proxy is a second
   line of defence, not the only one.

### 2. Signing and replay

Each delivery carries:
`X-Abeon-Signature: t={unix},v1={HMAC-SHA256(secret, t + "." + delivery_id + "." + body)}`
and `X-Abeon-Delivery: {delivery_id}`. The integration guide tells receivers to reject signatures older
than 5 minutes and to use the delivery id as an idempotency key.

### 3. Retries and switching off

Retries use exponential backoff, up to 8 attempts over about 24 hours. After 20 consecutive failures the
endpoint is switched off and the users who manage it get a notification (ADR-0028).

### 4. Where the secrets live

- **Secrets the platform generates** (the HMAC secret of a webhook it sends) are stored in the
  application's database, **encrypted** with a key held outside that database. They are shown once and
  then only as the last four characters.
- **Credentials a client brings** from a third party (payment providers, a CRM's API key) stay in
  Unified's vault (ADR-0038).

ADR-0038's sentence "instance databases hold no client secrets" is narrowed to *third-party
credentials*.

### 5. The link checker

The external-link checker uses the same client with a lower limit: `HEAD` first, then `GET` with no body
read. It keeps a per-host rate limit, so content cannot turn the platform into a scanner.

## Consequences

**Positive**

- SSRF is handled in one place for every application. An application that adds webhooks gets the
  protection without writing it.
- Receivers can verify origin, freshness and uniqueness of a delivery.
- A broken endpoint stops consuming retries and somebody is told.

**Negative**

- An egress proxy is one more component to run in production.
- Clients whose systems sit on private networks cannot receive webhooks directly. They need a publicly
  reachable endpoint, which is the normal requirement for webhooks.
- The pinned connection needs care with TLS (SNI must still use the hostname). The SDK test suite must
  cover it.

**Rejected**

- *A denylist of hostnames.* It does not stop DNS rebinding or numeric addresses.
- *Unified as the sender of every webhook* (ADR-0019 style). It would move the CMS's own delivery
  state, retries and logs into a platform service, for calls that carry no platform credential.
- *Storing full response bodies for debugging.* That is the read channel that turns blind SSRF into data
  exfiltration.

## References

- Amends ADR-0036 §3 and narrows ADR-0038 §2 (platform-generated secrets). Uses ADR-0028
  (notifications).
- Product document: `abeon-cms-prd-2026-10-27.md` (integrations, stage 7b; revalidation webhooks,
  stage 6; link checker, stage 4).
- Not implemented on 2026-10-28.

## Implementation note (2026-10-30)

- `Abeon\SDK\Http\Outbound\OutboundClient` with `AddressPolicy`; the cluster's pod and service ranges come from
  `abeon.outbound.denied_cidrs` (`ABEON_OUTBOUND_DENIED_CIDRS`).
- **Amendment to §1.1:** the link checker (§5) may call `http://` addresses. Links in content are often plain
  HTTP, and a check sends no secret and reads no body worth keeping, so the HTTPS rule protects nothing there.
  Every other rule still applies to it: public addresses only, pinned, no redirects, the same limits. Webhooks and
  form actions stay HTTPS only.

