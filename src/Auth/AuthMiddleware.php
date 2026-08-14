<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth;

use Abeon\SDK\Exceptions\AuthException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthMiddleware
{
    public function __construct(
        private readonly JwtValidator $validator,
        private readonly AuthContext $context,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $token = BearerToken::from($request);
        if ($token === null) {
            throw AuthException::unauthenticated();
        }

        $user = $this->validator->decodeUser($token);
        $this->context->set($user);

        return $next($request);
    }
}
