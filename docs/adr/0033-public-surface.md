# ADR-0033: An instance may publish a public surface

**Status:** Accepted
**Date:** 2026-09-23

## Context

Some applications have a second audience. A CMS is managed by a client's editors and *publishes* a
website read by people with no account at all, under the client's own domain. ADR-0031 gave each client
its own instance of each application, reached at the client's platform host under a path prefix — a
shape built entirely around a signed-in user.

Three things stood in the way of a public page:

1. **An organisation has exactly one host.** `organisations.host` is unique and is the client's
   identity: it decides whose session a request carries, where login returns to, and where the tenant
   switcher moves. There was nowhere to put a second address for the same client.
2. **Every route in the template sits behind `AbeonWebAuth`**, so a reader without a session lands on the
   login screen. Routes without a session are possible — `/auth/callback` and the proxies are already
   there — but nothing framed them.
3. **Nothing kept the two apart.** Serve a public page on the panel's host and every anonymous request
   carries the platform cookies; serve the panel on the public host and a reader finds a login screen on
   a domain that should never have one.

## Decision

### 1. The panel's host belongs to Auth; the public host belongs to the instance

- The **panel host** stays `organisations.host` in Auth. It is the client's identity and the only thing
  the platform session is scoped to. Unchanged by this ADR.
- The **public host** is a column on the assignment — `tenant_apps.public_host` in AbeonUnified. A
  published site is a property of the pair *(organisation, application)*: a client's CMS publishes, its
  CRM does not, and both belong to one organisation.

**`GET /api/v1/auth/hosts/{host}` stays about panel hosts only.** It is what the login screen consults
before returning a browser after sign-in (ADR-0031 §3). If it ever answered for a public host, the
platform session would be delivered to an address whose entire purpose is to be anonymous.

### 2. Two surfaces, two hosts, one instance

The same image and the same database serve `panel.acme.com/cms` and `www.acme.com`. Splitting them into
two deployments is an optimisation for when public traffic starts to disturb editors; nothing in the
contract prevents it, and nothing requires it.

### 3. The surface is decided by the host, and it is checked

The public routes are registered **bound to the public host**, before the panel's, because the site owns
`/` on its domain and the panel owns `/` on the platform host. Two guards make the split explicit rather
than incidental: a panel route refuses a request that arrived on the public host, and a public route
refuses one that did not. Both answer **404** — whether a panel exists at that address is not something
a reader needs told, and a redirect would send them to a login screen they have no business at.

An instance with no public host configured registers no public routes and behaves exactly as before.

### 4. A public request gets no cookies

The public group has no session middleware: no session cookie, no CSRF token. The platform cookies are
host-only since ADR-0031 §3 and therefore never reach the public host at all; this keeps the instance
from setting any of its own there.

### 5. The deployment layer learns about the host from an event

`unified.app_instance.requested` carries `public_host` for an instance that already has one, and
`unified.app_instance.host_changed` announces one given, changed or withdrawn afterwards — with
`public_host: null` for a withdrawal, so the provisioner removes the route rather than leaving it
pointing at an instance that no longer claims it.

Setting it is a platform operation (`abeon:instance:public-host`), not something an organisation's
administrator can do: pointing a domain at the platform needs DNS and a certificate.

## Consequences

**Positive**

- A CMS-shaped application fits the platform without exceptions: same image, same instance, same
  assignment row, one extra column.
- The public surface cannot accidentally receive the platform session, and the panel cannot accidentally
  appear on a client's public domain — both are refusals, not conventions.
- A reader's request costs no session row and no cookie, which is what makes the page cacheable later.

**Negative**

- A third address per client to keep track of, with its own DNS and its own certificate.
- The host is read when routes are registered, so `route:cache` bakes it in: an instance given a new
  domain has to rebuild the cache. Stated in `bootstrap/app.php` where it happens.
- The public host lives in AbeonUnified while the panel host lives in Auth. Two places, on purpose, but
  somebody will look in the wrong one — hence this ADR's first paragraph.
- **Media are still unsolved.** A published site has images and attachments, and the object-storage
  contract (ADR-0021) has no implementation, so files sit in the instance's own volume and disappear
  with it. A known debt, and the natural moment to pay it is the first real CMS.

**Rejected**

- *A second host column on `organisations`.* It reads as if the client publishes, when what publishes is
  one of its applications — and a client with two publishing applications would have nowhere to put the
  second address.
- *One host, panel under a path prefix like `/admin`.* Then the public pages share an origin with the
  session, and every anonymous request carries the platform cookies.
- *Letting the store set the public host.* An administrator would be able to claim an address that is
  not theirs, and the platform would have to verify domain ownership before believing them.
- *A separate deployment for the public surface from the start.* Two deployments to provision, two to
  roll, one database shared between them — for a page that is a handful of routes until traffic says
  otherwise.

## References

- ADR-0031 (instance per client; the public surface is a second surface of that instance, and §3's
  host-only cookies are what keep it anonymous), ADR-0015 (the assignment row now also carries where the
  instance publishes), ADR-0021 (object storage, still unimplemented — the media gap above)
- Implemented in this change: `public_host` on the assignment and in both events, the
  `abeon:instance:public-host` command, the surface guards and the host-bound public route group in the
  template, and the local provisioner routing both addresses to one instance.
