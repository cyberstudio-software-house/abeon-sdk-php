<?php

declare(strict_types=1);

namespace Abeon\SDK\Logging;

/**
 * Loki-friendly JSON log formatter.
 *
 * Wires `correlation_id` (from CorrelationContext) and `service` into
 * every log record's structured fields.
 *
 * Sprint 0 scaffold: holds collaborators only. Full Monolog integration
 * (extending Monolog\Formatter\JsonFormatter with format(LogRecord)) lands
 * in Sprint 0 step 6 once `composer install` has resolved monolog/monolog
 * via the consumer service's Laravel install.
 */
class JsonFormatter
{
    public function __construct(
        public readonly CorrelationContext $context,
        public readonly string $serviceName,
        public readonly string $correlationField = 'correlation_id',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function contextFields(): array
    {
        return [
            $this->correlationField => $this->context->current(),
            'service'               => $this->serviceName,
        ];
    }
}
