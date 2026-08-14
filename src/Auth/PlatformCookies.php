<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

/**
 * The names of the two cookies the platform's session travels in.
 *
 * **Constants, not configuration.** They used to come from `ABEON_JWT_COOKIE_NAME` and
 * `ABEON_REFRESH_COOKIE_NAME`, which was configuration nobody could safely use: every
 * application has to exclude these two names from cookie encryption, and that exclusion
 * is declared in `bootstrap/app.php`, inside `withMiddleware()` — which runs while the
 * application is still being configured, before the config repository exists. A
 * `config()` call there fails outright. So the exclusion lists were literals while
 * everything else read the config, and a deployment that set the variable would have
 * had its renamed cookie encrypted and then silently discarded as undecryptable:
 * login looks fine, every call afterwards is unauthenticated. That is the exact symptom
 * the exclusion exists to prevent.
 *
 * Making them per-deployment values bought nothing either — Auth issues them, every
 * service reads them, and the browser is the transport. There is no deployment in which
 * two services may disagree about the name, so a variable that can only be set
 * platform-wide and in lockstep is not a variable.
 *
 * `config('abeon.auth.cookies')` still works and still is the way to read them; it is
 * now filled from here rather than from the environment.
 */
final class PlatformCookies
{
    /** The access token, carrying the access TTL as its own lifetime (ADR-0001). */
    public const ACCESS = 'abeon_token';

    /** The refresh token — a seven-day credential Auth stores hashed (ADR-0023). */
    public const REFRESH = 'abeon_refresh';

    /**
     * Both names, for `encryptCookies(except: …)`.
     *
     * Usable from `bootstrap/app.php`, which is the whole point: a class constant is
     * available before the config repository is.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return [self::ACCESS, self::REFRESH];
    }
}
