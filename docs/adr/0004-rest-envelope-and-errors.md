# ADR-0004: REST response envelope and errors

**Status:** Accepted
**Date:** 2026-05-14

## Context

Inter-service REST calls and frontend↔backend API calls need consistent response shapes:

- Success payloads should be self-describing (where is the data? where is pagination meta? where are filters echoed back?).
- Error responses must be machine-parseable across services: `ServiceCallException` needs to lift typed details from upstream service failures into the local exception so middleware/error handlers downstream see consistent shapes.
- TypeScript counterpart (`@abeon/shared`) must mirror the wire format with minimal mapping logic.

Without convention, every controller invents its own JSON layout, and clients write per-endpoint parsers.

## Decision

### Success envelope

All REST success responses use `{data, meta?}`:

```json
// Single resource
{ "data": { "id": "42", "email": "jan@example.com" } }

// Collection
{ "data": [ { "id": "1" }, { "id": "2" } ] }

// Paginated collection
{
  "data": [ /* … */ ],
  "meta": {
    "current_page": 1,
    "per_page": 25,
    "total": 142,
    "last_page": 6
  }
}
```

`meta` is optional and reserved for pagination, aggregate metadata, or echoes of the applied filter/sort. Extra top-level fields are discouraged — wrap them in `meta` or extend the resource shape.

`Abeon\SDK\Http\ApiResponse` is the canonical helper:

```php
return ApiResponse::data(new ContactResource($contact));
return ApiResponse::created(new ContactResource($contact));
return ApiResponse::paginated($paginator);
return ApiResponse::noContent();
```

Canonical schema: [`schemas/http/envelope.json`](../../schemas/http/envelope.json).

### Error format — RFC 7807

All error responses use **RFC 7807 Problem Details** with `Content-Type: application/problem+json`:

```json
{
  "type":     "https://api.abeon.pl/errors/validation",
  "title":    "Validation Error",
  "status":   422,
  "detail":   "The email field is required.",
  "instance": "/api/v1/contacts/42",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

- `type` is a stable URI that identifies the error class. Production URIs may not resolve — they're identifiers, not URLs to follow.
- `title` is a short human-readable summary, constant per `type`.
- `status` mirrors the HTTP status code.
- `detail` is request-specific information.
- `instance` is the URI of the failing request (optional, useful in audit).
- Extension members (like `errors` for field-level validation) are arbitrary and preserved by `ProblemDetails::fromArray()` → `toArray()`.

`Abeon\SDK\Http\ProblemDetailsRenderer` handles the rendering:
- Throwables that extend `AbeonException` use their attached `ProblemDetails`.
- Other throwables get a generic 500 (with debug-mode message exposure controlled by `config('app.debug')`).

`Abeon\SDK\DTO\ProblemDetails` is a value object — readonly, `fromArray()`/`toArray()` symmetric.

### Pagination

Pagination keys are aligned with Laravel's `LengthAwarePaginator`:

- `current_page` (1-based)
- `per_page`
- `total` (overall count)
- `last_page` (= `ceil(total / per_page)`)

`ApiResponse::paginated($paginator)` produces this from any Laravel paginator instance. No cursor pagination in v1 (offset-based is sufficient for the volumes we expect; cursor can be added as opt-in extension when needed).

### URL versioning

All public APIs live under `/api/v1`. Version bumps follow strict rules:

- **Additive changes** (new field, new endpoint, new optional query param) — NO version bump. Stays in `v1`.
- **Breaking changes** (rename/remove field, change semantics, change required params) — new `/api/v2` route group.
- **Coexistence**: v1 and v2 run in parallel for **minimum 3 months**. v1 responses gain `Deprecation: true` + `Sunset: <date>` headers via `abeon.version` middleware (ADR upcoming for VersionHeadersMiddleware, currently in Sprint 3).
- **Maximum 2 versions** in parallel at any time.

### Filtering and sorting (convention, not enforced)

- Filter: `?filter[status]=active&filter[created_after]=2026-01-01`
- Sort: `?sort=-created_at,name` (leading `-` = descending)
- Page: `?page=1&per_page=25`

`Abeon\SDK\Http\QueryParser` (deferred to v0.2 as opt-in helper) will parse these into a query spec. Until then, services parse `$request->query('filter', [])` themselves but should respect these conventions.

## Consequences

**Positive:**
- Frontend `@abeon/shared` has one `PaginatedResponse<T>` type that fits all paginated endpoints.
- `ServiceCallException::fromResponse()` mechanically lifts upstream Problem Details into the local exception — error context preserved across hops.
- v1/v2 coexistence policy gives consumers time to migrate without big-bang upgrades.

**Negative / accepted:**
- Wrapping all success responses in `{data}` adds 7 bytes per response — negligible compared to debugging savings.
- RFC 7807 `type` URIs are stable strings, not real documentation URLs — opportunity for misleading docs if anyone tries to follow them.
- Pagination meta duplicates `last_page` (derivable from total/per_page) — accepted for client simplicity.

## References

- Implementation: `src/Http/ApiResponse.php`, `src/Http/ProblemDetailsRenderer.php`, `src/DTO/ProblemDetails.php`, `src/Exceptions/AbeonException.php`
- Schemas: `schemas/http/envelope.json`, `schemas/http/problem-details.json`
- Versioning middleware: `src/Http/VersionHeadersMiddleware.php`
- Related: ADR-0005 (s2s auth — RFC 7807 lifted across hops), arch doc sekcja 6.1

## References

- **Implemented 2026-08-14** — until then only `AbeonException` rendered as a problem document, so the
  error format above described a shape the platform mostly did not emit. A validation failure came back
  in Laravel's own `{"message":..., "errors":{...}}`, and without an `Accept: application/json` header it
  came back as a **302 to `/`** — from services that have no `web` group, no session and no page there.
  `schemas/fixtures/problem-details.json` had pinned the correct validation shape since the
  beginning and nothing produced it.

  `ProblemDetailsRenderer::register()` now covers `AbeonException`, `ValidationException` (422 with the
  `errors` extension member), `AuthenticationException` (401 instead of a redirect to a `login` route
  that does not exist), anything implementing `HttpExceptionInterface` — which is how
  `NotFoundHttpException` from `firstOrFail()` reaches a client — and, outside debug mode, any remaining
  throwable.

  It is **opt-in**, taken up by `abeon-auth` and `abeon-unified` only. `abeon-boilerplate-inertia` needs
  validation failures to return as a redirect carrying the errors in the session, because that is how
  Inertia forms work, and `abeon-auth-ui` posts native (non-JSON) forms — Blade until 2026-08-17, Inertia-rendered React since, still submitting as ordinary browser POSTs. Gating on `$request->expectsJson()` would
  have fixed nothing: the redirect happens precisely when the caller omits that header.
