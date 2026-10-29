<?php

declare(strict_types=1);

namespace Abeon\SDK\Http\Outbound;

use Closure;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * The one way to call an address a client typed in — a webhook, a form action, a link in content
 * (ADR-0041). HTTPS only, the host resolved once and the connection pinned to the checked address
 * so a second DNS answer cannot point it inside the network, no redirects, short timeouts, and at
 * most 64 KB of the answer read.
 */
final class OutboundClient
{
    public const MAX_READ = 65536;

    public const EXCERPT = 1024;

    /** @var Closure(string): list<string> */
    private readonly Closure $resolve;

    /**
     * @param  (Closure(string): list<string>)|null  $resolve  host → addresses; DNS by default
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly AddressPolicy $policy,
        ?Closure $resolve = null,
    ) {
        $this->resolve = $resolve ?? static fn (string $host): array => gethostbynamel($host) ?: [];
    }

    /**
     * Why an address may not be used, or null when it may. For validating a URL when it is
     * saved; the same check runs again on every call.
     */
    public function refusal(string $url, bool $allowHttp = false): ?string
    {
        return $this->target($url, $allowHttp) === null ? 'Adres musi być publicznym adresem https:// bez loginu i hasła.' : null;
    }

    /**
     * @param  array<string, string>  $headers
     * @param  bool  $allowHttp  only for checking whether a link answers: no secret is sent
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, bool $allowHttp = false, int $timeoutSeconds = 10): OutboundResponse
    {
        $started = hrtime(true);
        $target = $this->target($url, $allowHttp);

        if ($target === null) {
            return new OutboundResponse(null, 0, '', OutboundResponse::REFUSED);
        }

        $request = $this->http
            ->connectTimeout(3)
            ->timeout(min(10, $timeoutSeconds))
            ->withHeaders(['User-Agent' => 'Abeon/1.0', ...$headers])
            ->withOptions([
                'allow_redirects' => false,
                'stream' => true,
                'curl' => [CURLOPT_RESOLVE => ["{$target['host']}:{$target['port']}:{$target['ip']}"]],
            ]);

        if ($body !== null) {
            $request = $request->withBody(Utils::streamFor($body), $headers['Content-Type'] ?? 'application/json');
        }

        try {
            $response = $request->send(strtoupper($method), $url);
        } catch (ConnectionException) {
            return new OutboundResponse(null, self::elapsed($started), '', OutboundResponse::UNREACHABLE);
        }

        $stream = $response->toPsrResponse()->getBody();
        $read = '';
        while (! $stream->eof() && strlen($read) < self::MAX_READ) {
            $chunk = $stream->read(self::MAX_READ - strlen($read));
            if ($chunk === '') {
                break;
            }
            $read .= $chunk;
        }
        $stream->close();
        $excerpt = mb_strcut($read, 0, self::EXCERPT, 'UTF-8');
        $status = $response->status();

        return new OutboundResponse($status, self::elapsed($started), $excerpt, $status >= 300 && $status < 400 ? OutboundResponse::REDIRECT : null);
    }

    /**
     * @return array{host: string, port: int, ip: string}|null
     */
    private function target(string $url, bool $allowHttp): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));

        if (! in_array($scheme, $allowHttp ? ['https', 'http'] : ['https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolve)($host);

        if ($ips === []) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! $this->policy->allows($ip)) {
                return null;
            }
        }

        return ['host' => $host, 'port' => (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80)), 'ip' => $ips[0]];
    }

    private static function elapsed(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
