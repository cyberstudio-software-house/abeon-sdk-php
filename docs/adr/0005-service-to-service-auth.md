# ADR-0005: Service-to-service authentication

**Status:** Accepted
**Date:** 2026-05-14

## Context

Some calls inside the cluster aren't on behalf of a user — CRM asks Finance for billing details during a background job; Notifications looks up sender profile in Auth without an active user session; ES Indexer pulls fresh data from CRM to repair an index.

These calls need authentication that:

1. Identifies the **calling service** (for audit, rate limiting, policy decisions).
2. Doesn't fall back to anonymous "trust the network" (K8s network policies help, but a compromised pod in the namespace gains too much).
3. Doesn't piggyback on a user JWT (no user is involved; reusing user creds would conflate audit trails).
4. Doesn't require a central token-issuing service per call (would make Auth a bottleneck for every internal call).

## Decision

### Each service signs its own service JWT

Each Laravel service holds:
- A **private RS256 key** (PEM) in a Kubernetes Secret, mounted as `ABEON_SERVICE_JWT_PRIVATE_KEY`.
- A **`kid`** matching the public key entry in Auth's JWKS, mounted as `ABEON_SERVICE_JWT_KID`.

`Abeon\SDK\Client\ServiceTokenProvider` issues short-lived (5 min default, 30s refresh margin) tokens on-demand:

```json
{
  "iss": "crm",
  "sub": "crm",
  "aud": "abeon",
  "type": "service",
  "service_name": "crm",
  "iat": 1735689600,
  "exp": 1735689900,
  "jti": "uuid"
}
```

Schema: [`schemas/auth/jwt-service.json`](../../schemas/auth/jwt-service.json) (see ADR-0001).

The token is **cached in-process** by the provider — repeated calls within the TTL window reuse it. Cache flushes when refresh margin is reached.

### Acting on behalf of an organisation (ADR-0016)

A service call made while serving a user request belongs to that user's organisation, and the callee
needs to know which one — otherwise the tenant dimension is lost at every service boundary.

The service token therefore carries an **optional `org_id`**:

```json
{
  "iss": "crm", "sub": "crm", "aud": "abeon", "type": "service",
  "service_name": "crm", "org_id": 42,
  "iat": 1735689600, "exp": 1735689900, "jti": "uuid"
}
```

- **Present** when the call originates from an organisation-scoped context — propagate the `org_id` of
  the inbound user token (`AuthContext::orgId()`).
- **Absent** for genuinely organisation-less work: registry self-registration, health probes, scheduled
  maintenance. Absent means "no organisation", never "all organisations" — a callee that needs a tenant
  and finds none must refuse, consistent with ADR-0018's fail-closed rule.

**Cache consequence.** `ServiceTokenProvider` caches exactly one token process-wide (deliberately, for
Octane worker reuse). An organisation-scoped token cannot share that slot: the cache must be **keyed by
`org_id`**, with the organisation-less token as its own entry. Getting this wrong leaks one
organisation's token into another organisation's request — a same-service, wrong-tenant call that
`AuthMiddleware` would happily accept.

### Validation by callee

The callee validates the token using `Abeon\SDK\Auth\JwtValidator`:

1. Header `kid` resolves to a JWK in `JwksClient::keys()` (cached, with single retry on miss to handle rotation).
2. Signature verified RS256.
3. `aud = "abeon"`, `type = "service"`.
4. `exp` not in the past.

If validation succeeds, the call is allowed. The callee can additionally check `service_name` against a per-route allowlist (out of scope for SDK; service-specific policy).

### JWKS aggregation — current and future

**Current (v1):** A shared Kubernetes ConfigMap contains all services' public keys with their `kid`s. The ConfigMap is mounted into the Auth pod, which exposes it at `${auth.url}/.well-known/jwks.json`. Adding a new service requires updating the ConfigMap as part of deploy.

**Future (M7, deferred):** K8s label-based discovery. Auth scrapes all Services labeled `abeon.io/service=true`, reads `abeon.io/jwks-url` annotation, builds JWKS dynamically. Adding a service = label + annotation; no Auth redeploy.

The decision to defer M7 is conscious — the ConfigMap approach is acceptable at the current service
count, and the future migration is mechanical (Auth changes, no service changes).

> **Corrected 2026-08-12 (ADR-0016).** This deferral was originally justified by *"current single-tenant
> scope"*. That reasoning no longer applies and, on inspection, never really did: the ConfigMap holds
> **service** public keys, which are per service and not per organisation. Multi-tenancy does not change
> the number of entries or how often they change, so the deferral stands — on the honest grounds above,
> which are about service count rather than tenancy.

### Rotation

- **Routine rotation:** redeploy service with new `(kid, private_key)` pair. Old `kid` removed from JWKS
  after a grace period of **at least the consumer JWKS cache TTL plus one access-token lifetime**.
  The binding quantity is the *cache*, not the token: a consumer that never misses its cache keeps a
  retired key usable for a full TTL. `JwksClient`'s TTL is configurable via
  `abeon.auth.jwks_cache_ttl`; with its 3600 s default the grace period is ≥ 75 minutes, not the
  10 minutes an earlier reading of this ADR implied.
