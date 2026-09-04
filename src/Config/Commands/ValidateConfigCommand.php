<?php

declare(strict_types=1);

namespace Abeon\SDK\Config\Commands;

use Abeon\SDK\Config\AbeonConfig;
use Illuminate\Console\Command;
use Throwable;

/**
 * Run-time check of every required SDK config key. Use as a CI gate or
 * post-deploy smoke test:
 *
 *     php artisan abeon:config:validate
 *     # → exit 0 when OK, 1 when any required key is missing/invalid.
 *
 * Misconfiguration used to be discovered only
 * when a code path used the missing key (lazy validation). For services
 * that publish events daily but call ServiceClient rarely, a missing
 * `ABEON_SERVICE_JWT_PRIVATE_KEY` could go unnoticed for weeks.
 */
class ValidateConfigCommand extends Command
{
    protected $signature = 'abeon:config:validate
        {--require-jwt-key : Also require service JWT signing key (only services that make outbound calls)}
        {--require-rabbitmq : Also require RabbitMQ DSN (only services that publish/consume events)}';

    protected $description = 'Validate that all required Abeon SDK config keys are set.';

    public function handle(AbeonConfig $config): int
    {
        $errors = [];
        $checks = [
            ['service name', fn () => $config->serviceName()],
            ['auth URL', fn () => $config->authUrl()],
            ['auth JWKS URL', fn () => $config->authJwksUrl()],
        ];

        if ($this->option('require-jwt-key')) {
            $checks[] = ['service JWT private key', fn () => $config->serviceJwtPrivateKey()];
            $checks[] = ['service JWT kid', fn () => $config->serviceJwtKid()];
        }

        if ($this->option('require-rabbitmq')) {
            $checks[] = ['RabbitMQ DSN', function () use ($config): string {
                $dsn = $config->rabbitMqDsn();
                if ($dsn === null) {
                    throw new \RuntimeException('abeon.events.dsn is not configured (set ABEON_RABBITMQ_DSN).');
                }
                return $dsn;
            }];
        }

        foreach ($checks as [$label, $resolver]) {
            try {
                $value = $resolver();
                $this->line(sprintf('  [ok]   %-30s = %s', $label, $this->mask($value)));
            } catch (Throwable $e) {
                $errors[] = $label;
                $this->line(sprintf('  <fg=red>[fail]</> %-30s %s', $label, $e->getMessage()));
            }
        }

        $this->line('');
        if ($errors === []) {
            $this->info('Config OK — all required keys present.');
            return self::SUCCESS;
        }

        $this->error(sprintf('Config invalid — %d required key(s) missing/invalid.', count($errors)));
        return self::FAILURE;
    }

    /**
     * Mask anything that looks like a secret (heuristic — be conservative).
     */
    private function mask(string $value): string
    {
        if (str_contains($value, 'PRIVATE KEY') || str_contains($value, 'BEGIN')) {
            return '<PEM ... '.strlen($value).' bytes>';
        }
        if (str_contains($value, '://') && str_contains($value, '@')) {
            // amqp://user:pass@host:port/vhost — mask everything between :// and @
            return (string) preg_replace('#://[^@]+@#', '://***:***@', $value);
        }
        return $value;
    }
}
