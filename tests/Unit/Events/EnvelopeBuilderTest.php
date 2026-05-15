<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\Actor;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Events\EnvelopeBuilder;
use Abeon\SDK\Exceptions\ContractViolationException;
use Abeon\SDK\Logging\CorrelationContext;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class EnvelopeBuilderTest extends TestCase
{
    private function builder(?CorrelationContext $correlation = null, ?AuthContext $auth = null): EnvelopeBuilder
    {
        $config = new AbeonConfig(new Repository(['abeon' => ['service' => ['name' => 'crm']]]));

        return new EnvelopeBuilder(
            $config,
            $correlation ?? new CorrelationContext(),
            $auth ?? new AuthContext(),
        );
    }

    public function test_envelope_has_all_required_fields(): void
    {
        $envelope = $this->builder()->build('crm.contact.created', ['contact_id' => 1]);

        $this->assertArrayHasKey('event_id', $envelope);
        $this->assertSame('crm.contact.created', $envelope['event_type']);
        $this->assertArrayHasKey('timestamp', $envelope);
        $this->assertSame('crm', $envelope['source']);
        $this->assertSame('1.0', $envelope['version']);
        $this->assertArrayHasKey('actor', $envelope);
        $this->assertSame(['contact_id' => 1], $envelope['data']);
        $this->assertArrayHasKey('metadata', $envelope);
    }

    public function test_event_id_is_uuid_v4(): void
    {
        $envelope = $this->builder()->build('crm.contact.created', []);

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $envelope['event_id'],
        );
    }

    public function test_timestamp_is_iso_8601_utc_with_ms(): void
    {
        $envelope = $this->builder()->build('crm.contact.created', []);

        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $envelope['timestamp'],
        );
    }

    public function test_invalid_routing_key_throws(): void
    {
        $this->expectException(ContractViolationException::class);
        $this->builder()->build('INVALID', []);
    }

    public function test_actor_defaults_to_service_when_no_user(): void
    {
        $envelope = $this->builder()->build('crm.contact.created', []);

        $this->assertSame('service', $envelope['actor']['type']);
        $this->assertSame('crm', $envelope['actor']['service_name']);
    }

    public function test_actor_defaults_to_user_when_authenticated(): void
    {
        $auth = new AuthContext();
        $auth->set(new User(
            id: '42',
            email: 'a@b.c',
            name: null,
            roles: [],
            permissions: [],
            orgId: null,
        ));

        $envelope = $this->builder(auth: $auth)->build('crm.contact.created', []);

        $this->assertSame('user', $envelope['actor']['type']);
        $this->assertSame('42', $envelope['actor']['user_id']);
    }

    public function test_explicit_actor_overrides_default(): void
    {
        $envelope = $this->builder()->build('crm.contact.created', [], Actor::system());

        $this->assertSame('system', $envelope['actor']['type']);
    }

    public function test_correlation_id_propagated_to_metadata(): void
    {
        $correlation = new CorrelationContext();
        $correlation->set('correlation-abc');

        $envelope = $this->builder(correlation: $correlation)->build('crm.contact.created', []);

        $this->assertSame('correlation-abc', $envelope['metadata']['correlation_id']);
    }

    public function test_causation_id_included_when_provided(): void
    {
        $envelope = $this->builder()->build(
            'crm.contact.created',
            [],
            causationId: 'cause-123',
        );

        $this->assertSame('cause-123', $envelope['metadata']['causation_id']);
    }

    public function test_causation_id_omitted_when_null(): void
    {
        $envelope = $this->builder()->build('crm.contact.created', []);

        $this->assertArrayNotHasKey('causation_id', $envelope['metadata']);
    }
}
