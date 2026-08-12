# ADR-0021: Object storage layout — one container per organisation

**Status:** Accepted
**Date:** 2026-08-12
**Supersedes:** the per-service container layout in `abeon-unified-architecture.md` §8.4

## Context

Architecture doc §8.4 specifies **two OCS containers per service** — `abeon-{service}-public` and
`abeon-{service}-private` — configured as Laravel filesystem disks inside each service, with the
Keystone v3 token cached in Redis and an upload flow of *"frontend → backend (presigned URL lub proxy)
→ OCS"*. That layout assumes one organisation per deployment, which ADR-0016 reversed.

OCS (Oktawave Cloud Storage) is OpenStack Swift with Keystone v3 authentication.

Object storage is also the **deliberate exception** to ADR-0019's doctrine that shared external
infrastructure sits behind a platform service. Proxying bulk bytes through AbeonUnified would put every
upload and download through a control-plane service for no benefit; §8.4's reasoning on this still
holds and is retained.

## Decision

**One container per organisation. All services share it, under `{service}/` object prefixes.**

1. **Container per organisation**, provisioned as part of organisation onboarding (ADR-0016).
2. **Objects are prefixed by owning service** — `crm/…`, `cms/…`. Public/private separation moves from
   separate containers to prefix plus per-object visibility.
3. **AbeonUnified provisions the container and issues credentials.** Applications never hold long-lived
   OCS credentials; the SDK's `Storage` component requests short-lived, organisation-scoped credentials
   from Unified and caches them, mirroring the `JwksClient` cache pattern and `ServiceTokenProvider`'s
   flush-on-401 behaviour.

   **Package boundary.** The architecture doc's *Decyzje uzupełniające* #2 keeps the OCS Laravel driver
   in a sibling package, `abeon/filesystem-ocs`, deliberately out of the core SDK so services that
   never touch files (AbeonUnified, the ES Indexer) do not pull Swift/Keystone dependencies. **That
   decision stands**, and the work splits along it:

   | Where | What |
   |---|---|
   | **core `abeon/sdk`** — `Abeon\SDK\Storage` | Fetch + cache organisation-scoped credentials from Unified (plain HTTP via `ServiceClient`), enforce the `{service}/` prefix, assemble the disk. **No Swift, no Keystone dependency** — which is precisely what decision #2 protects. |
   | **`abeon/filesystem-ocs`** | The Flysystem / Swift / Keystone driver, unchanged. |

   Without this split the core SDK would drag object-storage dependencies into every service on the
   platform, including the ones that exist specifically to avoid them.
4. **Bytes go directly from the application to OCS.** Unified is in the credential path, not the data
   path.
5. **The SDK enforces the `{service}/` prefix** so ordinary application code cannot address another
   service's objects — see the honest limits below.

Retained from §8.4: Keystone v3 token cached in Redis with a refresh margin, and the presigned-URL or
proxy upload flow.

## Consequences

**Positive:**
- *"Here is your data"* becomes trivial — one container is the client's entire object footprint.
  Retention, backup policy, export and deletion are per-organisation operations rather than a sweep
  across sixteen service containers.
- Provisioning a new organisation creates one container, not thirty-two.
- No credential distribution problem: no service holds an OCS secret, and revocation is one place.

**Negative / accepted — and the isolation trade is real:**

**The `{service}/` prefix is a convention, not a boundary.** This was verified rather than assumed:
Swift ACLs live in `X-Container-Read` / `X-Container-Write`, and *"the scope of the ACL is limited to
the container where the metadata is set and the objects in the container"* — there is no prefix-level
or object-level ACL. A credential that can read the organisation's container can read **every
service's** objects in it.

So the boundary that database-per-service gives us has **no equivalent here**, and cannot be bought
back at the storage layer with this container topology. What actually constrains cross-service access:

- The SDK's `Storage` component refuses to address objects outside the calling service's prefix, so
  ordinary code cannot do it accidentally.
- A service that constructs its own Swift client bypasses that entirely. All services in an instance
  are first-party code, so this is a review and static-analysis concern, not a security boundary —
  and it must be understood as such rather than assumed to be enforced.
- ADR-0018's tenant scoping does not help here at all; it is a database concern.

**If a real boundary is ever required** — an untrusted or third-party application in the suite, or a
compliance requirement — the fix is a container **per organisation per service**, restoring
container-level ACLs as the enforcement point, at the cost of many more containers and a heavier
provisioning step. This ADR should be revisited at that point rather than patched.

**Other accepted costs:**
- Public/private separation is now per object rather than per container, so retention and CDN policy
  that §8.4 configured per container must be expressed differently.
- Applications depend on Unified for credentials. A Unified outage degrades storage access even though
  bytes do not flow through it; the credential cache is what limits that blast radius, so its TTL is a
  real availability parameter.

## References

- Depends on: ADR-0016 (organisation is the container dimension), ADR-0019 (Unified provisions and
  issues credentials; the doctrine and this exception to it)
- Related: ADR-0018 (tenant scoping — database only; it does **not** cover object storage)
- Supersedes: `abeon-unified-architecture.md` §8.4 container layout (its Keystone caching and upload
  flow are retained)
- Implementation: new `Abeon\SDK\Storage\*` (credentials, prefix, disk assembly) + the existing
  `abeon/filesystem-ocs` sibling package (Swift driver) per the architecture doc's *Decyzje
  uzupełniające* #2; patterns to reuse in `src/Auth/JwksClient.php` (`CacheRepository`) and
  `src/Client/ServiceTokenProvider.php` (fetch / cache / flush-on-401)
- Swift ACL scope: [OpenStack Swift — Access Control Lists](https://docs.openstack.org/swift/latest/overview_acl.html)
- Implementation delta: `abeon-sdk-delta-2026-08-12.md` item 7
