# ADR-0011: Cross-app search service (`abeon-search`)

**Status:** Accepted (Phase 2 — contract now, service later)
**Date:** 2026-06-23

## Context

ADR-0007 adopted a **per-service command registry** for Cmd+K in Phase 0.5 and explicitly
deferred **cross-app full-text search** to Phase 2, noting it "will integrate as one more provider
behind the same palette UI when ready." Phase 0.5 is now implemented: the registry lives in
`@abeon/shared` (`command-registry.ts`), nav commands are seeded via `useRegisterNavCommands`, and
`@abeon/ui`'s `<CommandPalette>` already supports async `provider(query)` entries.

What is still missing is the **contract** for the deferred search so services and the chrome can be
built against a stable shape before the service exists. This ADR defines that contract. The service
itself (`abeon-search`, likely backed by an ElasticSearch/OpenSearch indexer fed by domain events)
is Phase 2 and out of scope here.

## Decision

**A dedicated `abeon-search` service exposes a single read endpoint; the frontend integrates it as
one async provider behind the existing command palette.** No palette or registry change is needed —
it is the same `Command.provider` mechanism already shipped.

### `GET /api/v1/search`

Requires a user JWT. Query params:

| Param | Type | Notes |
|---|---|---|
| `q` | string | The user's query. Required; blank → empty result set (frontend skips the call). |
| `source` | string | Optional CSV of app names to restrict to (e.g. `crm,finance`). Default: all the user can see. |
| `limit` | int | Optional cap (server clamps). |

Results MUST be filtered to what the user is entitled to — search honours the same
`{app}.{resource}.{action}` permission model as ADR-0010's `/apps`; a hit is only returned if the
user can access its `source_app`.

**Response** (per ADR-0004 envelope):

```json
{
  "data": [
    {
      "id": "crm.contact.42",
      "title": "Jan Kowalski",
      "subtitle": "Acme Sp. z o.o.",
      "source_app": "crm",
      "entity_type": "contact",
      "url": "/crm/contacts/42",
      "icon": "users",
      "score": 0.92
    }
  ],
  "meta": { "total": 1 }
}
```

Schema: [`schemas/dto/search-result.json`](../../schemas/dto/search-result.json). PHP DTO:
`Abeon\SDK\DTO\SearchResult`. TS type: `@abeon/shared` `SearchResult`. Golden fixtures are validated
on both sides by the schema contract tests (`tests/Unit/Contract/SchemaContractTest.php`,
`abeon-shared/tests/contract/schema-fixtures.test.ts`).

### Frontend integration

`@abeon/shared` ships `useRegisterSearchProvider({ api, navigate, path?, group? })` (Sprint, this
ADR). It registers exactly one command whose `provider(query)` calls `GET /api/v1/search?q=…`,
maps each `SearchResult` to a navigable `Command` (`run` → `navigate(url)`), and lets the palette
merge those rows with the static per-app commands. A blank query short-circuits to `[]` without a
network call. The chrome opts in by calling the hook once in its layout — no other change.

### Indexing (Phase 2, non-normative)

Each service publishes domain events (ADR-0002 envelope); `abeon-search` consumes them to maintain
its index. Services that prefer to own their own search may instead register a per-app provider that
hits their own endpoint — both surface identically in the palette. Ranking/federation across sources
is the search service's concern and is not specified here.

## Consequences

- The `SearchResult` contract is stable now; `abeon-search` and any consumer can be built against it
  independently and in any order.
- Until `abeon-search` exists, `useRegisterSearchProvider` simply yields no results (or services use
  their own per-app providers) — the palette degrades gracefully, exactly as ADR-0007 intended.

## References

- ADR-0007 (search & command registry — this supersedes its "Phase 2" deferral for the search half)
- ADR-0004 (REST envelope), ADR-0010 (permission filtering model)
