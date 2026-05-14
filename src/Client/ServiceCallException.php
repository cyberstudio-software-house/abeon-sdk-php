<?php

declare(strict_types=1);

namespace Abeon\SDK\Client;

use Abeon\SDK\DTO\ProblemDetails;
use Abeon\SDK\Exceptions\AbeonException;
use Illuminate\Http\Client\Response;

class ServiceCallException extends AbeonException
{
    public static function fromResponse(Response $response): self
    {
        $body = $response->json();

        if (is_array($body) && isset($body['type'], $body['title'], $body['status'])) {
            return new self(ProblemDetails::fromArray($body));
        }

        return new self(new ProblemDetails(
            type:   'https://api.abeon.pl/errors/service-call-failed',
            title:  'Upstream service call failed',
            status: $response->status() ?: 502,
            detail: 'Response did not conform to RFC 7807 (no application/problem+json body).',
        ));
    }
}
