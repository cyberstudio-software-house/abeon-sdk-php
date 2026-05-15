<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth\Endpoints;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Exceptions\AuthException;
use Abeon\SDK\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Base implementation of `GET /api/v1/auth/user` per ADR-0010.
 *
 * Returns the JWT-derived User DTO (see `abeon_user()`). The Auth service
 * is the canonical owner of this endpoint; other services may mount it for
 * debugging only.
 *
 * Requires `abeon.auth` middleware.
 */
class UserController
{
    public function __construct(private readonly AuthContext $authContext)
    {
    }

    public function __invoke(): JsonResponse
    {
        $user = $this->authContext->user();
        if ($user === null) {
            throw AuthException::unauthenticated();
        }

        return ApiResponse::data($user);
    }
}
