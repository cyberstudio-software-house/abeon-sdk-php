<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Events\SchemaDiscovery;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class SchemaDiscoveryTest extends TestCase
{
    private string $vendorRoot;
    /** @var list<array{level: string, message: string, context: array}> */
    private array $logEntries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->vendorRoot = sys_get_temp_dir().'/abeon-sdk-test-'.bin2hex(random_bytes(4));
        mkdir($this->vendorRoot, 0o755, true);
        mkdir($this->vendorRoot.'/composer', 0o755, true);
        $this->logEntries = [];
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->vendorRoot);
        parent::tearDown();
    }

    private function rmrf(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $this->rmrf($path.'/'.$entry);
        }
        @rmdir($path);
    }

    private function discovery(): SchemaDiscovery
    {
        $test = $this;
        $logger = new class($test->logEntries) extends AbstractLogger {
            public function __construct(private array &$entries) {}
            public function log($level, $message, array $context = []): void
            {
                $this->entries[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
        return new SchemaDiscovery($this->vendorRoot, $logger);
    }

    private function writeInstalledJson(array $packages): void
    {
        file_put_contents(
            $this->vendorRoot.'/composer/installed.json',
            json_encode(['packages' => $packages], JSON_PRETTY_PRINT),
        );
    }

    private function makePackage(string $name, string $schemasRelPath): string
    {
        $pkgDir = $this->vendorRoot.'/'.$name;
        mkdir($pkgDir, 0o755, true);
        mkdir($pkgDir.'/'.$schemasRelPath, 0o755, true);
        return $pkgDir.'/'.$schemasRelPath;
    }

    public function test_returns_empty_when_installed_json_missing(): void
    {
        $this->assertSame([], $this->discovery()->discover());
    }

    public function test_discovers_schema_from_declaring_package(): void
    {
        $schemaDir = $this->makePackage('vendor/crm', 'schemas/events');
        file_put_contents(
            $schemaDir.'/crm.contact.created.json',
            json_encode(['type' => 'object', 'required' => ['contact_id']]),
        );

        $this->writeInstalledJson([[
            'name'  => 'vendor/crm',
            'extra' => ['abeon' => ['event-schemas' => 'schemas/events']],
        ]]);

        $schemas = $this->discovery()->discover();

        $this->assertArrayHasKey('crm.contact.created', $schemas);
        $this->assertSame(['type' => 'object', 'required' => ['contact_id']], $schemas['crm.contact.created']);
    }

    public function test_rejects_path_traversal_attempt(): void
    {
        // Malicious package declares schemas at "../" which would point to vendor root.
        $this->makePackage('vendor/evil', 'schemas');
        $this->writeInstalledJson([[
            'name'  => 'vendor/evil',
            'extra' => ['abeon' => ['event-schemas' => '../']],
        ]]);

        // Put a JSON file in vendor root that the malicious package should NOT load.
        file_put_contents($this->vendorRoot.'/some.config.json', json_encode(['secret' => 'value']));

        $schemas = $this->discovery()->discover();

        // No schemas loaded — path-escape was caught.
        $this->assertSame([], $schemas);
        // Logger received the warning.
        $messages = array_column($this->logEntries, 'message');
        $this->assertContains('abeon.schema_discovery.path_escape', $messages);
    }

    public function test_skips_invalid_routing_key_filenames(): void
    {
        $schemaDir = $this->makePackage('vendor/crm', 'schemas/events');
        file_put_contents($schemaDir.'/crm.contact.created.json', '{"type":"object"}');
        file_put_contents($schemaDir.'/NOT_A_ROUTING_KEY.json', '{"type":"object"}');

        $this->writeInstalledJson([[
            'name'  => 'vendor/crm',
            'extra' => ['abeon' => ['event-schemas' => 'schemas/events']],
        ]]);

        $schemas = $this->discovery()->discover();

        $this->assertArrayHasKey('crm.contact.created', $schemas);
        $this->assertArrayNotHasKey('NOT_A_ROUTING_KEY', $schemas);
    }

    public function test_logs_warning_on_malformed_json(): void
    {
        $schemaDir = $this->makePackage('vendor/crm', 'schemas/events');
        file_put_contents($schemaDir.'/crm.contact.created.json', 'not json {{{');

        $this->writeInstalledJson([[
            'name'  => 'vendor/crm',
            'extra' => ['abeon' => ['event-schemas' => 'schemas/events']],
        ]]);

        $schemas = $this->discovery()->discover();

        $this->assertSame([], $schemas);
        $messages = array_column($this->logEntries, 'message');
        $this->assertContains('abeon.schema_discovery.schema_not_json_object', $messages);
    }

    public function test_skips_packages_without_extra_metadata(): void
    {
        $this->writeInstalledJson([[
            'name' => 'vendor/no-schemas',
            // no extra.abeon.event-schemas
        ]]);

        $this->assertSame([], $this->discovery()->discover());
    }
}
