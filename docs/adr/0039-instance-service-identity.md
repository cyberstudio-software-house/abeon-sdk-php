# ADR-0039: Each instance signs with its own key, bound to its organisation

**Status:** Proposed
**Date:** 2026-10-28
**Amends:** ADR-0005 (service keys), ADR-0031 §6 (closes the deferred item)

## Context

ADR-0005 gives each *service* one RS256 key and a `kid` in Auth's JWKS. ADR-0031 then made a business
application run as one instance per client, and recorded the consequence honestly in §6:

> A service token identifies the instance (`cms@acme`), and the receiving side checks that the token's
> `org_id` belongs to that identity. Until then an instance can act for any organisation — acceptable
> only while every instance is first-party code.

"Until then" is still today. In the local stack every instance of an application is configured with the
same `ABEON_SERVICE_JWT_KID` and `ABEON_SERVICE_JWT_PRIVATE_KEY` (`docker-compose.apps.yml`, the
`boilerplate` service: `boilerplate-dev`, `${BOILERPLATE_SVC_KEY}`). The receiving side takes the
organisation from the token's own claim: `StorageController::callerOf` reads
`ServiceAuthMiddleware::ATTRIBUTE_ORG_ID` and signs for that container. Whoever holds one instance's key
can therefore write any `org_id` into a token and be believed.

ADR-0035 §3 already called this "load-bearing rather than deferred". Three decisions since then raise
the stakes:

- **ADR-0037**: files are signed per organisation by Unified on the strength of that claim.
- **ADR-0038**: each client's third-party credentials sit in Unified's vault, readable by
  `(org_id, service)` taken from the token. One leaked instance key would read every client's keys for
  that application.
- **The CMS** (`abeon-cms-prd-2026-10-27.md`) makes instances parse anonymous uploads (CVs, images) with
  native libraries. That is the most likely way an instance gets compromised.

## Decision

**Every business-application instance has its own signing key. The public half is registered with the
organisation and service it is bound to, and every receiver refuses a token whose claims do not match
that binding.**

### 1. One key per instance, minted at provisioning

The provisioner (ADR-0022, ADR-0031 §5) generates an RS256 key pair when it creates an instance. The
private key goes into that instance's secret as `ABEON_SERVICE_JWT_PRIVATE_KEY`. The `kid` is
`{service}@{org_slug}`, for example `cms@acme`. Nothing else holds the private key.

Platform services (Auth, AbeonUnified) keep their own service-wide keys. They legitimately act for many
organisations, and their tokens carry no organisation binding.

### 2. The public key is registered with its binding

Auth keeps a registry of service keys in its database instead of the ConfigMap ADR-0005 deferred (M7).
Each row holds the `kid`, the public key, the `service`, the bound `org_id` (null for a platform
service), the state (`active`, `retiring`, `revoked`) and timestamps. The provisioner registers the key
through an internal endpoint authenticated as the provisioner.

The published JWKS gains two members on each key: `abeon_service` and `abeon_org_id`.

### 3. Receivers check the binding, not only the signature

`ServiceAuthMiddleware` in the SDK, after the checks ADR-0005 already lists, refuses a token when:

- the key's `abeon_service` differs from the token's `service_name`; or
- the key has an `abeon_org_id` and the token's `org_id` differs from it, or is missing.

The check lives in the SDK, so Unified, Auth and every service that accepts service tokens get it at
once. It fails closed. A key with no binding signs tokens only for the services listed as platform
services.

### 4. Rotation and revocation

A new key is registered as `active` and the old one moves to `retiring` for the JWKS cache lifetime plus
the token lifetime (ADR-0005). `revoked` removes a key from the JWKS at once. Revoking a compromised
instance therefore affects only that client.

## Consequences

**Positive**

- A compromised instance can act only for its own organisation: its own files, its own vault entries,
  its own events.
- ADR-0031 §6's deferred item is closed. ADR-0037 and ADR-0038 now rest on an enforced boundary instead of
  a convention.
- Revocation and rotation can be done per client.

**Negative**

- The provisioner gains a key-management step, and Auth gains a key registry table and a registration
  endpoint. That is ADR-0005's M7, done earlier than planned.
- JWKS grows with the number of instances, which is acceptable at hundreds of entries. The cache TTL
  becomes a revocation parameter.
- The local stack needs a key per instance. Generating it is part of `tools/provisioner.py`.

**Rejected**

- *Trusting the network or the ingress to separate instances.* A compromised pod in the namespace sees
  the same network. ADR-0005 rejected this reason already.
- *An `org_id` lookup on every receiving call* ("which organisation is this caller allowed to be?").
  It is the same check made remotely and per request. The binding in the JWKS answers it from a cache.
- *Leaving it until the first third-party instance.* ADR-0038's vault turns one leaked key into every
  client's payment credentials. That cost does not wait for third-party code.

## References

- Closes the deferred item in ADR-0031 §6. Amends ADR-0005 (keys move from the ConfigMap to Auth's
  registry, i.e. M7). Required by ADR-0035 §3, ADR-0037 and ADR-0038.
- Code today: `abeon-unified/app/Http/Controllers/Internal/StorageController.php` (`callerOf`),
  `abeon-sdk-php/src/Auth/ServiceAuthMiddleware.php`, `docker-compose.apps.yml` (one key per service).
- Must be implemented before CMS stage 7b (the vault in use) and should be before the first client pilot
  (`abeon-cms-prd-2026-10-27.md`, chapter 9).
- Not implemented on 2026-10-28.
