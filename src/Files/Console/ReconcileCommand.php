<?php

declare(strict_types=1);

namespace Abeon\SDK\Files\Console;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\Files\Files;
use Illuminate\Console\Command;

final class ReconcileCommand extends Command
{
    protected $signature = 'abeon:files:reconcile {--orphan-days=7 : Delete objects no record knows once they are this old}';

    protected $description = 'Compare file records with the objects in storage';

    public function handle(Files $files, AbeonConfig $config): int
    {
        $report = $files->reconcile($config->serviceName(), (int) $this->option('orphan-days'));

        foreach ($report['missing'] as $key) {
            $this->warn("missing: {$key}");
        }

        foreach ($report['orphans'] as $key) {
            $this->line("orphan: {$key}");
        }

        $summary = sprintf('%d missing, %d orphans, %d deleted', count($report['missing']), count($report['orphans']), $report['deleted']);
        $this->info($summary);

        return $report['missing'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
