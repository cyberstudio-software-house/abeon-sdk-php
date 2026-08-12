<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\Actor;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Support\Uuid;
use DateTimeImmutable;
use DateTimeZone;

class EnvelopeBuilder
{
    public function __construct(
        private readonly AbeonConfig $config,
        private readonly CorrelationContext $correlation,
        private readonly AuthContext $auth,
    ) {
    }

    /**
     * Build an event envelope (schemas/events/_envelope.json).
     *
     * `org_id` is taken from the authenticated user (ADR-0016). It is `null` when
     * there is no request context — a console command, a scheduled job, or genuinely
     * platform-level work such as registry self-registration. `null` means "no
     * organisation", never "all organisations": a consumer that requires a tenant
     * must refuse the message rather than process it unscoped (ADR-0018).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function build(
        string $routingKey,
        array $data,
        ?Actor $actor = null,
        ?string $causationId = null,
        string $version = '1.0',
    ): array {
        RoutingKey::assertValid($routingKey);

        return [
            'event_id'   => Uuid::v4(),
            'event_type' => $routingKey,
            'timestamp'  => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'source'     => $this->config->serviceName(),
            'version'    => $version,
            'org_id'     => $this->auth->user()?->orgId,
            'actor'      => ($actor ?? $this->defaultActor())->toArray(),
            'data'       => $data,
            'metadata'   => array_filter([
                'correlation_id' => $this->correlation->ensure(),
                'causation_id'   => $causationId,
            ], fn ($v) => $v !== null),
        ];
    }

    private function defaultActor(): Actor
    {
        $user = $this->auth->user();
        if ($user !== null) {
            return Actor::user($user->id);
        }

        return Actor::service($this->config->serviceName());
    }

}
