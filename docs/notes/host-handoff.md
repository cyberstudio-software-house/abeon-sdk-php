# Host handoff callback

Background for `src/Panel/HandoffCallbackController.php`.

`GET /auth/callback?code=…&next=…` is where a client's host picks up a session (ADR-0031 §3).

The login screen lives on the platform's own host and its cookies stay there. This application
is on the client's host, so it exchanges the one-time code the browser arrived with for a
session of its own and writes the platform cookies itself, host-only.

Everything here fails towards the login screen: a code that has expired, been used, or was
minted for another host is indistinguishable from a stale bookmark, and the answer to all of
them is to sign in again.

## The cookies it writes

Host-only and `path=/`: every application of this client shares the session, and no other
client's host ever receives it. `httpOnly` because nothing in the browser reads these — the
server attaches the token as a bearer header on the way out.

## `next`

A path inside this application, never somewhere else. `next` comes from a URL the browser was
handed, so an absolute address here would make this route an open redirect that hands out a
fresh session on the way.
