<?php

declare(strict_types=1);

namespace Abeon\SDK\Events\Commands;

use Abeon\SDK\Events\EventPruner;
use Illuminate\Console\Command;

/**
 * Delete the event bookkeeping that has done its job (ADR-0002).
 *
 * `abeon_event_outbox` keeps every published row and `abeon_processed_events` keeps one row
 * per message ever consumed. Nothing deleted from either and no command existed to, so both
 * grew for the life of the deployment — in every service, and again in every client's
 * instance, where those tables are the client's own database.
 *
 * The rule lives in `EventPruner`; this is the handle on it.
 */
class PruneEventsCommand extends Command
{
    protected $signature = 'abeon:events:prune
        {--days= : Override the retention window from config}
        {--dry-run : Count what would go, delete nothing}';

    protected $description = 'Delete processed outbox rows and processed-event records past their retention window.';

    public function handle(EventPruner $pruner): int
    {
        $days = $this->option('days') === null ? null : (int) $this->option('days');

        if ($days !== null && $days < 1) {
            $this->error('The retention window must be at least one day; refusing to run.');

            return self::FAILURE;
        }

        $counted = $pruner->prune($days, (bool) $this->option('dry-run'));

        $this->info(sprintf(
            $this->option('dry-run')
                ? '%d outbox row(s) and %d processed-event row(s) are past the window.'
                : 'Deleted %d outbox row(s) and %d processed-event row(s).',
            $counted['outbox'],
            $counted['processed'],
        ));

        return self::SUCCESS;
    }
}
