<?php

declare(strict_types=1);

namespace Abeon\SDK\Events;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Config\AbeonConfig;
use Abeon\SDK\DTO\Actor;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Support\Uuid;
use Abeon\SDK\Tenancy\TenantContext;
use DateTimeImmutable;
use DateTimeZone;

/**
 * **Bound `scoped`, not `singleton`** (`AbeonServiceProvider::registerEvents`). Two of
 * its three collaborators are request-scoped, and a singleton holding them pins the
 * first request's user, organisation and correlation id into every event the process
 * publishes afterwards. Under php-fpm that is invisible — one request per process — and
 * under Octane or any long-lived worker every event after the first carries the wrong
 * actor and the wrong causation chain. Same trap `ServiceClient` documents in its own
 * constructor.
 */
class EnvelopeBuilder
{
    public function __construct(
        private readonly AbeonConfig $config,
        private readonly CorrelationContext $correlation,
        private readonly AuthContext $auth,
        private readonly TenantContext $tenants,
    ) {
    }

    /**
     * Build an event envelope (schemas/events/_envelope.json).
     *
     * `org_id` comes from `TenantContext` (ADR-0016), which falls back to the
     * authenticated user in an HTTP request and is set explicitly everywhere else.
     * It read `AuthContext` directly until 2026-09-04, which meant an event published
     * from an event consumer, a queued job or a console command carried `org_id: null`
     * **even inside `TenantContext::runFor()`** — the one mechanism the SDK documents
     * for exactly that case. Every such event was untenanted, and ADR-0002 requires the
     * envelope to carry the organisation.
     *
     * `null` is still a legitimate value: a console command that opted into no tenant,
     * or genuinely platform-level work such as registry self-registration. It means "no
     * organisation", never "all organisations" — a consumer that requires a tenant must
     * refuse the message rather than process it unscoped (ADR-0018).
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
            'org_id'     => $this->tenants->current(),
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
