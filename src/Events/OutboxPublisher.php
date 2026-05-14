<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\Actor;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;

/**
 * Default EventPublisher — writes envelope to `abeon_event_outbox` so
 * publishing is atomic with the business transaction. Actual delivery
 * to RabbitMQ happens out-of-band via OutboxDrainer.
 */
class OutboxPublisher implements EventPublisher
{
    public const TABLE = 'abeon_event_outbox';

    public function __construct(
        private readonly EnvelopeBuilder $builder,
        private readonly DatabaseManager $db,
        private readonly AbeonConfig $config,
    ) {
    }

    public function publish(
        string $routingKey,
        array $data,
        ?Actor $actor = null,
        ?string $causationId = null,
    ): string {
        $envelope = $this->builder->build($routingKey, $data, $actor, $causationId);

        $this->table()->insert([
            'event_id'    => $envelope['event_id'],
            'routing_key' => $envelope['event_type'],
            'envelope'    => json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at'  => $envelope['timestamp'],
        ]);

        return $envelope['event_id'];
    }

    private function table(): Builder
    {
        return $this->db->connection($this->config->outboxConnection())->table(self::TABLE);
    }
}
