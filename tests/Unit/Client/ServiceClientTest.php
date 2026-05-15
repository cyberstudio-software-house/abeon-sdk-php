<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Client;

use Abeon\SDK\Client\ServiceClient;
use Abeon\SDK\Client\ServiceCallException;
use Abeon\SDK\Client\ServiceTokenProvider;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Http\CorrelationIdMiddleware;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Support\Uuid;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use PHPUnit\Framework\TestCase;

final class ServiceClientTest extends TestCase
{
    private HttpFactory $http;
    private CorrelationContext $correlation;
    private FakeTokenProvider $tokens;
    private AbeonConfig $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new HttpFactory();
        $this->correlation = new CorrelationContext();
        $this->correlation->set('aaaaaaaa-aaaa-4aaa-baaa-aaaaaaaaaaaa');
        $this->tokens = new FakeTokenProvider();
        $this->config = new AbeonConfig(new Repository([
            'abeon' => [
                'service'  => ['name' => 'test-svc'],
                'services' => [
                    'crm' => ['url' => 'http://crm.test'],
                ],
                'client' => [
                    'timeout'        => 5.0,
                    'connect_timeout'=> 1.0,
                    'max_retries'    => 2,
                    'retry_delay_ms' => 0,  // no real delay in tests
                ],
            ],
        ]));
    }

    private function client(): ServiceClient
    {
        return new ServiceClient($this->http, $this->tokens, $this->correlation, $this->config);
    }

    public function test_injects_authorization_and_correlation_headers(): void
    {
        $this->http->fake(['*' => $this->http->response(['ok' => true], 200)]);

        $this->client()->service('crm')->get('/x');

        $this->http->assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'Bearer fake-service-token')
                && $request->hasHeader(CorrelationIdMiddleware::HEADER, 'aaaaaaaa-aaaa-4aaa-baaa-aaaaaaaaaaaa');
        });
    }

    public function test_injects_idempotency_key_uuid(): void
    {
        $capturedKey = null;
        $this->http->fake(function ($request) use (&$capturedKey) {
            $capturedKey = $request->header('Idempotency-Key')[0] ?? null;
            return $this->http->response(['ok' => true], 200);
        });

        $this->client()->service('crm')->post('/items', ['name' => 'x']);

        $this->assertNotNull($capturedKey);
        $this->assertMatchesRegularExpression(Uuid::REGEX, (string) $capturedKey);
    }

    public function test_throws_service_call_exception_on_4xx(): void
    {
        $this->http->fake([
            '*' => $this->http->response([
                'type'   => 'https://api.abeon.pl/errors/not-found',
                'title'  => 'Not Found',
                'status' => 404,
                'detail' => 'contact 42',
            ], 404, ['Content-Type' => 'application/problem+json']),
        ]);

        try {
            $this->client()->service('crm')->get('/contacts/42');
            $this->fail('Expected ServiceCallException');
        } catch (ServiceCallException $e) {
            $this->assertSame(404, $e->problem->status);
            $this->assertSame('Not Found', $e->problem->title);
            $this->assertSame('contact 42', $e->problem->detail);
        }
    }

    public function test_flushes_token_cache_on_401(): void
    {
        $this->http->fake(['*' => $this->http->response(['title' => 'unauth', 'type' => 't', 'status' => 401], 401)]);

        try {
            $this->client()->service('crm')->get('/x');
        } catch (ServiceCallException) {
            // expected
        }

        $this->assertTrue($this->tokens->flushCalled, 'ServiceTokenProvider::flush() should be called on 401');
    }

    public function test_retries_on_5xx_and_eventually_throws(): void
    {
        // First call: 503. Second: still 503. Should retry (max_retries=2 → up to 3 attempts).
        $this->http->fake([
            '*' => $this->http->sequence()
                ->push(['title' => 'down', 'type' => 't', 'status' => 503], 503)
                ->push(['title' => 'down', 'type' => 't', 'status' => 503], 503)
                ->push(['title' => 'down', 'type' => 't', 'status' => 503], 503),
        ]);

        try {
            $this->client()->service('crm')->get('/x');
            $this->fail('Expected ServiceCallException after retries');
        } catch (ServiceCallException $e) {
            $this->assertSame(503, $e->problem->status);
        }

        // Three total attempts = 1 initial + 2 retries.
        $this->http->assertSentCount(3);
    }

    public function test_does_not_retry_on_4xx(): void
    {
        $this->http->fake(['*' => $this->http->response(['title' => 'bad', 'type' => 't', 'status' => 400], 400)]);

        try {
            $this->client()->service('crm')->post('/x', []);
        } catch (ServiceCallException) {
            // expected
        }

        $this->http->assertSentCount(1);
    }
}

final class FakeTokenProvider extends ServiceTokenProvider
{
    public bool $flushCalled = false;

    public function __construct()
    {
        // Skip parent constructor — we don't need real key signing in tests.
    }

    public function token(): string
    {
        return 'fake-service-token';
    }

    public function flush(): void
    {
        $this->flushCalled = true;
    }
}
