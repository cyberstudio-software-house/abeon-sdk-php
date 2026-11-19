# App entitlement

Background for `src/Panel/EnsureAppEntitled.php`.

## What it does

Blocks deep-links into this app from users not entitled to it (spec FR-1, frontend half of G10).
The AppSwitcher already hides apps the user cannot see (Auth filters `GET /api/v1/auth/apps` by
permission); this stops someone navigating to the URL directly anyway.

Entitlement rule mirrors ADR-0010: a user is entitled if they hold any permission under this
app's prefix (`{app}.…`). Apply AFTER `AbeonWebAuth`, which populates `AuthContext`. On failure
aborts 403 — the exception handler renders the Inertia `Errors/403` page (see
`bootstrap/app.php`).

Fine-grained, per-feature gating is done per-route with the SDK's Gate bridge, e.g.
`->middleware('can:crm.contacts.read')`.

## Two escape hatches were removed on 2026-08-15

Both of them let a request through that should not have been.

The first accepted any grant beginning with `*` as a "dev superuser", with no environment gate,
so it behaved identically in production. ADR-0024 §1 says `*`, `crm.*` and `*.*.*` are not valid
grants and Auth must never mint them, and `PermissionName::PATTERN` in Auth enforces that — so a
real token cannot carry one and the branch had no use left. But the SDK does not validate
permission *shape* on receipt: `JwtValidator::decodeUser()` checks `type`, `aud`, `iss` and
`org_id`, and `User::fromArray()` accepts any strings. That made these two lines the only place
in the whole chain where `*` meant anything at all — and what it meant was "everything". Dead
code that grants full access is the worst kind of dead code. ADR-0024 §4 also says outright that
"the boilerplate must not learn to depend on it".

The second let every request through when no app identity was configured, on the reasoning that
there was nothing to gate on. What that actually produced was a forgotten `ABEON_SERVICE_NAME`
in one of sixteen deployments yielding an application with no permission gate at all — quietly,
with a green healthcheck, discoverable only by someone typing the URL. ADR-0018 puts the
doctrine plainly: what makes a system safe is that the unsafe state is loud. An application that
does not know its own identity is not in a state where it should be serving traffic.
