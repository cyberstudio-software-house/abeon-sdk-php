<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Messaging;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Events\EnvelopeBuilder;
use Abeon\SDK\Events\InMemoryEventPublisher;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Messaging\MessageRequest;
use Abeon\SDK\Messaging\Messages;
use Abeon\SDK\Tenancy\TenantContext;
use Illuminate\Config\Repository;
use InvalidArgumentException;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessagesTest extends TestCase
{
    private const SCHEMA_NS = 'https://schemas.abeon.pl/';

    private InMemoryEventPublisher $publisher;

    private TenantContext $tenants;

    private function messages(string $serviceName = 'auth'): Messages
    {
        $config = new AbeonConfig(new Repository(['abeon' => ['service' => ['name' => $serviceName]]]));
        $auth = new AuthContext();
        $this->tenants = new TenantContext($auth);
        $this->publisher = new InMemoryEventPublisher(
            new EnvelopeBuilder($config, new CorrelationContext(), $auth, $this->tenants),
        );

        return new Messages($this->publisher, $config);
    }

    private function request(): MessageRequest
    {
        return new MessageRequest(
            template: 'auth.invitation',
            email: 'anna@acme.pl',
            idempotencyKey: 'auth.invitation:1842',
            name: 'Anna Nowak',
            locale: 'pl',
            data: ['organisation' => 'Acme Sp. z o.o.', 'link' => 'https://auth.abeon.pl/invitation/9f3c'],
        );
    }

    public function test_it_publishes_under_the_services_own_routing_key(): void
    {
        $messages = $this->messages('auth');

        $messages->send($this->request());

        $this->assertCount(1, $this->publisher->publishedForRoutingKey('auth.message.requested'));
    }

    public function test_the_payload_is_valid_against_the_shared_schema(): void
    {
        $messages = $this->messages();

        $messages->send($this->request());

        $validator = new Validator();
        $validator->resolver()?->registerPrefix(self::SCHEMA_NS, (string) realpath(__DIR__.'/../../../schemas'));
        $data = json_decode((string) json_encode($this->publisher->published[0]['data']));

        $this->assertTrue(
            $validator->validate($data, self::SCHEMA_NS.'events/message-requested.json')->isValid(),
        );
        $this->assertSame('auth.invitation:1842', $this->publisher->published[0]['data']['idempotency_key']);
    }

    public function test_empty_data_is_still_an_object(): void
    {
        $messages = $this->messages();

        $messages->send(new MessageRequest('auth.password_reset', 'a@b.pl', 'auth.reset:1'));

        $this->assertSame('{}', json_encode($this->publisher->published[0]['data']['data']));
    }

    public function test_the_envelope_carries_the_current_organisation(): void
    {
        $messages = $this->messages();

        $this->tenants->runFor(4, fn () => $messages->send($this->request()));

        $this->assertSame(4, $this->publisher->published[0]['org_id']);
    }

    public function test_a_hyphenated_service_name_still_makes_a_valid_routing_key(): void
    {
        $this->assertSame('auth_ui.message.requested', Messages::routingKeyFor('auth-ui'));
    }

    /**
     * @return iterable<string, array{callable(): MessageRequest}>
     */
    public static function invalidRequests(): iterable
    {
        yield 'template without a service' => [fn () => new MessageRequest('invitation', 'a@b.pl', 'k')];
        yield 'template with a dash' => [fn () => new MessageRequest('auth-ui.invitation', 'a@b.pl', 'k')];
        yield 'not an address' => [fn () => new MessageRequest('auth.invitation', 'anna(at)acme.pl', 'k')];
        yield 'empty idempotency key' => [fn () => new MessageRequest('auth.invitation', 'a@b.pl', '')];
        yield 'unknown locale' => [fn () => new MessageRequest('auth.invitation', 'a@b.pl', 'k', locale: 'de')];
        yield 'user id zero' => [fn () => new MessageRequest('auth.invitation', 'a@b.pl', 'k', userId: 0)];
        yield 'data as a list' => [fn () => new MessageRequest('auth.invitation', 'a@b.pl', 'k', data: ['x'])];
    }

    /**
     * @param  callable(): MessageRequest  $make
     */
    #[DataProvider('invalidRequests')]
    public function test_an_invalid_request_is_refused_before_it_is_published(callable $make): void
    {
        $this->expectException(InvalidArgumentException::class);

        $make();
    }
}
