<?php

declare(strict_types=1);

namespace Abeon\SDK\Audit\Console;

use Abeon\SDK\Audit\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\QueryException;

/**
 * Asks the database, rather than trusting it: writes a probe entry and tries to change and
 * delete it. Both must fail. A deploy gate, like `abeon:jwks:check`.
 */
final class CheckAuditCommand extends Command
{
    public const PROBE = 'system.audit.append_only_probe';

    protected $signature = 'abeon:audit:check';

    protected $description = 'Verify the database refuses UPDATE and DELETE on the audit log';

    public function handle(AuditLog $log, ConnectionResolverInterface $db): int
    {
        $log->record(self::PROBE);
        $table = $db->connection()->table(AuditLog::TABLE);
        $id = $table->where('action', self::PROBE)->max('id');
        $holes = [];

        foreach (['UPDATE' => fn () => $table->clone()->where('id', $id)->update(['action' => 'tampered']), 'DELETE' => fn () => $table->clone()->where('id', $id)->delete()] as $verb => $attempt) {
            try {
                $attempt();
                $holes[] = $verb;
            } catch (QueryException) {
            }
        }

        if ($holes !== []) {
            $this->error('The audit log accepted '.implode(' and ', $holes).'. Run the audit migration; its triggers make the log append-only.');

            return self::FAILURE;
        }

        $this->info('The audit log is append-only: UPDATE and DELETE were refused.');

        return self::SUCCESS;
    }
}
