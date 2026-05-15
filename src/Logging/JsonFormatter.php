<?php

declare(strict_types=1);

namespace Abeon\SDK\Logging;

use Monolog\Formatter\JsonFormatter as MonologJsonFormatter;
use Monolog\LogRecord;

/**
 * Loki-friendly JSON log formatter for Monolog 3.
 *
 * Injects two extra fields into every log record so structured log queries
 * across services work uniformly:
 *
 *   - `correlation_id` (or whatever name `$correlationField` is set to) —
 *     pulled from CorrelationContext so logs emitted inside a request
 *     share the inbound `X-Correlation-ID`.
 *   - `service` — pulled from `abeon.service.name` so a Loki query like
 *     `{service="crm"} |~ "error"` works without per-service log labels.
 *
 * Wire-up (consumer service):
 *
 *     // config/logging.php
 *     'channels' => [
 *         'abeon-stdout' => [
 *             'driver' => 'monolog',
 *             'handler' => Monolog\Handler\StreamHandler::class,
 *             'with' => ['stream' => 'php://stdout'],
 *             'formatter' => Abeon\SDK\Logging\JsonFormatter::class,
 *             'formatter_with' => [
 *                 'context'     => app(Abeon\SDK\Logging\CorrelationContext::class),
 *                 'serviceName' => env('ABEON_SERVICE_NAME'),
 *             ],
 *         ],
 *     ],
 */
class JsonFormatter extends MonologJsonFormatter
{
    public function __construct(
        public readonly CorrelationContext $context,
        public readonly string $serviceName,
        public readonly string $correlationField = 'correlation_id',
    ) {
        parent::__construct(
            batchMode: self::BATCH_MODE_JSON,
            appendNewline: true,
            ignoreEmptyContextAndExtra: true,
            includeStacktraces: false,
        );
    }

    public function format(LogRecord $record): string
    {
        $extra = $record->extra;
        $correlation = $this->context->current();
        if ($correlation !== null && ! isset($extra[$this->correlationField])) {
            $extra[$this->correlationField] = $correlation;
        }
        if (! isset($extra['service'])) {
            $extra['service'] = $this->serviceName;
        }

        return parent::format($record->with(extra: $extra));
    }
}