- **Compromised key:** redeploy immediately with new pair, remove old `kid` from JWKS. Maximum exposure window = remaining TTL of in-flight tokens (≤ 5 minutes).

JWKS naturally supports multiple `kid`s simultaneously — this enables zero-downtime rotation.

### What this is NOT

- **Not** mutual TLS — that's a network-layer concern, complementary not replacement. Service mesh (Linkerd/Istio mTLS) can be layered on top without affecting this design.
- **Not** capability-based — service JWTs don't carry permissions. Authorization at the callee is by service identity (`service_name`) + per-route policy, not by claims embedded in the token.
- **Not** a session — every call issues a fresh JWT (within cache TTL). No revocation list. Compromise window is bounded by token TTL.

## Consequences

**Positive:**
- Auth is not a bottleneck — services don't call Auth per internal call. Auth only needs to be reachable to update JWKS and to validate user tokens.
- Service identity is cryptographically attested (signature) rather than network-position-attested.
- Rotation is well-defined and zero-downtime.
- Audit logs gain a real `service_name` per call — much better than "internal call from some pod".

**Negative / accepted:**
- Each service must hold a private key — operational complexity in K8s Secret management. Mitigated by standardized env vars and Helm template.
- ConfigMap-based JWKS requires Auth redeploy when adding a service. Acceptable in v1 (deploys happen anyway). M7 deferred to remove this friction.
- Compromised service key has up to 5-min exposure. Reduce by lowering `ttlSeconds` in `ServiceTokenProvider`, at the cost of more frequent signing.
- No revocation list — relies entirely on short TTL. If 5 minutes is too long for a specific event, the service can flush its `ServiceTokenProvider` cache; that doesn't revoke already-issued tokens but stops new ones with the compromised key.

## References

- Implementation: `src/Client/ServiceTokenProvider.php`, `src/Client/ServiceClient.php`, `src/Auth/JwtValidator.php`, `src/Auth/JwksClient.php`, `src/Auth/ServiceAuthMiddleware.php`
- Schema: `schemas/auth/jwt-service.json`
- Config keys: `ABEON_SERVICE_JWT_PRIVATE_KEY`, `ABEON_SERVICE_JWT_KID`, `ABEON_AUTH_JWKS_URL`
- Related: ADR-0001 (JWT format both kinds), arch doc sekcja 5.4
- **Amended 2026-08-12 by ADR-0016** (multi-tenant organisations): the service token gained an optional
  `org_id` for on-behalf-of calls, and `ServiceTokenProvider`'s process-wide cache must be keyed by it.
  See also ADR-0018 (fail-closed when a tenant is required and absent).
- **Amended 2026-08-13 by [ADR-0025](0025-auth-service-invariants.md)** (Auth service invariants): the
  rotation grace period is now derived from the **consumer JWKS cache TTL**, not from the token TTL. The
  original "≥ 2× token TTL = 10 minutes" understated it — `JwksClient` caches for 3600 s by default, so a
  consumer that never misses its cache keeps a retired `kid` usable for an hour. The TTL also became
  configurable (`abeon.auth.jwks_cache_ttl`); it was previously a constructor default that operations
  could not change. ADR-0025 additionally defines what the aggregated JWKS endpoint does when the
  ConfigMap is missing or malformed: serve Auth's own keys, 200, alert — but fail startup on a duplicate
  `kid`, which would make validation non-deterministic.

- **Amended 2026-08-13** — the SDK now ships the *receiving* half. Until AbeonUnified needed an internal
  endpoint, this ADR was implemented on the calling side only: `ServiceTokenProvider` minted the tokens
  and `ServiceClient` attached them, but nothing validated one on arrival. `AuthMiddleware` calls
  `decodeUser()`, which asserts `type: "user"` and therefore **rejects every service token**, so the
  first service with an internal route would have written its own check — and the sixteenth would have
  written a slightly different one. `Abeon\SDK\Auth\ServiceAuthMiddleware` (alias `abeon.service`)
  enforces this ADR's validation list and exposes the verified `service_name` as a request attribute.
  Deciding *which* services may call a given route stays per-route policy, as specified above.

- **Corrected 2026-08-14** — `JwtValidator` asserted `iss === abeon-auth` for *every* token, including
  service ones. The validation list above deliberately checks `kid`, signature, `aud`, `type` and `exp`
  and **not** `iss`, because a service token is self-signed by its caller and carries that caller's name
  (`"iss": "crm"` in the example above; `schemas/auth/jwt-service.json` types it as "Issuing service
  name"). The result was that the SDK minted service tokens the SDK could never accept — invisible until
  a real client called a real service, because both the middleware's tests and the manual drives minted
  tokens with the platform issuer, a shape `ServiceTokenProvider` never produces. The validator now
  requires `iss === service_name` for service tokens, so a caller cannot claim to be another service,
  and the platform issuer only for user tokens (ADR-0001).
