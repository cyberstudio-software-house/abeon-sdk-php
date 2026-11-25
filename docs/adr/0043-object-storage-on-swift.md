# ADR-0043: Object storage speaks Swift, with three containers per organisation

**Status:** Accepted
**Date:** 2026-11-25
**Supersedes:** ADR-0034 (protocol and public access only)

## Context

ADR-0034 moved client files to the S3 API of Oktawave OCS. Its central rule, anonymous reads on
`{service}/public/` granted by a bucket policy, and its type-bound upload signature were proven against
MinIO only, and the ADR said so.

On 2026-11-25 the production endpoint was checked. OCS is OpenStack Swift 2.17.1 (`/info`). Its S3 layer
is the old `swift3` 1.12 middleware, which has no bucket policies, and no S3 endpoint or S3 credentials
exist for the company account. Every other company project already talks to OCS through Swift with
Keystone v3, TempURL and SLO. Swift is the format the company keeps.

`/info` on OCS lists `tempurl` (GET, HEAD, PUT, POST, DELETE; sha1, sha256, sha512), `slo` (at most 1000
segments), CORS in the proxy, `max_file_size` 5 GiB, and no `dlo`.

## Decision

1. **Protocol.** AbeonUnified talks to Swift over plain HTTP (`Illuminate\Support\Facades\Http`). There is
   no SDK dependency. Production authenticates with Keystone v3 (`OCS_AUTH=keystone`, user id, password,
   project id). The local SAIO container uses tempauth v1 (`OCS_AUTH=v1`). The token is cached until five
   minutes before it expires, and a 401 drops it once and retries.
2. **Three containers per organisation.** Swift ACLs act on a whole container, so visibility moves from a
   prefix to a container:
   - `abeon-org-{id}-pub` with `X-Container-Read: .r:*` (no listings) holds `{service}/public/...`;
   - `abeon-org-{id}-prv` without an ACL holds `{service}/private/...`;
   - `abeon-org-{id}-seg` holds SLO segments.

   Object keys do not change. The container comes from the second segment of the key.
3. **Addresses are TempURLs** signed with sha256 and a container-level key (`X-Container-Meta-Temp-URL-Key`,
   from `ABEON_STORAGE_TEMP_URL_KEY`). The account-level key is left alone because other systems on the
   same account use it. A private GET carries `filename=`, so Swift answers with
   `Content-Disposition: attachment`. A signature covers one method, one expiry and one object path, so
   `{service}/` is still enforced per object.
4. **Multipart is SLO.** `createMultipart` returns a UUID and stores the organisation, service, key and
   type in the cache for seven days. Parts are TempURL PUTs to `-seg` under `{key}/{upload_id}/{part}`.
   `complete` writes the manifest with the type stored at creation, and checks that the same organisation,
   service and key are completing it. Part numbers are limited to 1–1000. `delete` removes an SLO together
   with its segments.
5. **`provision()`** PUTs the three containers on every call, and with them the ACL (set on `-pub`, removed
   on the others), the TempURL key and CORS (`Allow-Origin: *`, `Expose-Headers` including `etag`). It stays
   idempotent, so drift is repaired on the next projection.
6. **Public address.** `ABEON_STORAGE_PUBLIC_BASE_URL` in Unified is the account URL
   (`https://ocs-pl.oktawave.com/v1/AUTH_<project>`). An instance gets that URL plus `/abeon-org-{id}-pub`,
   so `ObjectStore::publicUrl()` in the SDK does not change.
7. **Usage** is the sum of `X-Container-Bytes-Used` over the three containers, cached for five minutes.

The HTTP contract of `v1/internal/storage/*`, the SDK `ObjectStore` and the Files module stay as they were.

## Consequences

- **Content-Type is no longer part of the upload signature.** TempURL does not sign headers, so an address
  issued for `image/jpeg` can carry any body. The passive-type allowlist is still checked when an address
  for `public/` is issued. What remains is covered in other ways:
  - browsers upload only to `private/` (Files staging and form attachments);
  - only application servers write to `public/` (`ProcessFile`);
  - `Uploads::complete` sniffs the real type from the first bytes;
  - the storage origin `ocs-pl.oktawave.com` holds no Abeon cookies.

  This risk is accepted.
- Public files of every organisation share the OCS origin with other Oktawave customers. That is the same
  exposure every existing company project already has.
- Local development and the live tests use `openstackswift/saio` instead of MinIO. The live tests also run
  against OCS when the `OCS_*` variables point there.
- `aws/aws-sdk-php` and `TypeBoundSignature` are removed from AbeonUnified.
- A file is limited to 5 GiB by OCS and to 1000 parts by SLO.
- **Rejected options:**
  - **S3 through `swift3`:** no bucket policies, and no S3 credentials on the account.
  - **One container with a per-object ACL:** Swift has no per-object read ACL.
  - **An account-level TempURL key:** it is shared with the panel's backups.

## References

- ADR-0021: layout, one container per organisation (now three).
- ADR-0034: superseded for protocol and public access. Its rule that applications hold no credentials stands.
- ADR-0037: the Files module, whose multipart API this implements with SLO.
- `abeon-unified/app/Storage/ObjectStorage.php`, `abeon-unified/tests/Feature/ObjectContainerTest.php`.
