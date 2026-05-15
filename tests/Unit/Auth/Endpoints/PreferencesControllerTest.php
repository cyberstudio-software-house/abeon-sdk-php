<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Auth\Endpoints;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Auth\Endpoints\PreferencesController;
use Illuminate\Database\ConnectionInterface;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PreferencesControllerTest extends TestCase
{
    public function test_defaults_have_required_chrome_keys(): void
    {
        $defaults = PreferencesController::defaults();

        $this->assertSame(1, $defaults['version']);
        $this->assertSame([], $defaults['chrome']['appOrder']);
        $this->assertSame([], $defaults['chrome']['pinned']);
        $this->assertSame('system', $defaults['chrome']['theme']);
        $this->assertFalse($defaults['chrome']['sidebarCollapsed']);
        $this->assertSame([], $defaults['chrome']['recents']);
    }

    public function test_merge_replaces_top_level_namespaces_with_array_replace_semantics(): void
    {
        $controller = new PreferencesController(
            new AuthContext(),
            $this->createMock(ConnectionInterface::class),
        );

        $base = [
            'version' => 1,
            'chrome'  => [
                'appOrder' => ['crm', 'pm'],
                'theme'    => 'system',
            ],
            'crm' => ['densityMode' => 'compact'],
        ];

        $merged = $this->invokeMerge($controller, $base, [
            'chrome' => ['theme' => 'dark'],
        ]);

        // chrome.theme overridden, chrome.appOrder preserved (array_replace top-level).
        $this->assertSame('dark', $merged['chrome']['theme']);
        $this->assertSame(['crm', 'pm'], $merged['chrome']['appOrder']);
        // unrelated namespaces preserved.
        $this->assertSame(['densityMode' => 'compact'], $merged['crm']);
    }

    public function test_version_is_owned_by_server_and_cannot_be_set_by_client(): void
    {
        $controller = new PreferencesController(
            new AuthContext(),
            $this->createMock(ConnectionInterface::class),
        );

        $merged = $this->invokeMerge($controller, ['version' => 1], [
            'version' => 999,
            'chrome'  => ['theme' => 'light'],
        ]);

        $this->assertSame(1, $merged['version']);
        $this->assertSame('light', $merged['chrome']['theme']);
    }

    public function test_unknown_top_level_namespace_is_accepted(): void
    {
        $controller = new PreferencesController(
            new AuthContext(),
            $this->createMock(ConnectionInterface::class),
        );

        $merged = $this->invokeMerge($controller, ['version' => 1], [
            'future_namespace' => ['anything' => 'goes'],
        ]);

        $this->assertSame(['anything' => 'goes'], $merged['future_namespace']);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function invokeMerge(PreferencesController $controller, array $base, array $incoming): array
    {
        $method = (new ReflectionClass($controller))->getMethod('mergeTopLevel');
        $method->setAccessible(true);

        return $method->invoke($controller, $base, $incoming);
    }
}
