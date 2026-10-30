<?php

declare(strict_types=1);

namespace Abeon\SDK\Audit\Console;

use Abeon\SDK\Audit\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Support\Carbon;

/**
 * The one way entries leave the audit log: past the retention, never younger than 365 days,
 * which the database enforces on its own (ADR-0042).
 */
final class PruneAuditCommand extends Command
{
    public const MIN_DAYS = 365;

    protected $signature = 'abeon:audit:prune';

    protected $description = 'Delete audit entries older than abeon.audit.retention_days';

    public function handle(ConnectionResolverInterface $db): int
    {
        $days = max(self::MIN_DAYS, (int) config('abeon.audit.retention_days'));
        $deleted = $db->connection()->table(AuditLog::TABLE)->where('occurred_at', '<', Carbon::now()->subDays($days))->delete();

        $this->line("Pruned {$deleted} audit entries older than {$days} days.");

        return self::SUCCESS;
    }
}
