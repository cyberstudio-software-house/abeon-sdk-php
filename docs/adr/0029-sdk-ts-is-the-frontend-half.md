# ADR-0029: `@abeon/sdk-ts` is the frontend half of the contract

**Status:** Accepted
**Date:** 2026-09-17

## Context

The TypeScript package started as `@abeon/shared` in architecture doc v1.0 (§3.5): types, an API client
that reads the JWT from the cookie, `useAuth()`, token refresh, and event types "for validation on the
frontend". Every business module was specified as a Laravel application (§4.1), including the four with a
Next.js frontend, which "have a separate Laravel backend (API-only)". Everything a *service* needs — JWT
middleware, the service-to-service client, event publishing and consuming, health — was specified only
for the PHP `abeon/sdk` (§4.2).

No ADR ever stated the package's scope. The rename to `abeon-sdk-ts` on 2026-09-07 (commit `3423a81`) was
about the name, but it made the pair look symmetrical, and the README called the package "the TypeScript
counterpart to `abeon/sdk`". That invites a reasonable question: can an application written in TypeScript
plug into the platform with `@abeon/sdk-ts` the way a Laravel one does with `abeon/sdk`? Today it cannot,
and nobody decided whether it should.

## Decision

**`@abeon/sdk-ts` is the frontend half of the platform contract.** It serves browser code, React hooks
and the server-side render of a frontend (Next.js SSR, Inertia pages). It is not a service runtime.

| Belongs in `@abeon/sdk-ts` | Belongs only in `abeon/sdk` |
|---|---|
| DTO types and schemas mirrored from `abeon/sdk` | Service JWT signing and `ServiceAuthMiddleware` |
| Browser and SSR API clients carrying the **user's** token | `ServiceClient` (service-to-service) |
| User JWT checks during SSR (`/server`) | Event publishing, the outbox and its drainer |
| Chrome hooks: auth, tenant, apps, store, preferences, pins, notifications, administration | Event consuming, dead-lettering, processed-event tracking |
| Command registry, search provider, cross-app links | Health checks, registry self-registration, permission declaration |
| | Tenant scoping of **data** (ADR-0018) |

- An application with a TypeScript frontend has a **Laravel backend with `abeon/sdk`**, as §4.1 says.
  A Next.js application is a Next.js frontend plus a Laravel API.
- A type for a backend contract (`ServiceJwtPayload`, `EventEnvelope`) may live here for completeness.
  Its presence is not a promise of an implementation.
- The `/server` entry point exists for SSR. It forwards the user's credentials; it does not mint
  service tokens.

**When to revisit.** When a real application needs a backend that cannot be Laravel. That is a new ADR
superseding this one, and it has to answer who maintains two implementations of the service contract and
how they are kept equal — at minimum, contract tests on the shared fixtures in `schemas/fixtures/` for
every backend capability, not only for DTOs.

## Consequences

**Positive:**

- One implementation of the service contract, one place its security rules are enforced.
- The question "does a TypeScript app need anything else?" has a written answer: a Laravel API.

**Negative / accepted:**

- A team that wants a Node-only backend has to stand up a Laravel API anyway.
- The Next.js boilerplate the architecture doc lists for Phase 0 still does not exist, so the TypeScript
  path is specified but not templated.

## References

- Architecture doc v1.0 §3.5, §4.1, §4.2, §13; decision #2 in §16.
- `abeon-sdk-ts/README.md`, `abeon-sdk-ts/docs/ARCHITECTURE.md` §1.
- ADR-0026 (hooks in the SDK, screens in the boilerplate).
