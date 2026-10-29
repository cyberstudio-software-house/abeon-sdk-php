<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Http;

use Abeon\SDK\Http\Outbound\AddressPolicy;
use Abeon\SDK\Http\Outbound\OutboundClient;
use Abeon\SDK\Http\Outbound\OutboundResponse;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Calls to addresses clients type in (ADR-0041). The property that matters is what is never
 * called: an address inside the network, however it is spelled or resolved.
 */
final class OutboundClientTest extends TestCase
{
    private HttpFactory $http;

    /** @var array<string, list<string>> */
    private array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new HttpFactory();
        $this->http->fake(['*' => $this->http->response('ok', 200)]);
        $this->dns = [
            'hooks.example.com' => ['93.184.216.34'],
            'rebind.example.com' => ['93.184.216.34', '10.0.0.5'],
            'metadata.example.com' => ['169.254.169.254'],
            'v6.example.com' => ['2606:2800:220:1::1'],
            'v6-local.example.com' => ['fd00::1'],
            'pod.example.com' => ['10.244.3.7'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'plain http' => ['http://hooks.example.com/x'],
            'loopback' => ['https://127.0.0.1/x'],
            'private' => ['https://192.168.1.10/x'],
            'carrier nat' => ['https://100.64.0.1/x'],
            'multicast' => ['https://224.0.0.1/x'],
            'unspecified' => ['https://0.0.0.0/x'],
            'ipv6 loopback' => ['https://[::1]/x'],
            'ipv6 unique local' => ['https://v6-local.example.com/x'],
            'ipv4-mapped ipv6' => ['https://[::ffff:127.0.0.1]/x'],
            'metadata by name' => ['https://metadata.example.com/latest'],
            'one private answer among public' => ['https://rebind.example.com/x'],
            'cluster range from configuration' => ['https://pod.example.com/x'],
            'credentials in the address' => ['https://user:pass@hooks.example.com/x'],
            'unresolvable' => ['https://nowhere.example.com/x'],
            'not a url' => ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('refused')]
    public function test_an_address_inside_the_network_is_never_called(string $url): void
    {
        $response = $this->client()->send('POST', $url, [], '{}');

        $this->assertSame(OutboundResponse::REFUSED, $response->error);
        $this->assertNotNull($this->client()->refusal($url));
        $this->http->assertNothingSent();
    }

    public function test_a_public_address_is_called_pinned_to_the_checked_ip(): void
    {
        $response = $this->client()->send('POST', 'https://hooks.example.com/revalidate', ['X-Test' => '1'], '{"a":1}');

        $this->assertTrue($response->successful());
        $this->assertSame('ok', $response->excerpt);
        $this->assertNull($this->client()->refusal('https://hooks.example.com/revalidate'));
        $this->assertNull($this->client()->refusal('https://v6.example.com/'));
        $this->http->assertSent(fn (Request $request): bool => $request->url() === 'https://hooks.example.com/revalidate'
            && $request->header('X-Test') === ['1'] && $request->body() === '{"a":1}');
    }

    public function test_a_redirect_is_a_failure_and_not_followed(): void
    {
        $this->http = new HttpFactory();
        $this->http->fake(['*' => $this->http->response('', 302, ['Location' => 'http://10.0.0.1/admin'])]);

        $response = $this->client()->send('POST', 'https://hooks.example.com/x');

        $this->assertSame(OutboundResponse::REDIRECT, $response->error);
        $this->assertFalse($response->successful());
        $this->http->assertSentCount(1);
    }

    public function test_only_a_kilobyte_of_the_answer_is_kept(): void
    {
        $this->http = new HttpFactory();
        $this->http->fake(['*' => $this->http->response(str_repeat('ą', 100000), 500)]);

        $response = $this->client()->send('POST', 'https://hooks.example.com/x');

        $this->assertSame(500, $response->status);
        $this->assertLessThanOrEqual(OutboundClient::EXCERPT, strlen($response->excerpt));
        $this->assertTrue(mb_check_encoding($response->excerpt, 'UTF-8'), 'The cut does not split a character.');
    }

    public function test_plain_http_is_allowed_only_when_asked_for_link_checks(): void
    {
        $this->assertTrue($this->client()->send('HEAD', 'http://hooks.example.com/x', allowHttp: true)->successful());
        $this->assertSame(OutboundResponse::REFUSED, $this->client()->send('HEAD', 'http://127.0.0.1/x', allowHttp: true)->error);
    }

    public function test_cidr_matching_covers_both_families_and_odd_prefixes(): void
    {
        $this->assertTrue(AddressPolicy::contains('100.64.0.0/10', '100.127.255.255'));
        $this->assertFalse(AddressPolicy::contains('100.64.0.0/10', '100.128.0.0'));
        $this->assertTrue(AddressPolicy::contains('fc00::/7', 'fdff::1'));
        $this->assertFalse(AddressPolicy::contains('fc00::/7', 'fe00::1'));
        $this->assertFalse(AddressPolicy::contains('10.0.0.0/8', '::1'));
    }

    private function client(): OutboundClient
    {
        return new OutboundClient($this->http, new AddressPolicy(['10.244.0.0/16']), fn (string $host): array => $this->dns[$host] ?? []);
    }
}
