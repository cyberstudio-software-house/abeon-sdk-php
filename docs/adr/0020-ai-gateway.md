# ADR-0020: AI gateway

**Status:** Accepted
**Date:** 2026-08-12

## Context

The product direction is AI-first: the first two applications on the platform are an AI CMS and an AI
CRM, both drawing on OpenRouter. The platform has no AI anything today — no contract, no gateway, no
cost model, no prompt or evaluation story — and the roadmap had placed "smart extras" in Phase 3.

Two questions had to be answered before any application can call a model.

**Where does the call go?** The concept graph drew applications calling OpenRouter directly. ADR-0019's
doctrine says otherwise: shared external infrastructure gets a service in front of it.

**Whose key is it?** Clients could bring their own OpenRouter key, or the platform could use one key
and bill clients for usage. The commercial decision is the second: **our platform key, clients billed
with a markup on tokens.** That decision is what makes the gateway mandatory rather than stylistic —
metering, quota enforcement and cost attribution per organisation cannot be done in sixteen
independently deployed applications.

## Decision

**OpenRouter is reachable only through an AI gateway inside AbeonUnified.**

1. **Endpoint:** `POST /api/v1/ai/completions` on AbeonUnified, service-JWT authenticated (ADR-0005),
   carrying the calling user's organisation (ADR-0016). Request and response shapes are OpenRouter's,
   narrowed to what the platform supports — the gateway is a policy layer, not a translation layer.
2. **The platform key never leaves Unified.** No application, and no part of the SDK, holds an
   OpenRouter credential.
3. **Every call is metered per organisation** — tokens in, tokens out, model, cost — and attributed for
   billing. This is the gateway's reason to exist; it is not optional instrumentation.
4. **Quotas and spend caps are enforced at the gateway**, per organisation. Exceeding one returns a
   Problem Details error (ADR-0004), not a partial result.
5. **Applications call it through the existing `ServiceClient`:**
   `$client->service('unified')->post('/api/v1/ai/completions', …)`. The SDK gains no dependency on
   OpenRouter, and no new transport — `ServiceClient::service()` already resolves through the config
   `services` map, so this is configuration plus optional typed sugar.

### Reserved, deliberately not built

Both of these are forward commitments on the contract's shape. They are recorded now so that honouring
them later is not a redesign.

- **Streaming.** `POST /api/v1/ai/completions/stream` is reserved for server-sent events. Assistant and
  chat interfaces will most likely want it, but it is not confirmed, and proxying SSE end-to-end is a
  materially larger build than a JSON endpoint. Request/response ships first.
- **Bring-your-own key.** The gateway must be able to resolve a **per-organisation key override**
  without changing the application-facing contract — the request shape stays identical, and only key
  resolution and billing treatment differ. This is what a client demanding their own OpenRouter account
  would require, and it implies a per-organisation third-party credential store that does not exist
  today.

## Consequences

**Positive:**
- One place holds the key, meters usage and enforces spend. Rotating the key is one deployment.
- Model choice, fallbacks and prompt-level policy can change centrally without touching applications.
- Billing has a single, trustworthy source of usage data — which is a hard requirement of the markup
  model, not a convenience.

**Negative / accepted:**
- Every AI call takes an extra hop. Acceptable for request/response; it is the main reason streaming
  needs its own design rather than falling out for free.
- Unified becomes latency-sensitive and on the critical path of two applications' core value. Its
  availability budget must reflect that.
- No prompt management, evaluation or model-versioning story is specified here. That is real work and
  is deliberately out of scope for this ADR.
- The concept graph's arrow from Abeon AI CMS directly to OpenRouter is **wrong** and should be
  redrawn through Unified.

## References

- Depends on: ADR-0019 (AbeonUnified — the doctrine and the owning service), ADR-0016 (organisation is
  the metering dimension)
- Related: ADR-0005 (service-to-service auth), ADR-0004 (Problem Details for quota errors)
- Implementation: `Abeon\SDK\Client\ServiceClient::service('unified')`; config key `abeon.services.unified`
- Status account: `abeon-concept-status-2026-08-12.md` §5
