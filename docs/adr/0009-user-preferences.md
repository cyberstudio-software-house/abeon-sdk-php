# ADR-0009: User preferences (chrome state)

**Status:** Accepted
**Date:** 2026-05-15

## Context

The federated chrome lets each user customise their experience:

- **Pinned items** — which sub-app nav items are pinned to the sidebar top section.
- **Theme** — light / dark / system (`next-themes` persists locally already, but should sync server-side eventually).

Without a server-side store, every device starts fresh, and changes made on a laptop never appear on a phone. The Phase-0.5 chrome must persist these.

Where should these preferences live?

1. **A dedicated `abeon-preferences` service** — clean separation, but adds one more service before any business logic ships.
2. **Inside the Auth service profile** — Auth already owns the canonical user record, runs in Phase 1, and is the natural home for "things attached to the user".
3. **Per-app local storage only** — no sync, breaks multi-device UX, kicks the can.

## Decision

**Option 2 — `abeon-auth` exposes `/api/v1/auth/me/preferences`** (GET + PATCH). Preferences are stored alongside the user profile as a JSON blob in the `user_preferences` table (**one row per user per organisation** — see the amendment below). The SDK provides a base controller (`Abeon\SDK\Auth\Endpoints\PreferencesController`) plus the migration so Auth service drops it in with one extension call.

> **Amended 2026-08-12 (ADR-0016): preferences are per-user-per-organisation.**
> The table key becomes `(user_id, org_id)` and the endpoint reads the organisation from the caller's
> token — the URL does not change. A user who belongs to two organisations keeps a separate app order,
> separate pins and separate theme in each, because the app sets themselves differ: a pin to
> `/crm/contacts` is meaningless in an organisation that has no CRM.
>
> This is what the MVP's own spec asked for — FR-3 required pins to persist server-side per user and
> *"SHOULD be scoped per tenant"*. Switching organisation (ADR-0017) must therefore re-fetch
> preferences along with the app list.

### Data shape

Top-level JSON object with a versioned schema. The chrome cares about the `chrome` namespace; other namespaces are reserved for future apps to use without colliding.

```json
{
  "version": 1,
  "chrome": {
    "pinned": [
      { "id": "crm.contacts", "app": "crm", "label": "Kontakty", "href": "/contacts", "iconName": "Users", "sectionId": "default", "order": 0 },
      { "id": "pm.projects",  "app": "pm",  "label": "Projekty", "href": "/projects", "iconName": "Briefcase", "sectionId": "default", "order": 1 }
    ],
    "theme": "system",
    "sidebarCollapsed": false,
    "recents": [
      { "id": "crm.contact.abc-123", "title": "Acme Inc.", "ts": 1715760000 }
    ]
  }
}
```

Canonical schema: [`schemas/dto/preferences.json`](../../schemas/dto/preferences.json) (added in Sprint S1).

**No app order (amended 2026-09-17).** This ADR listed a per-user *app order* for the sidebar and app
switcher, after the MVP's `AppOrderSettings.tsx` — which was a mock: its "save" only showed a toast and
nothing read the order. The platform carried `chrome.appOrder` in the schema, the type and a setter for
four months, with no screen to set it and no component reading it. It is removed. The app switcher's
order is the catalogue's (`AppDescriptor.category` and `order` in AbeonUnified); a user-level ordering, if
one is ever wanted, starts from a concrete behaviour — for instance favourite applications above the
categories — rather than from an unused field.

**Pinned sections (amended 2026-09-17).** `chrome.pinnedSections` holds named sections of the pinned list
as `{id, label, order}`. The section `default` always exists without an entry — an entry with that id only
renames it — so a user who never creates a section has nothing stored. Each pin names its section in
`sectionId`. Removing a section moves its pins to `default` in the same write, so no pin can point at a
section that is gone. The MVP had sections too, but every pin went to `default` and empty sections were
hidden, so a created section never appeared; here an empty section is shown with a drop hint.

