<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Http;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Http\Cors;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\TestCase;

final class CorsTest extends TestCase
{
    private function cors(array $allowed): Cors
    {
        $repo = new Repository([
            'abeon' => [
                'service' => ['name' => 'crm'],
                'cors'    => ['allowed_origins' => $allowed],
            ],
        ]);

        return new Cors(new AbeonConfig($repo));
    }

    public function test_attaches_headers_for_allow_listed_origin(): void
    {
        $request = Request::create('/x');
        $request->headers->set('Origin', 'https://app.abeon.pl');

        $response = $this->cors(['https://app.abeon.pl'])
            ->handle($request, fn () => new Response('ok'));

        $this->assertSame('https://app.abeon.pl', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        $this->assertStringContainsString('X-Correlation-ID', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_drops_headers_for_disallowed_origin(): void
    {
        $request = Request::create('/x');
        $request->headers->set('Origin', 'https://evil.example.com');

        $response = $this->cors(['https://app.abeon.pl'])
            ->handle($request, fn () => new Response('ok'));

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
        $this->assertNull($response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_wildcard_echoes_actual_origin_for_credentials(): void
    {
        $request = Request::create('/x');
        $request->headers->set('Origin', 'https://anywhere.example');

        $response = $this->cors(['*'])
            ->handle($request, fn () => new Response('ok'));

        // Per CORS spec, '*' with credentials is invalid — middleware echoes the actual origin.
        $this->assertSame('https://anywhere.example', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
    }

    public function test_preflight_short_circuits_with_204(): void
    {
        $request = Request::create('/x', 'OPTIONS');
        $request->headers->set('Origin', 'https://app.abeon.pl');
        $request->headers->set('Access-Control-Request-Method', 'POST');

        $callbackInvoked = false;
        $response = $this->cors(['https://app.abeon.pl'])
            ->handle($request, function () use (&$callbackInvoked) {
                $callbackInvoked = true;

                return new Response('should not run');
            });

        $this->assertSame(204, $response->getStatusCode());
        $this->assertFalse($callbackInvoked);
        $this->assertSame('https://app.abeon.pl', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_no_origin_no_cors_headers(): void
    {
        $request = Request::create('/x');

        $response = $this->cors(['https://app.abeon.pl'])
            ->handle($request, fn () => new Response('ok'));

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_empty_allow_list_drops_all_origins(): void
    {
        $request = Request::create('/x');
        $request->headers->set('Origin', 'https://app.abeon.pl');

        $response = $this->cors([])
            ->handle($request, fn () => new Response('ok'));

        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
