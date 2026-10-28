# ADR-0037: File management is a module in the SDK, and Unified keeps the storage keys

**Status:** Accepted
**Date:** 2026-10-28
**Amends:** ADR-0034 (adds endpoints to Unified; thumbnails stay the application's job, now with shared code)

## Context

ADR-0034 settled how bytes reach storage: an application asks AbeonUnified for a signed address for one
object and one operation, and moves the bytes itself. It deliberately left everything above the bytes
to the application — *"Thumbnails … generating them is the application's job"* — and that is all that
exists today:

- `Abeon\SDK\Storage\{ObjectStore, ObjectPath, SignedUrl}`: `uploadUrl`, `readUrl`, `publicUrl`,
  `delete`.
- `App\Storage\ObjectStorage` in AbeonUnified (`provision`, `sign`, `delete`, `discard`) behind
  `POST /api/v1/internal/storage/{sign,delete}`.
- In the template, `MediaController` and `Media.tsx`: one image under the fixed key `media/hero`.

There is no record of a file anywhere. Nothing confirms that an upload finished, so an abandoned upload
leaves an object nobody owns. There are no variants, no folders, no record of where a file is used, no
limits and no way to list what an application has stored.

Every application needs this. The CMS needs it first (`abeon-cms-prd-2026-10-27.md`, stage 3), and the
newer client projects each built their own: manngo uploads asynchronously to Swift, hr-management uses
Spatie Media Library with a tenant path generator, PCC has chunked uploads to OCS, hafen keeps
`abeon_files` with SEO names and comma-separated id lists, and telko generates thumbnails from the URL.
telko also shows what an unverified pipeline costs: about 100,000 files never reached OCS and nothing
noticed, while every download issues a live `HEAD` to find out.

Three placements were considered on 2026-10-27/28 (`abeon-files-prd-2026-10-27.md` §2):

| | Inside the CMS | Module in the SDK and the template | Separate `abeon-files` service |
|---|---|---|---|
| Who can use it | the CMS only | every application on the template | every application |
| Where a file's record lives | CMS database | the application's database | a central database |
| Storage keys | Unified | Unified | moved to the new service |
| New infrastructure | none | none | service, database, worker, deployment |
| Sharing a file between applications | no | not in v1 | yes |

A separate service was chosen first and reversed the next day. Its only advantage is the last row, and
none of the eight client projects analysed shares files between applications. The CMS cannot own the
code because manngo, hr-management and geoglobe need it without depending on the CMS.

## Decision

**File management is a platform module: the backend in `abeon/sdk`, the hooks in `@abeon/sdk-ts`, the
screens in the template. Each application keeps its files' records in its own database. AbeonUnified
remains the only holder of storage keys.**

### 1. The module, by layer (the ADR-0026 seam)

- **`Abeon\SDK\Files`** (PHP): migrations (`files`, `file_variants`, `file_usages`, `folders`, `tags`,
  `file_tag`); the upload service; the processing job; the `UsesFiles` trait; the trash; and the
  commands `abeon:files:prune-pending`, `abeon:files:purge-trash`, `abeon:files:reconcile`,
  `abeon:files:manifest`. It builds on `ObjectStore`, which does not change.
- **`@abeon/sdk-ts`**: `useFiles()`, `useFileUpload()` (multipart, progress), `useFolders()`,
  `useFileUsages()`.
- **The template**: the `/files/*` routes, the library screen replacing the `media/hero` demo, and a
  file picker for forms. An application gets them by forking and adds its own metadata beside them — the
  CMS adds alt text per locale and a caption.

### 2. A file is `ready` only after the platform has seen it

1. `begin` records the file as `pending` and returns a signed `PUT` with the content type in the
   signature (ADR-0034 §3). Policy (types and sizes per application) and the organisation's quota are
   checked here.
2. The browser sends the bytes to storage.
3. `complete` checks the object exists with the expected size, reads its first bytes and decides the
   type **from the content**, not from the header. A mismatch fails the file and deletes the object.
4. A job derives dimensions and variants and sets `ready`.

A `pending` file not completed within 24 hours is removed with its object. A daily reconciliation
compares `ready` records with the objects under the application's prefix, reports both directions, and
removes objects without a record after 7 days.

### 3. Variants are generated ahead, under predictable keys

Presets are declared per application (the CMS: `thumb` 320, `md` 960, `lg` 1920, `og` 1200×630), and
written as WebP plus the original format under `{service}/{visibility}/thumbnails/{file_uuid}/{preset}.{ext}`.
The focal point is a property of the file, because cropping a fixed-ratio preset depends on it; changing
it re-runs the job. EXIF location data is stripped and orientation applied.

**No image is transformed on request.** The architecture document allowed "on-the-fly" thumbnails; that
would put a service on the path of a public read, which ADR-0034 §4 keeps free of any platform
dependency. A public address remains a function of the container and the key.

### 4. Usage is recorded, and a used file cannot be deleted

An entity declares which files it uses (`UsesFiles`), and the module keeps `file_usages` in step on save.
Deleting a file that has usages returns `409` with the list. An unused file goes to a 30-day trash, then
its object and variants are deleted. This replaces comma-separated id lists such as hafen's.

### 5. Unified keeps the keys and gains four operations

All under `/api/v1/internal/storage/*`, service token, the container from `org_id` and the prefix from
`service_name` exactly as `sign` does today:

- **multipart**: create, sign a part, complete, abort;
- **list** the objects under the caller's own prefix (for reconciliation);
- **usage**: the size of the organisation's container (for the quota);
- **export**: a ZIP of the organisation's container under a private key, readable through a signed link
  for 7 days, gated on `core.files.manage`. Each application adds its own `manifest.json` through the
  module's command.

Deleting an organisation's files remains `ObjectStorage::discard`. The processing job reads the original
and writes variants through signed addresses, so no application holds a key.

### 6. Where a separate service would come in

If sharing a file between applications of one organisation becomes a real requirement, a service can
take over the catalogue: the module's interface becomes its client and the data model is the same. That
would be a new ADR, not an amendment of this one.

## Consequences

**Positive**

- No new deployable. The CMS's stage 3 builds the module once, in the SDK, and manngo, hr-management and
  geoglobe receive it with a release.
- A file's record is backed up, restored and deleted with the content that uses it (ADR-0031: one
  instance and database per client).
