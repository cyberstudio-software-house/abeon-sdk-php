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
 * Filtering rule (ADR-0010): an app is visible when it is **assigned to the
 * caller's organisation** AND the user holds a matching permission — i.e. ANY
 * entry of the app's declared `permissions` has a prefix matching ANY of the
 * user's `{app}.{resource}.{action}` grants.
 *
 * **This base implementation can only enforce part of that**, and the split is
 * deliberate rather than an oversight:
 *
 *   - The *permission* half is enforced here in full.
 *   - The *assignment* half is only enforced negatively — an app explicitly
 *     marked `enabled === false` is hidden. It cannot be enforced positively,
 *     because `ServiceRegistry::list()` returns descriptors as services
 *     self-registered them, and self-registration leaves `enabled` null by
 *     contract (ADR-0015: "null/ignored on self-registration"). Resolving
 *     `enabled` for an organisation needs the `tenant_apps` relation, which
 *     lives in the registry's owning service (AbeonUnified, ADR-0019).
 *
 * So an extending controller with access to that relation MUST resolve
 * `enabled` per organisation before filtering — which is exactly why Auth owns
 * this endpoint canonically and may bypass `ServiceRegistry::list()` to read
 * the table directly.
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
        // Assignment half, as far as this layer can see it: an app explicitly
        // disabled for the organisation is never visible. `null` means "not
        // resolved here" — see the class docblock — so it is not treated as a
        // denial, or a self-registered catalogue would render empty.
        if ($app->enabled === false) {
            return false;
        }

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
