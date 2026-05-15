<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth\Endpoints;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\DTO\AppDescriptor;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Exceptions\AuthException;
use Abeon\SDK\Http\ApiResponse;
use Abeon\SDK\Services\ServiceRegistry;
use Illuminate\Http\JsonResponse;

/**
 * Base implementation of `GET /api/v1/auth/apps` per ADR-0010.
 *
 * Combines `ServiceRegistry::list()` (the platform-wide app catalog) with
 * `abeon_user()->permissions` (the current user's grants). Returns the subset
 * of apps the user can see in the AppSwitcher.
 *
 * Filtering rule: an app is visible if ANY entry of its declared `permissions`
 * has a prefix that matches ANY of the user's `{app}.{resource}.{action}`
 * permissions. See ADR-0010 §"Filtering rule".
 *
 * Auth service owns this endpoint canonically — it has direct DB access to
 * the registry so an extending controller may bypass `ServiceRegistry::list()`
 * and read the table directly for performance.
 *
 * Requires `abeon.auth` middleware.
 */
class AppsController
{
    public function __construct(
        private readonly AuthContext $authContext,
        private readonly ServiceRegistry $registry,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->authContext->user();
        if ($user === null) {
            throw AuthException::unauthenticated();
        }

        $visible = array_values(array_filter(
            $this->registry->list(),
            fn (AppDescriptor $app) => $this->isVisibleTo($app, $user),
        ));

        return ApiResponse::data($visible, ['total' => count($visible)]);
    }

    /**
     * Public so extending services can override (e.g. add admin-only override).
     */
    public function isVisibleTo(AppDescriptor $app, User $user): bool
    {
        $userPerms = $user->permissions;
        if ($userPerms === []) {
            return false;
        }

        foreach ($app->permissions as $declared) {
            $prefix = strtok($declared, '.');
            if (! is_string($prefix) || $prefix === '') {
                continue;
            }
            foreach ($userPerms as $granted) {
                if (str_starts_with($granted, $prefix.'.')) {
                    return true;
                }
            }
        }

        return false;
    }
}
