# ADR-0007: Search and command registry (Cmd+K)

**Status:** Accepted
**Date:** 2026-05-15

## Context

The federated chrome (arch doc §3.4) ships a Cmd+K palette in every service's topbar. The MVP at `abeon-shell-unified/src/components/layout/Topbar.tsx` demonstrates per-context filtering ("all" / "offers" / "orders" / "pages" / "files" for the suite; "contacts" / "companies" / "opportunities" for CRM) plus recent searches and suggestions.

Two implementation models exist:

1. **Cross-app full-text search service** — a dedicated `abeon-search` (or the planned `abeon-es-indexer`) that exposes `GET /api/v1/search?q=...&source=*` aggregating all indexed entities.
2. **Per-service command registry** — each service's frontend registers its own commands and search providers; the palette runs them locally and presents a unified UI.

Option 1 is a Phase 2 effort (requires the ElasticSearch indexer service, schema federation, ranking). Option 2 is achievable in Phase 0.5 and covers ~80% of the MVP's actual Cmd+K usage (navigation + recent + per-app entity lookup).

## Decision

**Phase 0.5 adopts Option 2 only — a per-service command registry consumed by `<CommandPalette>` in `@abeon/ui`.** Cross-app full-text search defers to Phase 2 and will integrate as one more provider behind the same palette UI when ready.

### Registry shape

Each service registers commands via `@abeon/shared/react` `useRegisterCommands()` (Sprint S2). Commands have:

| Field | Type | Notes |
|---|---|---|
| `id` | string | Stable, app-scoped, e.g. `crm.nav.contacts`. Used for deduplication. |
| `title` | string | Shown in palette row. |
| `subtitle` | string \| null | Secondary line, e.g. group, breadcrumb hint. |
| `group` | string | UI grouping label: "Navigation", "Recent", "Actions", "Search results", etc. Free-form. |
| `icon` | string \| null | Lucide icon name. |
| `keywords` | string[] | Extra search-match terms. |
| `shortcut` | string \| null | Optional keyboard hint, e.g. `g c`. Display only — binding is the consumer's job. |
| `score` | number \| null | Optional manual ranking hint (higher = sticks to top). |
| `run` | function | What happens on selection — `(ctx) => void`. Receives `{ close, router }` context. |

Registries are React-scoped (per provider), live in memory, garbage-collected on unmount. There is no global window object.

### Search providers (async)

A registered command can be a *search provider* — invoked on every keystroke with the query string, returns more commands:

```ts
useRegisterCommands([
  {
    id: 'crm.search.contacts',
    title: 'Search CRM contacts',
    group: 'Search',
    async provider(query) {
      const { data } = await api.get('/crm/api/v1/contacts/search', { params: { q: query } });
      return data.map((c) => ({
        id: `crm.contact.${c.id}`,
        title: c.name,
        subtitle: c.company,
        icon: 'user',
        run: () => router.visit(`/crm/contacts/${c.id}`),
      }));
    },
  },
]);
```

The palette debounces queries (250ms default) and merges results in. Providers run **only** for the currently active service — palette does not call other services' endpoints. Cross-app *navigation* commands stay registered (e.g. "Open Finance", "Open CRM"), but cross-app *entity search* is the Phase 2 problem.

### Built-in commands shipped with the chrome

`@abeon/ui` `<CommandPalette>` registers these without service code:

- **App navigation** — one entry per `useApps()` entry, with `run: () => location.assign(crossAppHref(app))`. Group: "Apps".
- **User menu items** — Profile, Logout, theme toggle. Group: "Account".
- **Theme toggle** — Group: "Account".

Per-service code only registers service-specific commands.

### Recents

Recent palette selections are stored in `localStorage` keyed `abeon-cmdk-recents` (capped 20). No backend dependency.

### Keyboard binding

Cmd+K (macOS) / Ctrl+K (everywhere else) opens the palette. `<CommandPalette>` owns the listener and exposes an imperative `open()` for app code that wants to trigger it from a button.

## Consequences

**Positive:**

- Ships in Phase 0.5 with **no new backend service** required.
- Each app's commands live next to the code that owns them — no central registry to keep in sync.
- Palette UX is uniform across all 16 services because the component is shared.
- Phase 2 cross-app full-text becomes "one more provider" — the palette UI does not change.

**Negative / accepted:**

- No cross-app entity search until Phase 2. Mitigation: app-switch + native palette inside the destination app is a 2-keystroke flow.
- Providers run on every keystroke — services must be mindful of debounce and rate limits. The hook enforces 250ms debounce by default.
- Recents are device-local. Sync across devices defers to Phase 2 (would land in `user.preferences.chrome.recents` per ADR-0009 if needed).

## References

- Chrome consumer: `abeon-shared/src/react/command-registry.ts` (Sprint S2)
- UI: `abeon-ui/src/components/layout/command-palette.tsx` (Sprint S3)
- Future Phase 2: `abeon-es-indexer/` aggregates per-service Elasticsearch indices and exposes `GET /api/v1/search`.
- Related: ADR-0010 (apps endpoint feeds app-navigation commands)
