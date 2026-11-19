# Inertia shared props

Background for `Panel::props()` (the app's `HandleInertiaRequests`) and `src/Panel/PreferencesReader.php`.

## Why `abeon.user` is a closure

Inertia's root middleware shares `abeon.user` so the React entry can
`<AbeonProvider initialAuth={{ user }}>` from the first render — no client refetch, no auth
flash. Pattern from `@abeon/sdk-ts/docs/inertia-integration.md`.

IMPORTANT: the `abeon.user` value MUST be a closure, not an eager value. This middleware runs in
the web GROUP (before route-level middleware like AbeonWebAuth), so `AuthContext` isn't populated
yet when `share()` is called. Inertia evaluates the closure lazily during response-build, after
AbeonWebAuth has set the user — that's when we need to read it.

## `basePath`

The path prefix this application is served under on its client's host (ADR-0031 §2), `''` at the
root. From `X-Forwarded-Prefix`, trusted by the SDK — the ingress strips `/cms` and says so. The
browser needs it for every address it builds itself: links, API calls, the broadcasting endpoint.

## `loginUrl`

Where the browser goes when there is no session. Shared from here because the backend already
resolves it — `AbeonWebAuth` redirects to exactly this — and because the alternative was three
copies of the address across two variable namespaces: the server reading `ABEON_AUTH_UI_URL` and
the browser reading `VITE_ABEON_AUTH_URL`, which points at a *different* service. Two of the
three copies were still aimed at Auth, which is API-only (ADR-0026) and has no `/login`, so
signing out ended on a 404 with no way back but typing a URL.

## `reverb`

Live notifications (ADR-0006 §Live updates). Shared from here rather than duplicated into
`VITE_*`, for the same reason `loginUrl` is: the backend already knows the address, and a second
copy in another variable namespace is a second thing to get wrong. Null without a key, and then
the bell polls exactly as it does today.

## `preferences` and `PreferencesReader`

Full page loads only. The provider reads this once, when it mounts, so an Inertia visit would pay
a call to Auth for a value nobody reads.

`PreferencesReader` reads the signed-in user's preferences document from Auth while the page is
being rendered, so the saved theme and sidebar state are part of the first paint.

Without it the browser rendered the defaults, fetched preferences after mounting, and switched —
a light page for a user who chose dark, for as long as one request takes. The browser cannot
avoid that on its own: preferences are per user per organisation and shared between applications
(ADR-0009), so a local copy would be wrong on the first visit to every application and after
every change made elsewhere.

**It must never cost the page.** The timeout is short and every failure is `null`, which is
exactly the behaviour before this class existed: the client fetches preferences itself. A slow
Auth makes the theme late, not the page (ADR-0025 §4).
