<?php

declare(strict_types=1);

namespace Abeon\SDK\Events\Commands;

use Abeon\SDK\Events\EventConsumer;
use Illuminate\Console\Command;

class ConsumeCommand extends Command
{
    protected $signature = 'abeon:events:consume
        {--iterations=0 : Stop after N internal loop iterations (0 = unbounded)}';

    protected $description = 'Consume events from RabbitMQ and dispatch to tagged handlers.';

    public function handle(EventConsumer $consumer): int
    {
        $consumer->run(maxIterations: (int) $this->option('iterations'));

        return self::SUCCESS;
    }
}
