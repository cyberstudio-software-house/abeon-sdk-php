<?php

declare(strict_types=1);

namespace Abeon\SDK\Client;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Http\CorrelationIdMiddleware;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Support\Uuid;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * Config-driven service-to-service HTTP client (M1).
 *
 * Usage: `$client->service('crm')->get('/api/v1/contacts/42')`.
 *
 * Behavior — Sprint 4 (MD-4, MD-5, MD-10 from code review):
 *
 *   - Auto-injects service JWT (`Authorization: Bearer ...`) and the current
 *     `X-Correlation-ID` on every call.
 *   - On unsafe methods (POST/PUT/PATCH/DELETE), generates a fresh
 *     `Idempotency-Key` (UUIDv4) per call so server-side dedup makes retries
 *     safe even when the operation isn't naturally idempotent.
 *   - Retries on connection failures and HTTP 5xx using Laravel's HTTP client
 *     retry middleware: `max_retries` attempts with `retry_delay_ms` between.
 *   - On a 401 from the upstream, the `$onAuthFailure` callback (when wired)
 *     flushes the service-token cache so the next call mints a new token —
 *     covers key rotation windows.
 *   - Non-2xx responses are thrown as `ServiceCallException` carrying RFC 7807
 *     details lifted from the upstream body.
 */
class ServiceClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ServiceTokenProvider $tokens,
        private readonly CorrelationContext $correlation,
        private readonly AbeonConfig $config,
    ) {
    }

    public function service(string $name): PendingRequest
    {
        $request = $this->http
            ->baseUrl(rtrim($this->config->serviceUrl($name), '/'))
            ->withToken($this->tokens->token())
            ->acceptJson()
            ->asJson()
            ->timeout((int) ceil($this->config->clientTimeoutSeconds()))
            ->connectTimeout((int) ceil($this->config->clientConnectTimeoutSeconds()))
            ->withHeaders([
                CorrelationIdMiddleware::HEADER => $this->correlation->ensure(),
                'Idempotency-Key'               => Uuid::v4(),
            ])
            ->retry(
                $this->config->clientMaxRetries() + 1,
                $this->config->clientRetryDelayMs(),
                fn (\Throwable $exception): bool => $this->shouldRetry($exception),
                throw: false,
            )
            ->throw(function (Response $response, RequestException $exception): void {
                // MD-5: flush service-token cache on 401 so next call retries
                // with a freshly minted token — covers key rotation windows.
                if ($response->status() === 401) {
                    $this->tokens->flush();
                }
                throw ServiceCallException::fromResponse($response);
            });

        return $request;
    }

    /**
     * Retry policy: transient network / 5xx only. 4xx responses are caller
     * bugs (validation, auth, not-found) — retry doesn't help.
     */
    private function shouldRetry(\Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException || $exception instanceof ConnectException) {
            return true;
        }
        if ($exception instanceof RequestException) {
            $status = $exception->response->status();
            return $status >= 500 && $status < 600;
        }

        return false;
    }
}
