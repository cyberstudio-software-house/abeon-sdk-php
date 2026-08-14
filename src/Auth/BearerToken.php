<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Illuminate\Http\Request;

/**
 * Pull the bearer token out of an `Authorization` header.
 *
 * One implementation because there were two identical copies, in `AuthMiddleware` and
 * `ServiceAuthMiddleware`, and a copy is a place for the two to disagree later.
 *
 * **The scheme is matched case-insensitively.** RFC 7235 defines the authentication
 * scheme as case-insensitive, and `str_starts_with($header, 'Bearer ')` is not. A client
 * sending `authorization: bearer …` — an integration written outside PHP, or a proxy
 * that normalises header casing — got a 401 saying it presented no token at all, which
 * is a long way from the cause. Nothing internal hits this, because `ServiceClient`
 * always writes `Bearer`, which is precisely why it would have cost somebody outside a
 * day.
 */
final class BearerToken
{
    public static function from(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');

        if (! is_string($header)) {
            return null;
        }

        if (strcasecmp(substr($header, 0, 7), 'Bearer ') !== 0) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token === '' ? null : $token;
    }
}
