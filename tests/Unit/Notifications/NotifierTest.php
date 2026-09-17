<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Notifications;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\EnvelopeBuilder;
use Abeon\SDK\Events\InMemoryEventPublisher;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Notifications\NotificationChannel;
use Abeon\SDK\Notifications\NotificationRequest;
use Abeon\SDK\Notifications\Notifier;
use Abeon\SDK\Tenancy\TenantContext;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotifierTest extends TestCase
{
    private const SCHEMA_NS = 'https://schemas.abeon.pl/';

    private InMemoryEventPublisher $publisher;

    private TenantContext $tenants;

    private function notifier(string $serviceName = 'crm'): Notifier
    {
        $config = new AbeonConfig(new Repository(['abeon' => ['service' => ['name' => $serviceName]]]));
        $auth = new AuthContext();
        $this->tenants = new TenantContext($auth);
        $this->publisher = new InMemoryEventPublisher(
            new EnvelopeBuilder($config, new CorrelationContext(), $auth, $this->tenants),
        );

        return new Notifier($this->publisher, $config);
    }

    private function request(array $channels = [NotificationChannel::InApp]): NotificationRequest
    {
        return new NotificationRequest(
            userId: 42,
            type: 'crm.deal.assigned',
            title: 'Przypisano Ci szansę sprzedaży',
            body: 'Acme Sp. z o.o. — 50 000 PLN',
            icon: 'briefcase',
            actionUrl: '/crm/deals/abc-123',
            metadata: ['deal_id' => 'abc-123'],
            channels: $channels,
        );
    }

    public function test_it_publishes_under_the_services_own_routing_key(): void
    {
        $notifier = $this->notifier('crm');

        $notifier->notify($this->request());

        $this->assertCount(1, $this->publisher->publishedForRoutingKey('crm.notification.requested'));
    }

    public function test_the_payload_is_valid_against_the_shared_schema(): void
    {
        $notifier = $this->notifier();

        $notifier->notify($this->request([NotificationChannel::InApp, NotificationChannel::Email]));

        $validator = new Validator();
        $validator->resolver()?->registerPrefix(self::SCHEMA_NS, (string) realpath(__DIR__.'/../../../schemas'));
        $data = json_decode((string) json_encode($this->publisher->published[0]['data']));

        $this->assertTrue(
            $validator->validate($data, self::SCHEMA_NS.'events/notification-requested.json')->isValid(),
        );
        $this->assertSame(['in_app', 'email'], $this->publisher->published[0]['data']['channels']);
        $this->assertSame('crm', $this->publisher->published[0]['data']['source_app']);
    }

    public function test_the_envelope_carries_the_current_organisation(): void
    {
        $notifier = $this->notifier();

        $this->tenants->runFor(7, fn () => $notifier->notify($this->request()));

        $this->assertSame(7, $this->publisher->published[0]['org_id']);
    }

    public function test_empty_metadata_is_still_an_object(): void
    {
        $notifier = $this->notifier();

        $notifier->notify(new NotificationRequest(userId: 1, type: 'crm.x', title: 'T', body: 'B'));

        $this->assertSame('{}', json_encode($this->publisher->published[0]['data']['metadata']));
    }

    public function test_a_hyphenated_service_name_still_makes_a_valid_routing_key(): void
    {
        $this->assertSame(
            'boilerplate_inertia.notification.requested',
            Notifier::routingKeyFor('boilerplate-inertia'),
        );
    }

    public function test_duplicate_channels_are_collapsed(): void
    {
        $request = $this->request([NotificationChannel::InApp, NotificationChannel::Email, NotificationChannel::Email]);

        $this->assertSame([NotificationChannel::InApp, NotificationChannel::Email], $request->channels);
    }

    /**
     * @return iterable<string, array{callable(): NotificationRequest}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'email without in_app' => [fn () => new NotificationRequest(1, 't', 'T', 'B', channels: [NotificationChannel::Email])];
        yield 'no channels' => [fn () => new NotificationRequest(1, 't', 'T', 'B', channels: [])];
        yield 'user id zero' => [fn () => new NotificationRequest(0, 't', 'T', 'B')];
        yield 'empty type' => [fn () => new NotificationRequest(1, '', 'T', 'B')];
        yield 'title too long' => [fn () => new NotificationRequest(1, 't', str_repeat('a', 201), 'B')];
        yield 'body too long' => [fn () => new NotificationRequest(1, 't', 'T', str_repeat('a', 2001))];
        yield 'metadata as a list' => [fn () => new NotificationRequest(1, 't', 'T', 'B', metadata: ['a', 'b'])];
    }

    /**
     * @param  callable(): NotificationRequest  $make
     */
    #[DataProvider('invalidRequests')]
    public function test_an_invalid_request_is_refused_before_it_is_published(callable $make): void
    {
        $this->expectException(InvalidArgumentException::class);

        $make();
    }
}
