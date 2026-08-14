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
     * POST this service's descriptor to the registry.
     *
     * Owned by **AbeonUnified** (ADR-0019), not Auth: Auth owns users,
     * memberships, roles and permissions; Unified owns applications and their
     * assignment to organisations. Read paths (`GET /api/v1/auth/apps`) keep their
     * URLs with Auth proxying, because the chrome and both SDKs already call them
     * — but self-registration moves, since nothing outside this SDK depends on it.
     */
    public function register(): void
    {
        $this->client
            ->service('unified')
            ->post('/api/v1/internal/registry/register', $this->descriptor()->toArray());
    }

    /**
     * Read the application catalogue.
     *
     * **This used to call `/api/v1/auth/apps` and could not have worked.** That route is
     * behind `AuthMiddleware`, which calls `decodeUser()` and refuses anything that is
     * not a user token — and `ServiceClient` presents a service token by construction.
     * A 401, on the first call, by definition. Nothing called it, so nothing said so:
     * the method had unit tests, looked finished, and would have failed the moment
     * somebody used it.
     *
     * It now asks Unified, which owns the registry (ADR-0019) and whose internal routes
     * are the ones meant for service tokens.
     *
     * The organisation is required rather than optional: that endpoint requires it, and
     * ADR-0018's rule applies — a catalogue with no tenant is not "every tenant".
     *
     * @return list<AppDescriptor>
     */
    public function list(int $orgId): array
    {
        $response = $this->client
            ->service('unified')
            ->get('/api/v1/internal/registry/catalogue', ['org_id' => $orgId]);
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
