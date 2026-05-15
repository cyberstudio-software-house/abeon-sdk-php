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

The decision to defer M7 is conscious — current single-tenant scope makes the ConfigMap approach acceptable, and the future migration is mechanical (Auth changes, no service changes).

### Rotation

- **Routine rotation:** redeploy service with new `(kid, private_key)` pair. Old `kid` removed from JWKS after a grace period (≥ 2× token TTL = 10 minutes minimum).
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

- Implementation: `src/Client/ServiceTokenProvider.php`, `src/Client/ServiceClient.php`, `src/Auth/JwtValidator.php`, `src/Auth/JwksClient.php`
- Schema: `schemas/auth/jwt-service.json`
- Config keys: `ABEON_SERVICE_JWT_PRIVATE_KEY`, `ABEON_SERVICE_JWT_KID`, `ABEON_AUTH_JWKS_URL`
- Related: ADR-0001 (JWT format both kinds), arch doc sekcja 5.4
