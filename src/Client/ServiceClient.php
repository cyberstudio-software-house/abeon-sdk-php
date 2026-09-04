<?php

declare(strict_types=1);

namespace Abeon\SDK\Client;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Http\CorrelationIdMiddleware;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Support\Uuid;
use Abeon\SDK\Tenancy\TenantContext;
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
 * Behaviour:
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
    /**
     * @param  (\Closure(): ?TenantContext)|null  $tenantResolver  Resolves the *current*
     *        `TenantContext`. A closure, not an instance: this client is bound as a
     *        singleton while `TenantContext` is request-scoped, so holding one would pin
     *        the first request's organisation for the life of the process and send every
     *        later tenant's calls under it.
     */
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ServiceTokenProvider $tokens,
        private readonly CorrelationContext $correlation,
        private readonly AbeonConfig $config,
        private readonly ?\Closure $tenantResolver = null,
    ) {
    }

    /**
     * Open a request to another service.
     *
     * When the call is made while serving a user request, the current organisation
     * (ADR-0016) is propagated into the service token so the callee can scope its
     * work. Outside a request — console commands, queued jobs, consumers — there is
     * no organisation and the token carries none (ADR-0005 as amended).
     */
    public function service(string $name): PendingRequest
    {
        $request = $this->http
            ->baseUrl(rtrim($this->config->serviceUrl($name), '/'))
            ->withToken($this->tokens->token($this->currentOrgId()))
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
                // Flush the service-token cache on 401 so the next call retries
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
    /**
     * Organisation this call is being made on behalf of, or null.
     *
     * **From `TenantContext`, not `AuthContext`.** It read the latter until 2026-09-04,
     * which meant every outbound call made from inside `TenantContext::runFor()` — an
     * event consumer, a queued job, a console command, `abeon:registry:import` — minted
     * a token carrying no organisation, silently, from a unit of work that had one.
     * `TenantContext` falls back to the authenticated user, so the HTTP path is
     * unchanged; the paths that have no authenticated user are the ones that were wrong.
     *
     * The identical defect was fixed in `EnvelopeBuilder` on the same day. This is its
     * other half: the envelope's `org_id` and the service token's `org_id` are the two
     * places the tenant crosses a service boundary (ADR-0005, ADR-0016).
     *
     * Resolved on every call — see the constructor note on why this must not be a held
     * instance.
     */
    private function currentOrgId(): ?int
    {
        if ($this->tenantResolver === null) {
            return null;
        }

        $tenants = ($this->tenantResolver)();

        return $tenants instanceof TenantContext ? $tenants->current() : null;
    }

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
