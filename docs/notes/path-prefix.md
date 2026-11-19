# Path-prefix hosting

Background for `src/Panel/ScopeCookiesToBasePath.php`.

## Cookie scoping

Keeps this application's session and `XSRF-TOKEN` cookies under its own path prefix.

Every application of a client shares one host (ADR-0031 §2): `/cms` and `/crm` are different
deployments with different `APP_KEY`s. Laravel names both cookies the same way in every
application and writes them for `/`, so whichever tab loaded last would overwrite the other's —
the session silently lost, and the next form post a 419, because the other application cannot
decrypt the token it was sent.

Both cookies take their path from `session.path`, so setting it per request is enough. Before
the session starts, which is why this is prepended to the `web` group. Platform cookies
(`abeon_token`, `abeon_refresh`) are Auth's and stay on `/` on purpose: they are the one session
every application of the client shares.
