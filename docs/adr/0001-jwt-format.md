# ADR-0001: JWT format (user + service)

**Status:** Accepted
**Date:** 2026-05-14

## Context

The Abeon Unified platform spans 16+ microservices. Cross-service authentication must work for two distinct subjects:

1. **End users** carrying short-lived access tokens issued by the Auth service.
2. **Services calling other services** carrying short-lived tokens they sign themselves.

Without an explicit specification, every service would invent its own JWT payload, causing silent drift. Every validator would have to maintain a private mapping. Replays and confusion attacks become easy when claims like `iss`/`aud` are loose.

## Decision

Both token types use **RS256** signed JWTs. Public keys are served from the Auth service's JWKS endpoint at `${auth.url}/.well-known/jwks.json`. Each signing key is uniquely identified by `kid` in the JWT header; JWKS may return multiple keys to support zero-downtime rotation.

### User token claims

Canonical schema: [`schemas/auth/jwt-user.json`](../../schemas/auth/jwt-user.json).

| Claim | Required | Notes |
|---|---|---|
| `sub` | yes | Stable user ID (string). |
| `exp` | yes | Unix timestamp. **15 min** default lifetime. |
| `iat` | yes | Unix timestamp. |
| `iss` | yes | Constant `"abeon-auth"`. |
| `aud` | yes | Constant `"abeon"`. |
| `type` | yes | Constant `"user"`. |
| `email` | yes | RFC 5322 email. |
| `name` | optional | Display name. |
| `roles` | yes | List of role names. |
| `permissions` | yes | List of `{app}.{resource}.{action}` strings (e.g. `crm.contacts.read`). Regex `^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$`. |
| `org_id` | optional | Integer organization ID for multi-org accounts. |
| `jti` | yes | Unique token ID — enables revocation lists. |

### Service token claims

Canonical schema: [`schemas/auth/jwt-service.json`](../../schemas/auth/jwt-service.json).

| Claim | Required | Notes |
|---|---|---|
| `sub` | yes | Service identifier (same as `service_name`). |
| `exp` | yes | Unix timestamp. **5 min** default lifetime. |
| `iat` | yes | Unix timestamp. |
| `iss` | yes | Issuing service name (e.g. `"crm"`). |
| `aud` | yes | Constant `"abeon"`. |
| `type` | yes | Constant `"service"`. |
| `service_name` | yes | Calling service identifier. |
| `jti` | yes | Unique token ID. |

### Validation rules

Every service validates inbound JWTs **locally** using the cached JWKS (no Auth round-trip per request):

1. Header `kid` must match a JWKS key; otherwise flush cache once and retry.
2. Signature verified via RS256 against the resolved JWK.
3. `iss` must equal the configured issuer (`abeon-auth` for user tokens; emitting service name for service tokens — validator inspects `type` to know which to expect).
4. `aud` must equal `abeon`.
5. `exp` not in the past.
6. `type` matches the expected token kind for the endpoint.

Implementation: `Abeon\SDK\Auth\JwtValidator` + `Abeon\SDK\Auth\JwksClient`.

### Key rotation

- Each service holds its private key as a Kubernetes Secret mounted as `ABEON_SERVICE_JWT_PRIVATE_KEY` and a `kid` as `ABEON_SERVICE_JWT_KID`.
- Public keys for all services are aggregated into a single JWKS document served by Auth. Two strategies (current and future):
  - **Current:** shared ConfigMap with all public keys, mounted into Auth pod, exposed at JWKS endpoint.
  - **Future (M7):** K8s label discovery — Auth scrapes services labeled `abeon.io/service=true`, reads `abeon.io/jwks-url` annotation, builds JWKS dynamically.
- Rotation: deploy service with new `(kid, private_key)` pair; old `kid` removed from JWKS after grace period.

### Cookie ↔ Authorization translation (frontend boilerplate)

The Auth service issues the access token as an httpOnly cookie on `.abeon.pl` (cookie name from `auth.cookies.access`, default `abeon_token`). Frontend backends (Laravel + Inertia or Next.js SSR) **must read this cookie and attach the JWT as `Authorization: Bearer <value>`** before calling downstream services. `Abeon\SDK\Auth\AuthMiddleware` only reads the `Authorization` header — it does **not** inspect cookies. See [`@abeon/shared`](../../../abeon-shared/) for the TypeScript helper `createServerApiClient(cookies, headers)` that performs this translation.

## Consequences

**Positive:**
- Local validation = constant-time per request; Auth is not a hot path.
- JWKS multi-`kid` enables zero-downtime rotation.
- Permission name regex catches typos at JWT-issue time and at consumer validation.
- Type claim distinguishes user vs service in audit logs and policy decisions.

**Negative / accepted:**
- 15-minute user token requires refresh token flow (separate refresh cookie). Mitigated by `@abeon/shared` `refreshTokenIfExpired()` helper.
- Compromised service private key has 5-minute exposure until JWKS rotation propagates.
- Permission catalog is federated (each service declares its own via `service.permissions.declared` event) — no central RBAC table. Single source of truth requires aggregation in Auth.

## References

- Implementation: `src/Auth/JwtValidator.php`, `src/Auth/JwksClient.php`, `src/Auth/AuthMiddleware.php`
- Issuer: `src/Client/ServiceTokenProvider.php` (service tokens; user tokens issued by Auth service)
- Schemas: `schemas/auth/jwt-user.json`, `schemas/auth/jwt-service.json`
- Related: ADR-0005 (service-to-service auth flow), arch doc sekcja 5
