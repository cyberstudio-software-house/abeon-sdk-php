# Token refresh

Background for `src/Panel/TokenRefresher.php` and `tests/Integration/Panel/TokenRefreshTest.php`.

## Why the suite exists

What happens when the access token expires under a user who is still working. This was the
last unexercised path in the chrome: access tokens live 15 minutes and no browser session
had ever crossed that boundary, so nothing here had run outside a code review. Two things
had to be true and neither was — a navigation had to survive expiry instead of bouncing to
the login screen, and an open tab's polling had to survive it instead of 401ing forever.

## The access cookie is gone, not stale

The common case, and the one an "invalid token" branch alone would miss: the access cookie
is written with the access TTL as its lifetime, so on expiry the browser deletes it and the
request arrives carrying only the refresh cookie.

The XHR half of that is the case a real run caught and this suite originally missed. By the
time the bell polls after expiry the browser has *deleted* the access cookie, because it was
written with the access TTL as its lifetime — so the request carries only a refresh cookie.
Gating the retry on a presented access token made every one of those polls 401.

## One entry point for refresh

`/api/v1/auth/{any}` used to pass `refresh` straight through to Auth, so a client calling it
bypassed `TokenRefresher` entirely — and two tabs doing that at once is two uses of one
refresh token, which ADR-0023 reads as a replay. One entry point means one call to Auth,
whoever asks.

This is the whole reason the refresh lives here rather than in the browser. ADR-0023 rotates
refresh tokens and treats a second use of a consumed one as a replay — it revokes the entire
family. A page firing several requests at once, or a second tab, would otherwise log the user
out for no reason.

## The tenant switch needs the cookie too

The switch rotates the refresh family (ADR-0023 §2), so Auth reads the cookie on this path as
well. Narrowing the list to `/refresh` and `/logout` left the family pointing at the previous
organisation — the switch appeared to work and the next refresh moved the user back without
saying so.

## The cache must not hold the rotated refresh token

ADR-0023 §2: a token is stored hashed so that reading the database never yields a working
credential. Auth honours that. The chrome was undoing it — `Set-Cookie` headers carry the new
refresh token in plain text, and caching them put a seven-day credential in the `cache` table,
and therefore in every backup and every dump copied to a test environment.

The fifteen-minute credential does stay: it is what the waiting requests need, and it is the
difference between fifteen minutes and seven days that matters.

## Why the refresh is server-side and single-flighted

Both cookies are `httpOnly`, so JavaScript cannot see them; this application is the thing
that holds the session on the user's behalf. More importantly, ADR-0023 rotates refresh
tokens and treats a *second* use of an already-consumed one as a replay — it revokes the
whole family and logs the user out. A page with a topbar bell, an app switcher and a
preferences hook fires several requests at once, and two tabs fire two sets, so "each caller
refreshes when it gets a 401" is a reliable way to log people out for having two tabs open.

Hence the single-flight: one refresh per presented token, everybody else waits and then
reuses the result.

**The single-flight is only as wide as its cache store**, which is why the store is named
explicitly rather than taken from the default. With a per-process store — the stock
`database` driver on a per-pod SQLite file, say — two replicas refresh the same token
concurrently and produce exactly the replay this class exists to prevent. That is a
correctness requirement, not a performance tuning knob, and `assertStoreIsShareable()` says
so out loud at the first refresh rather than leaving it to be discovered as random logouts
every fifteen minutes.

A per-process store silently turns the single-flight into no flight control at all, and the
symptom — users logged out every time their token expires — looks nothing like its cause.
One line in the log beats that.

## `RESULT_TTL_SECONDS`

How long a completed refresh stays reusable. It only has to outlive the burst of requests one
page load produces; anything longer widens the window in which a bearer token sits in the
cache for no benefit.

Ten seconds, down from thirty. The burst this exists for is one page load — the bell, the app
switcher and the main view, all in flight together — and that is over in well under a second.
The rest was a credential sitting in a table for no reason anybody could name.

## The three timings

The three timings have to be ordered, and they were not.

The call to Auth must finish before its lock expires, or a waiter takes the lock over while
the first call is still in flight and refreshes the same token again. And a waiter must not
give up before the lock could possibly have been released, or it reports "nobody is refreshing
this" while somebody is — and the caller sends the user to the login screen with a live
session.

So: call timeout < lock TTL <= wait.

## After a lock timeout

Somebody has held it for longer than a refresh can take. Serve whatever they produced if it
landed; a short second look costs a moment and saves sending a user with a live session to the
login screen over a race of milliseconds.

## `RefreshedSession` and `withoutCookies()`

`RefreshedSession` is the result of a successful token refresh: the new access token for the
request being served, Auth's own response body for anything relaying it onward, and the
`Set-Cookie` headers to hand back to the browser verbatim.

The cookies are kept as raw header strings rather than being rebuilt, because Auth owns their
attributes — domain, `SameSite`, `Secure`, both lifetimes. Reconstructing them here would mean
a second, silently diverging definition of the platform's session cookies. That reasoning is
about the *response*, and it was quietly applied to the cache too.

`withoutCookies()` is the same session, minus the browser's copy of the rotated refresh token.
`setCookies` are Auth's raw `Set-Cookie` headers, which means they contain the new refresh
token in plain text — a fresh seven-day credential. Storing that in the single-flight cache
put it in the `cache` table, which is to say in every backup, every dump copied to a test
environment, and every debug panel that renders cache contents. ADR-0023 §2 is explicit that a
token is stored hashed so that reading the database never yields a working credential; Auth
honours that and the chrome was undoing it for thirty seconds at a time.

The access token stays: it is a fifteen-minute credential and it is the thing the waiting
requests actually need. The difference between fifteen minutes and seven days is the whole
point of the change.

A request served from the cache therefore sets no cookies. It does not need to — the request
that actually performed the refresh sets them, and it is the same browser.
