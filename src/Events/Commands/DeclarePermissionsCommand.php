<?php

declare(strict_types=1);

namespace Abeon\SDK\Events\Commands;

use Abeon\SDK\Auth\PermissionsDeclarator;
use Illuminate\Console\Command;
use Throwable;

class DeclarePermissionsCommand extends Command
{
    protected $signature = 'abeon:permissions:declare';

    protected $description = 'Publish service.permissions.declared so Auth can update the RBAC catalog (M5).';

    public function handle(PermissionsDeclarator $declarator): int
    {
        try {
            $eventId = $declarator->declare();
            $this->info("Declared. event_id={$eventId}");

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
