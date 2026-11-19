<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Same-origin proxy for `/api/v1/notifications/*` — the topbar bell (ADR-0006),
 * served by AbeonUnified (ADR-0019); see docs/notes/service-proxy.md.
 */
class NotificationProxyController
{
    use ProxiesToService;

    public function __invoke(Request $request): Response
    {
        return $this->relay($request, (string) config('abeon.unified.url'), '/api/v1/notifications');
    }
}
