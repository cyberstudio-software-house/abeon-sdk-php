# ADR-0014: Suite/dashboard composition → `abeon-home` (Phase 2)

**Status:** Accepted (decision now, service in Phase 2)
**Date:** 2026-06-23

## Context

The MVP had two chrome modes: a single-app mode and a **"suite" mode** — a landing dashboard that
aggregated widgets from *many* apps on one screen. The new `AppDescriptor.mode` field
(`"suite" | "app"`, added with the chrome fields in ADR-0010) preserves the ability for an app to
declare it wants the suite/dashboard treatment, but nothing in the platform currently **owns**
cross-app composition.

In a database-per-service, full-page-navigation world (ADR-0013), a screen that shows data from CRM +
Finance + Helpdesk at once cannot read those databases directly — it needs a backend that aggregates
across services. This is a genuine architectural gap (G3 in the gap analysis), and it must have a
named owner so apps don't each invent their own aggregation.

## Decision

**A dedicated Phase-2 service, `abeon-home`, owns cross-app suite/dashboard composition.** It is a
Backend-for-Frontend (BFF): it does not own domain data, it composes it.

- **Aggregation:** `abeon-home` pulls per-app widget data on demand via `ServiceClient`
  (synchronous, ADR-0005 service-to-service auth) and/or maintains lightweight read projections fed
  by domain events (ADR-0002 envelope) for widgets that must be fast.
- **Entitlement:** a widget is shown only if the user is entitled to its `source_app` (same
  permission-prefix model as ADR-0010); `abeon-home` composes only the widgets the user can see.
- **Chrome wiring:** `abeon-home` is itself an app served behind the federated chrome; an app that
  declares `AppDescriptor.mode: "suite"` resolves to the `abeon-home` landing, while `"app"` apps
  render their own content area. `fullscreen` apps bypass the dashboard entirely.

**Not built now.** This ADR records the owner and shape so the `mode` field has a defined meaning and
apps/widgets can be designed against it; the service is scheduled for Phase 2.

## Alternatives considered

- **Drop suite-mode entirely** (each app standalone, no aggregated home). Rejected: the cross-app
  landing was a valued MVP behavior and `mode` already encodes the intent; better to name the owner
  than to silently drop the capability.
- **Compose in the frontend** (the chrome calls each app's API directly and assembles widgets
  client-side). Rejected: leaks N service calls + entitlement logic into every chrome, duplicates
  ranking/merging, and couples the chrome to every app's API — a BFF centralizes it.

## Consequences

- `AppDescriptor.mode` has a defined destination (`abeon-home`) rather than being an unused field.
- Phase 2 gains a clear, bounded service to build; widgets get a contract to target.
- Until `abeon-home` ships, there is no aggregated dashboard — apps render in `"app"` mode and the
  Suite landing is simply not present (graceful absence).

## References

- ADR-0010 (`AppDescriptor.mode`, entitlement model), ADR-0013 (full-page nav / federated chrome)
- ADR-0005 (service-to-service auth), ADR-0002 (event envelope for projections)
