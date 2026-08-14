<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Events;

use Abeon\SDK\Events\RoutingKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every routing key the platform actually publishes has a payload schema, and that
 * schema is discoverable.
 *
 * ADR-0002 requires a schema per event. Three keys were being published with none —
 * `auth.user.created`, `auth.membership.created`, `service.permissions.declared` — and
 * the two schemas that did exist were unreachable anyway, because this package never
 * declared `extra.abeon.event-schemas` and `SchemaDiscovery` keys the catalog by
 * **file name**: `app-registered.json` is not a routing key, so it was a contract that
 * existed as a file and not as a contract.
 *
 * Neither gap was visible from either side. Nothing validates payloads against the
 * catalog yet (the envelope schema calls `data` loose in v1), so a missing schema costs
 * nothing until the day someone turns validation on and every publisher breaks at once.
 */
final class PublishedSchemaContractTest extends TestCase
{
    /**
     * Everything published anywhere in the platform, with where it is published from.
     *
     * Kept by hand, because there is nothing to derive it from — a publisher is a method
     * call with a string literal. Adding a `publish()` call and not this line is the
     * failure mode; the point is that the line is cheap and its absence is not.
     *
     * @var array<string, string>
     */
    private const PUBLISHED = [
        'auth.user.created'            => 'abeon-auth/app/Auth/Invitations.php',
        'auth.membership.created'      => 'abeon-auth/app/Auth/Invitations.php',
        'service.permissions.declared' => 'abeon-sdk-php/src/Auth/PermissionsDeclarator.php',
        // Specified by ADR-0022 and not yet published by anything. Listed because the
        // contract half is what this test is about, and ADR-0022's loop cannot be built
        // against a schema nobody can look up.
        'unified.app.registered'       => 'ADR-0022, no publisher yet',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function publishedKeys(): iterable
    {
        foreach (self::PUBLISHED as $key => $where) {
            yield $key => [$key, $where];
        }
    }

    #[DataProvider('publishedKeys')]
    public function test_every_published_key_has_a_discoverable_schema(string $key, string $where): void
    {
        $this->assertTrue(RoutingKey::isValid($key), "`{$key}` is not a valid routing key.");

        $path = $this->schemaDir().'/'.$key.'.json';

        $this->assertFileExists(
            $path,
            "`{$key}` is published from {$where} with no payload schema. ADR-0002 requires one, and "
            ."`SchemaDiscovery` keys the catalog by file name — so the file has to be named `{$key}.json`.",
        );

        $schema = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($schema, "`{$key}.json` is not a JSON object.");
        $this->assertSame('object', $schema['type'] ?? null);
        $this->assertSame(
            "https://schemas.abeon.pl/events/{$key}.json",
            $schema['$id'] ?? null,
            'The `$id` and the file name have to agree, or a reader following one finds the other.',
        );
    }

    public function test_this_package_declares_where_its_schemas_are(): void
    {
        // Without this key `SchemaDiscovery` never looks, `EventCatalog::all()` is empty
        // for every consumer, and every assertion above still passes — the files are
        // there, nothing reads them.
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../../composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertSame('schemas/events', $composer['extra']['abeon']['event-schemas'] ?? null);
    }

    public function test_the_declared_directory_is_the_one_the_schemas_are_in(): void
    {
        $composer = json_decode((string) file_get_contents(__DIR__.'/../../../composer.json'), true);
        $declared = __DIR__.'/../../../'.($composer['extra']['abeon']['event-schemas'] ?? '');

        $this->assertDirectoryExists($declared);
        $this->assertSame(realpath($this->schemaDir()), realpath($declared));
    }

    public function test_every_routing_key_shaped_file_is_one_of_the_known_events(): void
    {
        // The other direction: a schema named like a routing key is a promise that the
        // key exists. Files deliberately *not* named as routing keys — `_envelope.json`,
        // and `notification-requested.json`, whose key `*.notification.requested` is a
        // subscription pattern that `RoutingKey` refuses — are skipped by discovery and
        // are not promises.
        $found = [];

        foreach (glob($this->schemaDir().'/*.json') ?: [] as $file) {
            $name = basename($file, '.json');

            if (RoutingKey::isValid($name)) {
                $found[] = $name;
            }
        }

        sort($found);
        $expected = array_keys(self::PUBLISHED);
        sort($expected);

        $this->assertSame($expected, $found);
    }

    private function schemaDir(): string
    {
        return __DIR__.'/../../../schemas/events';
    }
}
