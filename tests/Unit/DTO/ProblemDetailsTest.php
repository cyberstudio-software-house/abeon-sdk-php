<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\DTO;

use Abeon\SDK\DTO\ProblemDetails;
use PHPUnit\Framework\TestCase;

final class ProblemDetailsTest extends TestCase
{
    public function test_basic_round_trip(): void
    {
        $data = [
            'type'   => 'https://api.abeon.pl/errors/not-found',
            'title'  => 'Not Found',
            'status' => 404,
        ];

        $problem = ProblemDetails::fromArray($data);

        $this->assertSame('https://api.abeon.pl/errors/not-found', $problem->type);
        $this->assertSame('Not Found', $problem->title);
        $this->assertSame(404, $problem->status);
        $this->assertNull($problem->detail);
        $this->assertNull($problem->instance);
        $this->assertSame([], $problem->extensions);
    }

    public function test_preserves_extension_members(): void
    {
        $data = [
            'type'     => 'https://api.abeon.pl/errors/validation',
            'title'    => 'Validation Error',
            'status'   => 422,
            'detail'   => 'invalid input',
            'instance' => '/api/v1/contacts',
            'errors'   => ['email' => ['required', 'invalid format']],
            'trace_id' => 'abc-123',
        ];

        $problem = ProblemDetails::fromArray($data);

        $this->assertSame(['errors' => $data['errors'], 'trace_id' => 'abc-123'], $problem->extensions);
        $this->assertSame($data, $problem->toArray());
    }

    public function test_defaults_when_fields_missing(): void
    {
        $problem = ProblemDetails::fromArray([]);

        $this->assertSame('about:blank', $problem->type);
        $this->assertSame('Error', $problem->title);
        $this->assertSame(500, $problem->status);
    }
}
