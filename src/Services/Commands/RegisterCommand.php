<?php

declare(strict_types=1);

namespace Abeon\SDK\Services\Commands;

use Abeon\SDK\Services\ServiceRegistry;
use Illuminate\Console\Command;
use Throwable;

class RegisterCommand extends Command
{
    protected $signature = 'abeon:registry:register';

    protected $description = 'Register this service in the Abeon Auth service registry (idempotent).';

    public function handle(ServiceRegistry $registry): int
    {
        try {
            $registry->register();
            $this->info('Registered.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
