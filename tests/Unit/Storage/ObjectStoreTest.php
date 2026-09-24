<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Storage;

use Abeon\SDK\Client\ServiceClient;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Storage\ObjectPath;
use Abeon\SDK\Storage\ObjectStore;
use Abeon\SDK\Client\ServiceTokenProvider;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Files without credentials (ADR-0034). What these pin down is the boundary: the
 * application names an object inside its own prefix and gets an address back, and
 * anything outside that prefix is refused before a request is even made.
 */
final class ObjectStoreTest extends TestCase
{
    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new HttpFactory();
    }

    public function test_an_upload_asks_unified_once_for_an_address(): void
    {
        $this->http->fake(['*' => $this->http->response([
            'data' => [
                'url'        => 'http://storage.test/abeon-org-1/cms/public/hero.jpg?X-Amz-Signature=deadbeef',
                'expires_at' => '2026-09-24T10:20:00+00:00',
                'headers'    => ['Content-Type' => 'image/jpeg'],
            ],
        ], 200)]);

        $signed = $this->store()->uploadUrl($this->store()->publicPath('hero.jpg'), 'image/jpeg');

        $this->assertStringContainsString('X-Amz-Signature', $signed->url);
        $this->assertSame(['Content-Type' => 'image/jpeg'], $signed->headers);

        $this->http->assertSentCount(1);
        $this->http->assertSent(function (Request $request): bool {
            $this->assertSame('http://unified.test/api/v1/internal/storage/sign', $request->url());
            $this->assertSame([
                'key'          => 'cms/public/hero.jpg',
                'operation'    => 'put',
                'content_type' => 'image/jpeg',
            ], $request->data());

            // Retried by `ServiceClient` on a 5xx, so the signer has to be able to tell a
            // retry from a second request for the same object.
            $this->assertNotEmpty($request->header('Idempotency-Key'));

            return true;
        });
    }

    /**
     * The address of a public object is the same string forever, so fetching it would
     * put AbeonUnified on the path of every image on a published page (ADR-0033) for no
     * information it does not already have.
     */
    public function test_a_public_address_is_derived_without_calling_anything(): void
    {
        $this->http->fake(['*' => $this->http->response([], 500)]);

        $url = $this->store()->publicUrl($this->store()->publicPath('media/2026/hero.jpg'));

        $this->assertSame('http://storage.test/abeon-org-1/cms/public/media/2026/hero.jpg', $url);
        $this->http->assertNothingSent();
    }

    public function test_a_private_object_has_no_public_address_and_a_public_one_is_not_signed(): void
    {
        $store = $this->store();

        $this->expectException(LogicException::class);
        $store->publicUrl(ObjectPath::fromKey('cms/private/invoices/1.pdf'));
    }

    public function test_a_public_object_is_never_signed_for_reading(): void
    {
        $this->expectException(LogicException::class);
        $this->store()->readUrl($this->store()->publicPath('hero.jpg'));
    }

    /**
     * The prefix is the boundary between one application's files and another's. Refused
     * here at the line that made the mistake — and again in AbeonUnified, which is the
     * refusal that matters.
     */
    public function test_a_path_cannot_leave_the_service_prefix(): void
    {
        $store = $this->store();

        foreach (['../../crm/private/secret.pdf', '..', '/etc/passwd', '', 'a//b', '.hidden'] as $path) {
            try {
                $store->publicPath($path);
                $this->fail("Accepted a path that leaves the prefix: {$path}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('cms/private/invoices/2026/inv-1.pdf', $store->privatePath('invoices/2026/inv-1.pdf')->key());
    }

    private function store(): ObjectStore
    {
        $config = new AbeonConfig(new Repository([
            'abeon' => [
                'service'  => ['name' => 'cms'],
                'services' => ['unified' => ['url' => 'http://unified.test']],
                'client'   => ['timeout' => 5.0, 'connect_timeout' => 1.0, 'max_retries' => 0, 'retry_delay_ms' => 0],
                'storage'  => ['public_base_url' => 'http://storage.test/abeon-org-1'],
            ],
        ]));

        $correlation = new CorrelationContext();
        $correlation->set('aaaaaaaa-aaaa-4aaa-baaa-aaaaaaaaaaaa');

        return new ObjectStore(
            new ServiceClient($this->http, new StubTokenProvider(), $correlation, $config),
            $config,
        );
    }
}

final class StubTokenProvider extends ServiceTokenProvider
{
    public function __construct()
    {
    }

    public function token(?int $orgId = null): string
    {
        return 'fake-service-token';
    }
}
