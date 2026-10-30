<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Integrations;

use Abeon\SDK\AbeonServiceProvider;
use Abeon\SDK\Client\ServiceTokenProvider;
use Abeon\SDK\Integrations\Secrets;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

final class SecretsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AbeonServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('abeon.service.name', 'cms');
        $app['config']->set('abeon.services.unified.url', 'http://unified.test');
        $app['config']->set('abeon.client.max_retries', 0);
        $app->instance(ServiceTokenProvider::class, new class extends ServiceTokenProvider
        {
            public function __construct()
            {
            }

            public function token(?int $orgId = null): string
            {
                return 'fake-service-token';
            }
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        Secrets::forget();
    }

    public function test_a_key_is_read_once_and_kept_in_the_process(): void
    {
        Http::fake(['unified.test/api/v1/internal/secrets/freshmail' => Http::response(['data' => ['api_token' => 'tok']]), 'unified.test/*' => Http::response(null, 404)]);

        $this->assertSame(['api_token' => 'tok'], app(Secrets::class)->get('freshmail'));
        $this->assertSame(['api_token' => 'tok'], app(Secrets::class)->get('freshmail'));
        $this->assertNull(app(Secrets::class)->get('livespace'));

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer fake-service-token'));
    }

    public function test_a_test_result_is_reported(): void
    {
        Http::fake(['unified.test/*' => Http::response(null, 204)]);

        app(Secrets::class)->reportTest('salesmanago', false);

        Http::assertSent(fn (Request $r) => $r->url() === 'http://unified.test/api/v1/internal/secrets/salesmanago/tested' && $r['ok'] === false);
    }
}
