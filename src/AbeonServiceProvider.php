<?php

declare(strict_types=1);

namespace Abeon\SDK;

use Illuminate\Support\ServiceProvider;

class AbeonServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bindings for Core / Auth / Events / Client / Services layers
        // will be wired here as Sprint 0–3 modules land.
    }

    public function boot(): void
    {
        // Config publish, route loading, middleware aliases, migrations —
        // added incrementally per sprint.
    }
}
