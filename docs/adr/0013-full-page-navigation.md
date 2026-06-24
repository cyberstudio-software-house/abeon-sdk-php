# ADR-0013: Full-page navigation between apps (no SPA shell)

**Status:** Accepted
**Date:** 2026-06-23

## Context

The MVP was a single SPA that composed all apps in one React tree: switching apps was instant and
in-memory state was shared across them. The new platform is a set of **independently-deployed
services**, each serving its own frontend, with **no host "Shell App"** (arch doc §3.7,
"Ponieważ nie ma Shell App"). The chrome is *federated* — every app embeds the same `@abeon/ui`
components fed by `@abeon/shared` hooks.

The consequence is a navigation model very different from the MVP's, and it deserves an explicit
record because it sets expectations for every app author (what state survives an app switch, and
what does not).

## Decision

**Navigation between apps is a full browser page load** (a plain `<a href>` / `crossAppHref()`), not
client-side SPA routing. Navigation *within* an app uses that app's own router (e.g. Inertia).

Smoothness comes from, not from a shared runtime, but from:

- **SSO via a shared cookie** — the JWT in an httpOnly cookie on `.abeon.pl` is sent to every app, so
  a cross-app navigation never re-prompts for login.
- **Prefetch** — `<link rel="prefetch">` on sidebar hover for the likely next app.
- **Identical chrome** — `@abeon/ui` renders the same topbar/sidebar everywhere, so visually only the
  content area changes across a load.
- **Skeletons** — each app shows the `@abeon/ui` skeleton layout on boot to avoid a flash of unstyled
  content.
- **Context via URL** — cross-app context is passed as query params (e.g.
  `/finance/invoices?contact_id=123`).

## Consequences

- **Accepted trade:** the MVP's instant switch and shared in-memory state (gap-analysis C4) are not
  reproduced. Each app boots fresh; transient in-memory state (multi-select, unsaved drafts, scroll
  position) does **not** survive an app switch.
- **Context is limited to what fits in the URL.** Anything richer than query params must be persisted
  server-side (e.g. preferences, ADR-0009) or re-fetched. If a real need for richer cross-app handoff
  emerges, the minimal addition would be a small shared client store keyed by correlation id — out of
  scope here.
- **Isolation upside:** apps deploy, version, and fail independently; one app's bundle/runtime cannot
  break another's.

## References

- Arch doc §3.4 (federated chrome), §3.6 (app registry), §3.7 (transitions)
- `@abeon/shared` `crossAppHref()`; ADR-0009 (preferences as the durable cross-app state)
