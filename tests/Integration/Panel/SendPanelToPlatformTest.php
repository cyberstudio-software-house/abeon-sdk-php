<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration\Panel;


/** A panel opened on the instance's own address goes to the same page on the platform. */
final class SendPanelToPlatformTest extends PanelTestCase
{
    public function test_a_direct_visit_is_sent_to_the_platform(): void
    {
        config(['abeon.platform_url' => 'http://app.ddev.site:8150/cms/']);

        $this->get('/settings?tab=a')->assertRedirect('http://app.ddev.site:8150/cms/settings?tab=a');
    }

    public function test_requests_through_the_platform_and_api_calls_are_left_alone(): void
    {
        config(['abeon.platform_url' => 'http://app.ddev.site:8150/cms']);

        $this->withHeaders(['X-Forwarded-Host' => 'app.ddev.site:8150', 'X-Forwarded-Prefix' => '/cms'])
            ->get('/settings')
            ->assertRedirectContains('/login?redirect=');
        $json = $this->getJson('/settings');
        $this->assertStringStartsNotWith('http://app.ddev.site:8150', (string) $json->headers->get('Location'));
    }

    public function test_nothing_happens_without_a_platform_address(): void
    {
        config(['abeon.platform_url' => null]);

        $this->get('/settings')->assertRedirectContains('/login?redirect=');
    }
}