- Unified stays the one place with storage keys, and the bytes still never pass through it.
- The 100,000-missing-files failure becomes visible within a day instead of never.

**Negative**

- **The module ships migrations**, so a schema change reaches every application. Mitigation: additive
  migrations only, released as minor versions.
- **Every application image needs the processing libraries** (libvips, poppler). Mitigation: put them in
  the shared `abeon-php` base image, and run processing on its own queue with a cap on input dimensions.
- Organisation-wide views are coarser than per-application ones: the quota and the export work on the
  whole container, and "where is this file used" only sees its own application.
- Uploads still start with a round trip to Unified (ADR-0034's accepted cost); multipart adds one per
  part.
- **Oktawave's S3 compatibility is still unproven**: ADR-0034 verified the policy and the signed `PUT`
  against MinIO only, and multipart is new here. It is tested against the real endpoint before the first
  client, as early as possible.

**Rejected**

- *A separate `abeon-files` service* (chosen on 2026-10-27, reversed on 2026-10-28): a new service,
  database and worker, moving the keys out of Unified, and a hard dependency before the CMS's stage 3 —
  all for cross-application sharing that no current project uses.
- *Building it inside the CMS and extracting it later.* The CMS would be the only consumer for months and
  the extraction would happen under the pressure of the second application.
- *Thumbnails generated from the URL on request* (telko's model): puts a service on the public read path.
- *Spatie Media Library as the module.* It assumes a filesystem disk with credentials, which ADR-0034
  removed from applications, and its conversions write through that disk.

## References

- Amends ADR-0034 (thumbnails remain the application's job and now have shared code; Unified's storage
  API gains multipart, list, usage and export). Builds on ADR-0021 (layout), ADR-0026 (API and hooks in
  the packages, screens in the template), ADR-0031 (per-client database), ADR-0018 (no organisation
  means no container), ADR-0028 (quota warning as a notification).
- Architecture document §8.4 "Thumbnails" (dated note added there, 2026-10-28: generated ahead, not on
  the fly).
- Product documents: `abeon-files-prd-2026-10-27.md` (scope, interfaces, stages),
  `abeon-cms-prd-2026-10-27.md` §6.2.
- Not implemented yet on 2026-10-28: `Abeon\SDK\Files`, the template's library screen, and the four
  Unified operations.
