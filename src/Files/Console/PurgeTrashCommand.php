<?php

declare(strict_types=1);

namespace Abeon\SDK\Files\Console;

use Abeon\SDK\Files\Files;
use Illuminate\Console\Command;

final class PurgeTrashCommand extends Command
{
    protected $signature = 'abeon:files:purge-trash';

    protected $description = 'Remove files that have been in the trash longer than the retention period';

    public function handle(Files $files): int
    {
        $this->line("Purged {$files->purgeExpiredTrash()} files.");

        return self::SUCCESS;
    }
}
