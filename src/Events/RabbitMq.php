<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\Config\AbeonConfig;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use RuntimeException;

/**
 * Lazy RabbitMQ connection + channel, plus topology declarations
 * (main exchange + dead-letter exchange).
 */
class RabbitMq
{
    private ?AbstractConnection $connection = null;

    private ?AMQPChannel $channel = null;

    public function __construct(private readonly AbeonConfig $config)
    {
    }

    public function channel(): AMQPChannel
    {
        if ($this->channel !== null) {
            return $this->channel;
        }

        $this->connection = $this->openConnection();
        $this->channel    = $this->connection->channel();

        $this->declareTopology($this->channel);

        return $this->channel;
    }

    public function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->isConnected();
    }

    public function close(): void
    {
        if ($this->channel !== null) {
            $this->channel->close();
            $this->channel = null;
        }
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
    }

    public function ping(): bool
    {
        try {
            $connection = $this->openConnection(probeTimeout: true);
            $isOpen     = $connection->isConnected();
            $connection->close();

            return $isOpen;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * MD-6 fix: explicit `connection_timeout` and `read_write_timeout` so
     * RabbitMQ unreachable doesn't hang the caller for OS-default 60+ seconds.
     * The probe timeout is shorter than the workload timeout — health checks
     * should fail fast.
     */
    private function openConnection(bool $probeTimeout = false): AbstractConnection
    {
        $dsn = $this->config->rabbitMqDsn();
        if ($dsn === null) {
            throw new RuntimeException('abeon.events.dsn is not configured (set ABEON_RABBITMQ_DSN).');
        }

        $parts = parse_url($dsn);
        if (! is_array($parts) || ! isset($parts['host'])) {
            throw new RuntimeException("Invalid RabbitMQ DSN: {$dsn}");
        }

        $connectionTimeout = $probeTimeout
            ? $this->config->rabbitMqHealthProbeTimeout()
            : $this->config->rabbitMqConnectionTimeout();

        return new AMQPStreamConnection(
            host:               $parts['host'],
            port:               $parts['port'] ?? 5672,
            user:               isset($parts['user']) ? urldecode($parts['user']) : 'guest',
            password:           isset($parts['pass']) ? urldecode($parts['pass']) : 'guest',
            vhost:              isset($parts['path']) ? ltrim($parts['path'], '/') : '/',
            connection_timeout: $connectionTimeout,
            read_write_timeout: $this->config->rabbitMqReadWriteTimeout(),
        );
    }

    private function declareTopology(AMQPChannel $channel): void
    {
        $channel->exchange_declare(
            exchange:    $this->config->rabbitMqExchange(),
            type:        AMQPExchangeType::TOPIC,
            passive:     false,
            durable:     true,
            auto_delete: false,
        );

        $channel->exchange_declare(
            exchange:    $this->config->rabbitMqDeadLetterExchange(),
            type:        AMQPExchangeType::TOPIC,
            passive:     false,
            durable:     true,
            auto_delete: false,
        );
    }
}
