<?php

declare(strict_types=1);

namespace Abeon\SDK\Events\Commands;

use Abeon\SDK\Events\OutboxDrainer;
use Illuminate\Console\Command;

class OutboxDrainCommand extends Command
{
    protected $signature = 'abeon:events:outbox-drain
        {--once : Run a single drain pass instead of looping}
        {--iterations=0 : Stop after N loop iterations (0 = unbounded)}';

    protected $description = 'Drain pending events from abeon_event_outbox to RabbitMQ.';

    public function handle(OutboxDrainer $drainer): int
    {
        if ($this->option('once')) {
            $count = $drainer->drainOnce();
            $this->info("Processed {$count} event(s).");

            return self::SUCCESS;
        }

        $iterations = (int) $this->option('iterations');
        $drainer->run(maxIterations: $iterations);

        return self::SUCCESS;
    }
}
