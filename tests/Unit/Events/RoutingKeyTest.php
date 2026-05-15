<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Events\RoutingKey;
use Abeon\SDK\Exceptions\ContractViolationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RoutingKeyTest extends TestCase
{
    #[DataProvider('validKeys')]
    public function test_accepts_valid_routing_keys(string $key): void
    {
        $this->assertTrue(RoutingKey::isValid($key));
    }

    public static function validKeys(): array
    {
        return [
            'crm contact created' => ['crm.contact.created'],
            'finance invoice paid' => ['finance.invoice.paid'],
            'a b c minimum'      => ['a.b.c'],
            'with underscores'   => ['app_1.res_2.act_3'],
            'digits inside'      => ['crm.contact_v2.created'],
        ];
    }

    #[DataProvider('invalidKeys')]
    public function test_rejects_invalid_routing_keys(string $key): void
    {
        $this->assertFalse(RoutingKey::isValid($key));
    }

    public static function invalidKeys(): array
    {
        return [
            'uppercase'       => ['CRM.contact.created'],
            'two segments'    => ['crm.contact'],
            'four segments'   => ['crm.contact.created.extra'],
            'empty segment'   => ['crm..created'],
            'starts with digit' => ['1crm.contact.created'],
            'starts with underscore' => ['_crm.contact.created'],
            'trailing dot'    => ['crm.contact.created.'],
            'leading dot'     => ['.crm.contact.created'],
            'whitespace'      => ['crm contact created'],
            'empty'           => [''],
        ];
    }

    public function test_assert_valid_throws_on_invalid_key(): void
    {
        try {
            RoutingKey::assertValid('CRM.contact.created');
            $this->fail('Expected ContractViolationException was not thrown');
        } catch (ContractViolationException $e) {
            $this->assertSame('Invalid event routing key', $e->problem->title);
            $this->assertStringContainsString("'CRM.contact.created'", $e->getMessage());
        }
    }

    public function test_assert_valid_passes_silently_on_valid_key(): void
    {
        RoutingKey::assertValid('crm.contact.created');
        $this->expectNotToPerformAssertions();
    }
}
