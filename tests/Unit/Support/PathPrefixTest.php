<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Unit\Support;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Support\PathPrefix;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;

final class PathPrefixTest extends TestCase
{
    private function pathPrefix(?string $prefix): PathPrefix
    {
        $repo = new Repository([
            'abeon' => [
                'service'        => ['name' => 'crm'],
                'app_descriptor' => ['path' => $prefix],
            ],
        ]);

        return new PathPrefix(new AbeonConfig($repo));
    }

    public function test_prepends_prefix_to_relative_path(): void
    {
        $pp = $this->pathPrefix('/crm');

        $this->assertSame('/crm/contacts/42', $pp->absolute('/contacts/42'));
        $this->assertSame('/crm/contacts/42', $pp->absolute('contacts/42'));
    }

    public function test_returns_relative_unchanged_when_no_prefix(): void
    {
        $pp = $this->pathPrefix(null);

        $this->assertSame('/contacts/42', $pp->absolute('/contacts/42'));
    }

    public function test_returns_relative_unchanged_when_prefix_is_root(): void
    {
        $pp = $this->pathPrefix('/');

        $this->assertSame('/contacts/42', $pp->absolute('/contacts/42'));
    }

    public function test_does_not_double_prefix(): void
    {
        $pp = $this->pathPrefix('/crm');

        $this->assertSame('/crm/contacts/42', $pp->absolute('/crm/contacts/42'));
        $this->assertSame('/crm', $pp->absolute('/crm'));
    }

    public function test_passes_through_absolute_urls(): void
    {
        $pp = $this->pathPrefix('/crm');

        $this->assertSame('https://app.abeon.pl/crm/x', $pp->absolute('https://app.abeon.pl/crm/x'));
        $this->assertSame('http://internal/contacts', $pp->absolute('http://internal/contacts'));
    }

    public function test_empty_input_returns_prefix(): void
    {
        $pp = $this->pathPrefix('/crm');

        $this->assertSame('/crm', $pp->absolute(''));
        $this->assertSame('', $this->pathPrefix(null)->absolute(''));
    }

    public function test_normalises_prefix_slashes(): void
    {
        $pp = $this->pathPrefix('crm/');

        $this->assertSame('/crm', $pp->prefix());
        $this->assertSame('/crm/foo', $pp->absolute('/foo'));
    }
}
