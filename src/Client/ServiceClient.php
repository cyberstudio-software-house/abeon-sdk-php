<?php

declare(strict_types=1);

namespace Abeon\SDK\Client;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Http\CorrelationIdMiddleware;
use Abeon\SDK\Logging\CorrelationContext;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

/**
 * Config-driven service-to-service HTTP client (M1).
 *
 * Usage: `$client->service('crm')->get('/api/v1/contacts/42')`.
 *
 * The SDK does not know about specific services — callers declare them
 * in `config('abeon.services')`. Calls auto-inject a fresh service JWT
 * and propagate the current correlation ID. Unsuccessful responses are
 * converted to ServiceCallException with RFC 7807 details.
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
        return $this->http
            ->baseUrl(rtrim($this->config->serviceUrl($name), '/'))
            ->withToken($this->tokens->token())
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                CorrelationIdMiddleware::HEADER => $this->correlation->ensure(),
            ])
            ->throw(function (Response $response, RequestException $exception): void {
                throw ServiceCallException::fromResponse($response);
            });
    }
}
