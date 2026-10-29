<?php

declare(strict_types=1);

namespace Abeon\SDK\Files\Console;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Files\Files;
use Illuminate\Console\Command;

final class PrunePendingCommand extends Command
{
    protected $signature = 'abeon:files:prune-pending';

    protected $description = 'Remove uploads that were never completed, and failed ones';

    public function handle(Files $files, AbeonConfig $config): int
    {
        $this->line("Pruned {$files->prunePending($config->serviceName())} uploads.");

        return self::SUCCESS;
    }
}
