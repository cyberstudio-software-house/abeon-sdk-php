# ADR-0034: Object storage speaks S3, and applications hold no credentials

**Status:** Accepted
**Date:** 2026-09-24
**Amends:** ADR-0021

## Context

ADR-0021 settled the layout of client files in August 2026: one container per organisation, objects
under a `{service}/` prefix, AbeonUnified provisioning containers and issuing credentials, bytes never
passing through Unified. None of it was built. There is no `Abeon\SDK\Storage`, the `abeon/filesystem-ocs`
package it names exists only in prose, and no repository requires a storage driver of any kind.

Two things made this the moment to pay it. ADR-0033 gave an instance a published page and said in its own
consequences that media were still unsolved — a site's images sit in the instance's volume and disappear
with it. And ADR-0021's most uncomfortable line had never been tested against reality: Swift's ACLs act
on a container, so the `{service}/` prefix was a convention, and one application's credentials could read
another's files.

## Decision

### 1. The protocol is S3, and that is what makes the prefix a boundary

ADR-0021 chose Swift with Keystone and accepted the consequence honestly: container-scoped ACLs mean
`{service}/` separates by agreement, not by enforcement. S3 policies and signatures act on a key, so the
consequence disappears — a signature issued for `cms/` opens nothing under `crm/`.

This is the only reason the restriction can be lifted. Nothing about the layout changed; the protocol did.

**Unchanged from ADR-0021:** one container per organisation (`abeon-org-{org_id}`), the `{service}/`
prefix inside it, AbeonUnified as the provisioner, bytes outside Unified's path, and retention or export
as an operation on a single container.

### 2. An application holds no storage credentials at all

Stronger than ADR-0021, which had Unified issue scoped, expiring credentials. An application asks for a
**signed address** for one object and one operation, and moves the bytes itself — usually from the
browser, straight to the storage. The keys exist in exactly one place, and a leaked application secret
reaches no files.

The endpoint takes the container from the service token's `org_id` and the prefix from its
`service_name` (`ServiceAuthMiddleware::ATTRIBUTE_ORG_ID` and `ATTRIBUTE`), the same rule `source_app`
follows on the notification route. A request naming an organisation or a bucket is refused by the
contract; a key outside the caller's prefix is refused twice, in the SDK at the line that built it and in
Unified where it counts.

A service token carrying no organisation addresses nothing (ADR-0018): null is an error here, never
"any container".

### 3. Three kinds of access

- `{service}/public/…` — the container policy grants anonymous `GetObject` under that prefix and nowhere
  else. The address is stable, cacheable and ready for a CDN, which is what ADR-0033 needs to keep a
  reader's request free of any platform dependency.
- `{service}/private/…` — readable only through a signed GET, valid for minutes.
- Writing — always a signed PUT, whatever the visibility. The `Content-Type` is signed over, so an
  address issued for an image cannot be spent on something else.

### 4. Addresses are derived where they can be, fetched where they cannot

A public object's address is a pure function of the container and the key, so the SDK builds it without
asking anybody. An instance serves one organisation (ADR-0031), so it knows its own container: the
provisioner writes `ABEON_STORAGE_PUBLIC_BASE_URL` beside `ABEON_ORG_ID`.

This removes ADR-0021's awkward corner, where credential TTL was an availability parameter. Unified being
down stops uploads and signed reads; it does not stop a published page from loading its pictures.

### 5. The container is created where Unified already learns of the organisation

`ProjectOrganisation`, the `auth.org.created` / `auth.org.updated` projection (ADR-0022). Idempotent,
because that projection is replayed by design, and the bucket policy is rewritten on every pass — a
policy that drifted would be a container whose private files might not be.

A deployment with no storage configured logs and carries on. A platform whose applications cannot upload
is a decision; turning every `auth.org.created` into a dead letter is not.

## Consequences

**Positive**

- One application cannot read another's files, and this is now enforced rather than agreed.
- No application has a credential worth stealing.
- A published page's images cost the platform nothing at read time and are cacheable as they are.
- The whole storage dependency lives in one repository: `aws/aws-sdk-php` in AbeonUnified, nowhere else.

**Negative**

- Every upload costs a round trip to Unified before the bytes move. Acceptable — it is one small request
  against a transfer — but it puts Unified on the path of *starting* an upload.
- Signed addresses are bearer credentials for their lifetime. Minutes, and per object, which is the whole
  mitigation.
- MinIO locally, S3-compatible storage in production: one more service in the local stack, and path-style
  addressing has to be set or every signature 404s.
- `abeon/filesystem-ocs` will not be written. With signed addresses there is no driver for an application
  to install, which is a consequence of the protocol change worth stating rather than leaving as a
  package somebody looks for.

**Deliberately not in this change**

- **Thumbnails.** The PRD wanted a shared `abeon-thumbnails` container, which contradicts one container
  per organisation. They belong under `{service}/public/thumbnails/…`, and generating them is the
  application's job.
- **A CDN.** The public address is stable and cacheable; putting an edge in front of it belongs to
  deployment.
- **Migrating existing files.** There are none.

**Rejected**

- *Credentials per application, as ADR-0021 had it.* Every application would then hold a secret that
  reaches files, and the prefix would be enforced by whatever the storage's policy language allows on a
  credential rather than on a single signed object.
- *Proxying bytes through AbeonUnified.* It would make the prefix trivial to enforce and turn Unified into
  a file server on the critical path of every upload and every image. ADR-0021 rejected this and was
  right.
- *A public bucket per application.* More containers to provision and to keep track of, for a separation
  the key prefix already gives, and an export or a deletion for one client would stop being an operation
  on one container.
- *Letting the caller name the key's prefix.* Then the refusal depends on the caller's honesty, which is
  the defect this ADR exists to remove.

## References

- Amends ADR-0021 (layout kept, protocol and credential model replaced), ADR-0033 (the media debt it
  recorded is paid), ADR-0019 (Unified provisions storage — the exception to the doctrine stands),
  ADR-0031 (one instance per client is what lets an instance know its own container), ADR-0018 (a missing
  organisation is an error, never a wildcard), ADR-0005 (the service token is where the caller's identity
  and organisation come from)
- Implemented in this change: `Abeon\SDK\Storage\{ObjectPath,ObjectStore,SignedUrl}` and
  `schemas/dto/stored-object.json`; `App\Storage\ObjectStorage` and
  `POST /api/v1/internal/storage/{sign,delete}` in AbeonUnified; the file demo in the template; MinIO in
  the local stack.
