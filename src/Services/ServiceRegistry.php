<?php

declare(strict_types=1);

namespace Abeon\SDK\Services;

use Abeon\SDK\Client\ServiceClient;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\AppDescriptor;

/**
 * Self-registration into Auth's app registry (M4) + read-only listing.
 *
 * Registration is idempotent — Auth deduplicates by name. Trigger options:
 * - `php artisan abeon:registry:register` (Sprint 1).
 * - Deployment pipeline post-deploy hook (recommended).
 *
 * Auto-registration on app boot is intentionally NOT wired — too eager
 * for HTTP workers + Artisan commands. Sprint 2+ may add a one-shot
 * boot flag once observability is in place.
 */
class ServiceRegistry
{
    public function __construct(
        private readonly ServiceClient $client,
        private readonly AbeonConfig $config,
    ) {
    }

    public function descriptor(): AppDescriptor
    {
        $base = $this->config->appDescriptor();
        $base['name']        = $this->config->serviceName();
        $base['permissions'] = $this->config->declaredPermissions();

        return AppDescriptor::fromArray($base);
    }

    /**
     * POST this service's descriptor to Auth.
     */
    public function register(): void
    {
        $this->client
            ->service('auth')
            ->post('/api/v1/internal/registry/register', $this->descriptor()->toArray());
    }

    /**
     * Read the list of currently registered apps from Auth.
     *
     * @return list<AppDescriptor>
     */
    public function list(): array
    {
        $response = $this->client->service('auth')->get('/api/v1/auth/apps');
        $body     = $response->json();

        if (! is_array($body) || ! isset($body['data']) || ! is_array($body['data'])) {
            return [];
        }

        $apps = [];
        foreach ($body['data'] as $item) {
            if (is_array($item)) {
                $apps[] = AppDescriptor::fromArray($item);
            }
        }

        return $apps;
    }
}