**Pins (amended 2026-09-17).** One list serves every application of the organisation, so a pin names
the application it belongs to (`app`) and its id is namespaced by it (`crm.contacts`). `href` is the path
as that application links to it. Each application shows only the pins it can open —
`resolvePins()` in `@abeon/sdk-ts/client`: its own pins are visited in place, another application's open
with a full load under that application's `AppDescriptor.path`, and pins of applications the
organisation does not have are hidden without being deleted. The example above used `{app, path, label}`
until this date, which no pin ever had; a contract test now compares the schema with the TypeScript type.

**Validation:** `PATCH` body merges into the existing blob (deep merge, top-level keys only). Unknown top-level namespaces are accepted (forward-compat). Unknown keys inside `chrome` are rejected (strict validation against the schema).

### REST endpoints

| Method | Path | Purpose | Response |
|---|---|---|---|
| `GET` | `/api/v1/auth/me/preferences` | Read full preferences blob for current user. | `{ data: Preferences }` |
| `PATCH` | `/api/v1/auth/me/preferences` | Deep-merge top-level namespaces. Returns full updated blob. | `{ data: Preferences }` |

Both require user JWT. Service tokens MAY read another user's preferences via internal `GET /api/v1/auth/users/{id}/preferences` (Auth internal route, not in SDK).

### Storage

Migration `abeon-sdk-php/database/migrations/2026_05_18_000001_create_user_preferences_table.php` (Sprint S1):

```php
Schema::create('user_preferences', function (Blueprint $t) {
    $t->id();
    $t->unsignedBigInteger('user_id')->unique();
    $t->json('preferences');           // versioned blob
    $t->timestamps();
});
```

Auth service runs this migration. Other services do not — they never read this table directly. They go through the REST endpoint.

### Caching

The Auth service caches the blob in Redis for 5 minutes per user. The chrome's `usePreferences()` hook caches in memory for the lifetime of the page. `PATCH` invalidates both. Latency target: < 50ms p95 for GET.

### Defaults

When the user has no row yet, the GET endpoint synthesises defaults:

```json
{
  "version": 1,
  "chrome": {
    "pinned": [],
    "theme": "system",
    "sidebarCollapsed": false,
    "recents": []
  }
}
```

No row is created until the first `PATCH`.

### Migration / schema evolution

`version: 1` is current. Future breaking changes increment the version and Auth migrates rows on read. The chrome (`usePreferences()`) is version-aware and degrades gracefully on unknown future versions.

## Consequences

**Positive:**

- Phase 0.5 ships chrome customisation without spinning a new service.
- Auth already owns user identity — preferences are conceptually adjacent.
- Single row per user with a JSON blob keeps the schema flexible; namespaced top-level keys let other apps annotate without coordinating migrations.
- Versioned envelope leaves room to evolve.

**Negative / accepted:**

- Auth service handles a workload it didn't originally signal (preferences read/write). Mitigated by Redis caching.
- A misbehaving app writing huge `metadata` could bloat the blob — schema validation enforces sane sizes (max 64 KB enforced server-side).
- Cross-app preferences (e.g. CRM-specific column visibility) will live under their own top-level key in the same blob, which couples them to Auth deploys. If this becomes painful, a future ADR may move per-app preferences into per-service tables, leaving `chrome` here.

## References

- Schema: `schemas/dto/preferences.json` (Sprint S1)
- Migration: `database/migrations/2026_05_18_000001_create_user_preferences_table.php`
- Base controller: `src/Auth/Endpoints/PreferencesController.php`
- Chrome consumer: `abeon-sdk-ts/src/react/use-preferences.ts`, `use-app-order.ts`
- Related: ADR-0010 (Auth /me + /apps endpoints), arch doc §3.6
- **Amended 2026-08-12 by ADR-0016** (multi-tenant organisations): preferences are keyed
  `(user_id, org_id)`, and ADR-0017's tenant switch must re-fetch them. MVP precedent:
  `unified-shell-spec.md` FR-3.
