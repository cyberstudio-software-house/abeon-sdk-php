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

## Amendment — 2026-11-05: settings at two levels, status, feature names

Built for Abeon CMS etap 9. What the decision above left open, now settled:

1. **Organisation level — the operator's, in the gateway.** `ai_settings` per organisation: `enabled`
   (off by default), `monthly_cap_usd` and `apps`, the applications allowed to call. Set only with
   `abeon:ai:org {org} --enable|--disable --cap= --apps=`, like the storage quota; the client cannot change
   them. `abeon:ai:usage {org} --month=` shows the spend by application and feature.
2. **Application level — the application's own.** Which features a CMS site uses, its tone and its brand
   glossary live in the application and go into the prompt. The gateway does not know them.
3. **The application names a feature, never a model.** `feature` is `{service}.{name}` (`cms.alt`,
   `cms.translate`); the gateway maps it to a model and a token ceiling in its configuration, so a model
   changes without a release of any application. A feature of another service is refused.
4. **Refusals, in this order, as Problem Details:** `403 ai-disabled`, `403 ai-app-not-enabled`,
   `402 ai-cap-reached`, `422 ai-unknown-feature`; a provider failure is `502 ai-provider-error` and costs
   nothing. The cap is checked before a call, so the last call of a month may overshoot it by its own cost.
5. **`GET /api/v1/internal/ai/status`** tells an application whether it may use AI, the cap and what is
   used this month, so its screens can say why a button is off.
6. **Nothing of the content is kept.** `ai_usage` holds the organisation, service, feature, model, tokens and
   cost. OpenRouter is asked for providers that keep no data (`data_collection: deny`, `zdr: true`).
7. **SDK:** `Abeon\SDK\Ai\Ai::complete($feature, $messages, $options)` and `status()` (kept for a minute in
   the process). The service is `abeon.ai.service` (default `unified`): moving the gateway out of
   AbeonUnified is configuration.

## Amendment — 2026-11-27: the client chooses the model, free models, markup, usage

Supersedes point 3 of the 2026-11-05 amendment where they differ.

1. **The organisation chooses the model per feature.** The gateway keeps a catalogue of OpenRouter models
   (`ai_models`, refreshed daily by `abeon:ai:models`): price per million tokens, context, image input,
   structured output, free. Each feature declares what it needs (`requires`: `image`, `json`); a model is
   offered and accepted only if it can do the feature. The choice lives in `ai_feature_settings`
   (organisation, feature, model); without one the platform default from configuration applies. Any
   suitable model may be chosen; the monthly cap is what limits the cost. A chosen model that leaves the
   catalogue stops the feature with `409 ai-model-unavailable` instead of silently using another model.
2. **Free models are an opt-in mode of the organisation.** Free models usually require that the provider
   may keep and use the content, which the private default (`data_collection: deny`, `zdr: true`) forbids.
   The mode needs the consent of a named user (`free_consent_by`, `free_consent_at`; `422
   ai-free-consent-required` without it) and covers every feature, whole-page translations included. In
   the mode the gateway tries the free models that can do the feature, sends them without the private
   flags, and puts a model that answers 429 or fails on a short break (60 s / 5 min, shared cache).
   When none answers, `free_fallback` decides: `paid` calls the feature's model once, privately; `stop`
   refuses with `503 ai-free-exhausted` and costs nothing. In `stop` mode the cap does not block, since
   nothing is billed.
3. **The client is billed with the markup and sees it.** `ai_usage` keeps the provider cost (`cost_usd`)
   and the billed cost (`billed_usd = cost_usd × ai.markup`, `AI_BILLING_MARKUP`, 1.2). The cap, the status,
   the answers and every usage screen use the billed cost; the provider cost stays with the operator
   (`abeon:ai:usage` shows both).
4. **Usage is the organisation's to see.** `ai_usage` also records the requested model, whether it was
   free, the attempts, the user who asked (id only) and the latency, never content. `GET /api/v1/ai/usage`
   returns the month by application, feature, model and day plus the latest calls: an application sees its
   own (`scope=app`), the platform screen the whole organisation (`scope=organisation`, through Auth
   `GET /api/v1/auth/ai/usage`).
5. **Who may change it.** `cms.ai.manage` for the CMS screens (models, free mode, usage) and
   `core.ai.manage` (owner and admin) for the platform usage screen. The cap, `enabled` and `apps` stay the
   operator's (`abeon:ai:org`).
6. **SDK 0.24:** `Ai::models()`, `Ai::settings()`, `Ai::updateSettings()`, `Ai::usage()`; `complete()` takes
   `user_id` and returns `free` and `attempts`.

Known gap: the Agent fork calls OpenRouter directly with its own key, outside the gateway; it has to move
to `Ai::complete()` before it serves clients.

## References

- Depends on: ADR-0019 (AbeonUnified — the doctrine and the owning service), ADR-0016 (organisation is
  the metering dimension)
- Related: ADR-0005 (service-to-service auth), ADR-0004 (Problem Details for quota errors)
- Implementation: `Abeon\SDK\Client\ServiceClient::service('unified')`; config key `abeon.services.unified`
- Status account: `abeon-concept-status-2026-08-12.md` §5
