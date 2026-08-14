<?php

declare(strict_types=1);

namespace Abeon\SDK\Http;

use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;

/**
 * Teaches an Abeon service to believe the ingress about the request it received.
 *
 * Every service on this platform sits behind a proxy that terminates TLS. Without a
 * trusted-proxy configuration Laravel ignores `X-Forwarded-Proto` and
 * `X-Forwarded-Host`, so the application believes every request arrived over plain
 * HTTP on whatever host the pod knows itself by. Three things break, all of them only
 * in production:
 *
 * 1. **The return-to parameter stops surviving login.** `AbeonWebAuth` builds it from
 *    `$request->fullUrl()`, which yields `http://app.abeon.pl/settings`, and
 *    `abeon-auth-ui` compares it against an allowlist **including the scheme**
 *    (`RedirectTarget::originOf()`). The origins differ, the request is discarded, and
 *    the user lands on the default page. Every deep link, every time — while working
 *    perfectly over plain HTTP locally, which is why nothing catches it before deploy.
 * 2. **Session and CSRF cookies go out without `Secure`**, because `session.secure`
 *    defaults to null and the framework decides from the scheme it thinks it saw.
 * 3. **`url()` and `route()` generate `http://` links** into an HTTPS site.
 *
 * **What `at` should be.** The default is `*`, which is correct when the only route to
 * the pod is through the ingress — the usual arrangement here — and wrong the moment
 * anything can reach it directly, because then a client sets its own
 * `X-Forwarded-For` and picks its own address. That matters beyond logging: Auth
 * throttles failed logins per `(email, ip)`, so a spoofable address is a spoofable
 * counter. Pin `ABEON_TRUSTED_PROXIES` to the ingress CIDR wherever the network does
 * not already guarantee it.
 */
final class TrustedProxies
{
    /**
     * Applied by `AbeonServiceProvider`, so no service has to remember it.
     *
     * Not a line in each `bootstrap/app.php`. `TrustProxies` is already in Laravel's
     * default global stack and reads these statics at request time, and
     * `Middleware::trustProxies()` does nothing but set them — so putting this in the
     * SDK costs each service nothing and cannot be forgotten in the sixteenth one. It
     * also has to be here rather than in `withMiddleware()`: that callback runs while
     * the application is still being configured, before the config repository is bound,
     * so a `config()` call there fails outright.
     */
    public static function apply(): void
    {
        TrustProxies::at(self::proxies());
        TrustProxies::withHeaders(
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO,
        );
    }

    /**
     * `*` or a list of addresses and CIDRs, whichever the operator configured.
     *
     * @return string|list<string>
     */
    public static function proxies(): string|array
    {
        $configured = config('abeon.http.trusted_proxies', '*');

        if (is_array($configured)) {
            /** @var list<string> $list */
            $list = array_values(array_filter($configured, 'is_string'));

            return $list === [] ? '*' : $list;
        }

        $configured = trim((string) $configured);

        if ($configured === '' || $configured === '*') {
            return '*';
        }

        /** @var list<string> $list */
        $list = array_values(array_filter(array_map('trim', explode(',', $configured)), fn (string $v): bool => $v !== ''));

        return $list === [] ? '*' : $list;
    }
}
