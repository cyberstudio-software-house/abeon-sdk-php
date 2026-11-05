<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Ai;

use Abeon\SDK\AbeonServiceProvider;
use Abeon\SDK\Client\ServiceTokenProvider;
use Abeon\SDK\Ai\Ai;
use Abeon\SDK\Client\ServiceCallException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

final class AiTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AbeonServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('abeon.service.name', 'cms');
        $app['config']->set('abeon.services.unified.url', 'http://unified.test');
        $app['config']->set('abeon.services.ai.url', 'http://ai.test');
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
        Ai::forget();
    }

    public function test_a_completion_names_the_feature_and_returns_the_answer(): void
    {
        Http::fake(['unified.test/api/v1/ai/completions' => Http::response(['data' => ['content' => 'Kursy w Katowicach', 'finish_reason' => 'stop', 'model' => 'm', 'usage' => ['input_tokens' => 1, 'output_tokens' => 2, 'cost_usd' => 0.001]]])]);

        $answer = app(Ai::class)->complete('cms.seo', [['role' => 'user', 'content' => 'Strona o kursach']], ['max_tokens' => 200]);

        $this->assertSame('Kursy w Katowicach', $answer['content']);
        Http::assertSent(fn (Request $r) => $r['feature'] === 'cms.seo' && $r['max_tokens'] === 200 && $r->hasHeader('Authorization', 'Bearer fake-service-token'));
    }

    public function test_a_refusal_carries_its_problem_type(): void
    {
        Http::fake(['unified.test/*' => Http::response(['type' => 'https://api.abeon.pl/errors/ai-cap-reached', 'title' => 'AI limit reached', 'status' => 402], 402, ['Content-Type' => 'application/problem+json'])]);

        try {
            app(Ai::class)->complete('cms.seo', [['role' => 'user', 'content' => 'x']]);
            $this->fail('A refusal must throw.');
        } catch (ServiceCallException $e) {
            $this->assertSame([402, 'https://api.abeon.pl/errors/ai-cap-reached'], [$e->problem->status, $e->problem->type]);
        }
    }

    public function test_the_status_is_kept_for_a_minute_and_the_gateway_can_move(): void
    {
        Http::fake(['ai.test/api/v1/internal/ai/status' => Http::response(['data' => ['enabled' => true, 'app_enabled' => true, 'monthly_cap_usd' => 20, 'used_usd' => 4.2, 'month' => '2026-11', 'features' => ['cms.alt']]])]);
        config(['abeon.ai.service' => 'ai']);

        $this->assertTrue(app(Ai::class)->status()['app_enabled']);
        app(Ai::class)->status();

        Http::assertSentCount(1);
    }

    public function test_a_completion_is_never_retried(): void
    {
        $this->app['config']->set('abeon.client.max_retries', 2);
        Http::fake(['unified.test/*' => Http::response(['type' => 'https://api.abeon.pl/errors/ai-provider-error', 'title' => 'AI provider failed', 'status' => 502], 502)]);

        try {
            app(Ai::class)->complete('cms.seo', [['role' => 'user', 'content' => 'x']]);
        } catch (ServiceCallException) {
        }

        Http::assertSentCount(1);
    }
}
