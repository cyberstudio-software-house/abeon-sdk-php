# JWT validation

Notes behind `src/Auth/JwtValidator.php`.

## `JWK_OWNER` is a non-standard JWK member

RFC 7517 §4 allows additional members and requires implementations to ignore ones they do
not recognise, so `abeon_owner` travels safely to standard JWT libraries that have no idea
what it means. For this platform it is the difference between "a valid signature" and "a
valid signature *from the party that claims it*".

## Leeway is set per-validator, not at boot

ADR-0001 rule 5: `exp`/`nbf` are checked by firebase/php-jwt against its static leeway,
which defaults to 0. Setting it in `decode()` rather than at boot means it holds for every
validator, including one constructed directly in a test — a validator whose clock is a
second ahead of the issuer would otherwise reject tokens that were just minted.

## `org_id` is required on user tokens

ADR-0016: `org_id` is a required, non-null claim on user tokens — it is the authorization
and data-scoping dimension, not a display field. Rejecting here keeps the failure at the
trust boundary; letting it through as null would push a missing tenant deep into query
scoping, where "no tenant" is one mistake away from "every tenant" (ADR-0018).

## The instance organisation check lives in `decodeUser()`

ADR-0031 §4: an instance of a business application serves one organisation, and the
audience is shared by every service (ADR-0001), so this is the only thing standing between
a user of one client and another client's instance. Here rather than in a middleware
because every user-token path — web, API, broadcasting — goes through this method, and a
check nobody can forget to add is the point.

## `assertLifetime()`: a token must say when it stops being valid

And must not claim to be valid for an implausible span.

`firebase/php-jwt` checks `exp` only when it is present, so a token minted without one
never expires. ADR-0005 bounds a key compromise by the token's lifetime — a guarantee that
only holds if the lifetime exists and is short. The ceiling is generous on purpose: it is a
backstop against an eternal token, not a second TTL policy.

## `assertKeyOwnsIdentity()`: identity comes from the key

The key that signed the token must belong to the party the token claims to be.

This is the check whose absence made JWKS aggregation dangerous. `iss` and `service_name`
are both fields of the token, written by whoever signed it, so comparing them to each other
proves internal consistency and **nothing about identity**. Once
`/.well-known/jwks.json` publishes every service's key (FR-27), a flat "is this signature
valid against any published key" check means any service's private key can mint a token for
any other service — and, worse, a *user* token for any user in any organisation, because
user tokens were only ever checked for `iss: abeon-auth`, which the signer also controls.

So identity comes from the key, and the key's owner comes from a place the signer does not
control: for Auth's own keys, the row in `signing_keys`; for everyone else, the ConfigMap
key name that operations chose when mounting it.

A key that does not say who owns it is refused rather than trusted. That is fail-closed,
and it means a JWKS from before this check cannot be used to authenticate anything.

## `service_name` is validated here, not in `ServiceAuthMiddleware`

A service token is self-signed, so its claimed name has to match the key it was signed
with. `iss` is still checked against `service_name` to keep the two fields of the token
consistent with each other.

The check is here rather than left to `ServiceAuthMiddleware`, which is where it used to
live. `decode()` is public and is the only part of this contract a service can use
*without* that middleware — in a consumer, a console command, or its own middleware, which
is exactly the case the middleware exists to make unnecessary. Somebody doing that was
entitled to assume a decoded service token names its sender.

The cast this replaces was its own small problem: `(string) $claims[...]` on a value from
outside our control turns an array into `"Array"` and an integer into its digits, and then
compares that.

User tokens are minted by Auth alone. No other service's key may sign one, whatever `iss`
says.
