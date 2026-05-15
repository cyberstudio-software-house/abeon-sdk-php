<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Exceptions;

use Abeon\SDK\DTO\ProblemDetails;
use Abeon\SDK\Exceptions\AbeonException;
use Abeon\SDK\Exceptions\AuthException;
use Abeon\SDK\Exceptions\ContractViolationException;
use PHPUnit\Framework\TestCase;

final class AbeonExceptionTest extends TestCase
{
    public function test_message_falls_back_to_title_when_detail_missing(): void
    {
        $exception = new AbeonException(new ProblemDetails(
            type:   'about:blank',
            title:  'Internal Error',
            status: 500,
        ));

        $this->assertSame('Internal Error', $exception->getMessage());
    }

    public function test_message_uses_detail_when_provided(): void
    {
        $exception = new AbeonException(new ProblemDetails(
            type:   'about:blank',
            title:  'Bad Request',
            status: 400,
            detail: 'specific reason',
        ));

        $this->assertSame('specific reason', $exception->getMessage());
    }

    public function test_exception_code_is_zero_not_http_status(): void
    {
        // LO-2: code() is always 0; HTTP status is on $problem->status / status().
        $exception = new AbeonException(new ProblemDetails(
            type:   'about:blank',
            title:  'Not Found',
            status: 404,
        ));

        $this->assertSame(0, $exception->getCode());
        $this->assertSame(404, $exception->status());
        $this->assertSame(404, $exception->problem->status);
    }

    public function test_auth_exception_factories_set_status(): void
    {
        $unauth = AuthException::unauthenticated();
        $this->assertSame(401, $unauth->status());
        $this->assertSame(0, $unauth->getCode());

        $forbidden = AuthException::forbidden();
        $this->assertSame(403, $forbidden->status());
    }

    public function test_contract_violation_exception_factories(): void
    {
        $unknown = ContractViolationException::unknownService('foo');
        $this->assertStringContainsString("'foo'", $unknown->getMessage());

        $outside = ContractViolationException::publishOutsideTransaction('crm.contact.created');
        $this->assertStringContainsString('outside a database transaction', $outside->getMessage());
        $this->assertStringContainsString('crm.contact.created', $outside->getMessage());
    }
}
