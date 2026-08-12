<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Client;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Client\ServiceClient;
use Abeon\SDK\Client\ServiceCallException;
use Abeon\SDK\Client\ServiceTokenProvider;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\User;
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

    // --- organisation propagation (ADR-0005 as amended by ADR-0016) ---

    public function test_propagates_the_current_organisation_into_the_service_token(): void
    {
        $this->http->fake(['*' => $this->http->response(['ok' => true], 200)]);

        $auth = new AuthContext();
        $auth->set(new User(
            id: '42', email: 'a@b.c', name: null,
            roles: [], permissions: [], orgId: 7,
        ));

        $client = new ServiceClient(
            $this->http, $this->tokens, $this->correlation, $this->config,
            static fn (): AuthContext => $auth,
        );

        $client->service('crm')->get('/x');

        $this->assertSame([7], $this->tokens->requestedOrgIds);
    }

    public function test_requests_an_organisation_less_token_outside_a_request(): void
    {
        $this->http->fake(['*' => $this->http->response(['ok' => true], 200)]);

        // Console commands, queued jobs and consumers have no AuthContext. Null
        // means "no organisation" — never "all organisations".
        $this->client()->service('crm')->get('/x');

        $this->assertSame([null], $this->tokens->requestedOrgIds);
    }

    public function test_resolves_the_auth_context_per_call_not_once(): void
    {
        $this->http->fake(['*' => $this->http->response(['ok' => true], 200)]);

        // ServiceClient is a singleton while AuthContext is request-scoped, so a
        // held instance would pin the first request's organisation and send every
        // later tenant's calls under it. The resolver must be consulted each time.
        $current = new AuthContext();
        $client  = new ServiceClient(
            $this->http, $this->tokens, $this->correlation, $this->config,
            static fn (): AuthContext => $current,
        );

        $current->set(new User(id: '1', email: 'a@b.c', name: null, roles: [], permissions: [], orgId: 1));
        $client->service('crm')->get('/x');

        $current->clear();
        $current->set(new User(id: '2', email: 'c@d.e', name: null, roles: [], permissions: [], orgId: 2));
        $client->service('crm')->get('/x');

        $this->assertSame([1, 2], $this->tokens->requestedOrgIds);
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

    /** @var list<int|null> every organisation a token was requested for, in order */
    public array $requestedOrgIds = [];

    public function __construct()
    {
        // Skip parent constructor — we don't need real key signing in tests.
    }

    public function token(?int $orgId = null): string
    {
        $this->requestedOrgIds[] = $orgId;

        return 'fake-service-token';
    }

    public function flush(): void
    {
        $this->flushCalled = true;
    }
}
