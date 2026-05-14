<?php

declare(strict_types=1);

namespace Abeon\SDK\Config;

use Abeon\SDK\Exceptions\ContractViolationException;
use Illuminate\Contracts\Config\Repository;

class AbeonConfig
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function serviceName(): string
    {
        $name = $this->config->get('abeon.service.name');
        if (! is_string($name) || $name === '') {
            throw new \RuntimeException('abeon.service.name is required (set ABEON_SERVICE_NAME)');
        }

        return $name;
    }

    public function authUrl(): string
    {
        return (string) $this->config->get('abeon.auth.url');
    }

    public function authJwksUrl(): string
    {
        $explicit = $this->config->get('abeon.auth.jwks_url');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        return rtrim($this->authUrl(), '/').'/.well-known/jwks.json';
    }

    public function authIssuer(): string
    {
        return (string) $this->config->get('abeon.auth.issuer', 'abeon-auth');
    }

    public function authAudience(): string
    {
        return (string) $this->config->get('abeon.auth.audience', 'abeon');
    }

    public function serviceJwtPrivateKey(): string
    {
        $key = (string) $this->config->get('abeon.auth.service_jwt.private_key', '');
        if ($key === '') {
            throw new \RuntimeException(
                'abeon.auth.service_jwt.private_key is required for outbound service calls (set ABEON_SERVICE_JWT_PRIVATE_KEY).',
            );
        }

        return $key;
    }

    public function serviceJwtKid(): string
    {
        $kid = (string) $this->config->get('abeon.auth.service_jwt.kid', '');
        if ($kid === '') {
            throw new \RuntimeException(
                'abeon.auth.service_jwt.kid is required (set ABEON_SERVICE_JWT_KID).',
            );
        }

        return $kid;
    }

    public function rabbitMqDsn(): ?string
    {
        $dsn = $this->config->get('abeon.events.dsn');

        return is_string($dsn) && $dsn !== '' ? $dsn : null;
    }

    public function rabbitMqExchange(): string
    {
        return (string) $this->config->get('abeon.events.exchange', 'abeon.events');
    }

    /**
     * @return list<string>
     */
    public function healthChecks(): array
    {
        $checks = $this->config->get('abeon.health.checks', []);
        if (is_string($checks)) {
            $checks = array_filter(array_map('trim', explode(',', $checks)));
        }
        if (! is_array($checks)) {
            return [];
        }

        return array_values(array_filter($checks, 'is_string'));
    }

    public function correlationField(): string
    {
        return (string) $this->config->get('abeon.logging.correlation_field', 'correlation_id');
    }

    /**
     * @return array<string, array{url: string}>
     */
    public function services(): array
    {
        $services = $this->config->get('abeon.services', []);

        return is_array($services) ? $services : [];
    }

    public function serviceUrl(string $name): string
    {
        $services = $this->services();
        if (! isset($services[$name]['url']) || ! is_string($services[$name]['url'])) {
            throw ContractViolationException::unknownService($name);
        }

        return $services[$name]['url'];
    }

    /**
     * @return list<string>
     */
    public function declaredPermissions(): array
    {
        $perms = $this->config->get('abeon.permissions', []);
        if (! is_array($perms)) {
            return [];
        }

        return array_values(array_filter($perms, 'is_string'));
    }

    /**
     * @return array<string, mixed>
     */
    public function appDescriptor(): array
    {
        $descriptor = $this->config->get('abeon.app_descriptor', []);

        return is_array($descriptor) ? $descriptor : [];
    }
}
