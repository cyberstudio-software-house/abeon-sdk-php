# Panel and public surfaces

Background for `src/Panel/EnsurePanelSurface.php`.

Keeps the panel off the instance's public host (ADR-0033).

One instance serves two surfaces: the panel, on the client's platform host, and the public site,
on the client's own domain. The panel carries the platform session; the public host must never
see it, and a reader who lands on `www.acme.com/settings` must not find one.

**404, not 403.** Whether a panel exists at this address is not something an anonymous reader
needs told, and a redirect would send them to a login screen they have no business at.

With no public host configured the instance has one surface and this is a no-op — which is every
application that does not publish anything.
