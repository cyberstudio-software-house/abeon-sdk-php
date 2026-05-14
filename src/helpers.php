<?php

declare(strict_types=1);

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\DTO\User;
use Abeon\SDK\Logging\CorrelationContext;

if (! function_exists('abeon_user')) {
    /**
     * Return the currently authenticated Abeon user, or null.
     *
     * Delegates to the request-scoped Abeon\SDK\Auth\AuthContext.
     */
    function abeon_user(): ?User
    {
        return app(AuthContext::class)->user();
    }
}

if (! function_exists('abeon_correlation_id')) {
    /**
     * Return the current correlation ID for this request, or null
     * if no correlation has been established yet.
     */
    function abeon_correlation_id(): ?string
    {
        return app(CorrelationContext::class)->current();
    }
}
