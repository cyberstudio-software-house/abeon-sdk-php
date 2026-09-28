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
 * **Both are written at the top level of the line, not inside `extra`.** Monolog serialises
 * `extra` as a nested object, which Loki's `| json` flattens to `extra_correlation_id` — so
 * ADR-0003's own payoff query, `{service="crm"} | json | correlation_id="…"`, matched nothing.
 * The whole mechanism exists for that query.
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

    /**
     * @return array<array<mixed>|bool|float|int|\stdClass|string|null>
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        $normalized = parent::normalizeRecord($record);

        foreach ([$this->correlationField, 'service'] as $field) {
            if (isset($normalized['extra'][$field])) {
                $normalized[$field] = $normalized['extra'][$field];
                unset($normalized['extra'][$field]);
            }
        }

        if (($normalized['extra'] ?? null) === [] && $this->ignoreEmptyContextAndExtra) {
            unset($normalized['extra']);
        }

        return $normalized;
    }
}
