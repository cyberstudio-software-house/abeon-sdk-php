<?php

declare(strict_types=1);

namespace Abeon\SDK\Audit;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Logging\CorrelationContext;
use Abeon\SDK\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Writes the service's audit log (ADR-0042). Call it inside the transaction that made the change:
 * no change without its entry, and no entry for a change that was rolled back.
 *
 * The actor is the person really acting: with an impersonated token (ADR-0001, `act`) that is the
 * one in `act`, and the user whose token it is goes to `on_behalf_of`.
 */
final class AuditLog
{
    public const TABLE = 'abeon_audit_log';

    /** Changed, but never written down: secrets, passwords, tokens. */
    public const MASK = '[changed]';

    private const SECRET = '/(secret|password|token|key_hash|api_key|private_key)/i';

    private const MAX_VALUE = 2000;

    public function __construct(
        private readonly ConnectionResolverInterface $db,
        private readonly Container $container,
    ) {}

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes  field => [before, after]
     * @param  array<string, scalar|null>  $context  e.g. the site the change belongs to
     */
    public function record(
        string $action,
        ?string $subjectType = null,
        string|int|null $subjectId = null,
        ?string $subjectLabel = null,
        array $changes = [],
        array $context = [],
    ): void {
        $user = $this->container->make(AuthContext::class)->user();
        $request = $this->container instanceof Application && ! $this->container->runningInConsole() ? $this->container->make(Request::class) : null;
        $impersonated = $user?->actorId !== null;

        $this->db->connection()->table(self::TABLE)->insert([
            'occurred_at' => Carbon::now(),
            'actor_type' => $user === null ? 'system' : 'user',
            'actor_id' => $impersonated ? $user->actorId : $user?->id,
            'actor_name' => $impersonated ? null : ($user?->name ?? $user?->email),
            'on_behalf_of' => $impersonated ? $user->id : null,
            'org_id' => $this->container->make(TenantContext::class)->current(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'subject_label' => $subjectLabel === null ? null : mb_substr($subjectLabel, 0, 255),
            'changes' => $changes === [] ? null : json_encode(self::clean($changes), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'ip' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 500),
            'correlation_id' => $this->container->make(CorrelationContext::class)->current(),
        ]);
    }

    /**
     * Secrets masked, long values cut: the log says what changed, not everything it was.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @return array<string, array{0: mixed, 1: mixed}|string>
     */
    public static function clean(array $changes): array
    {
        $clean = [];

        foreach ($changes as $field => [$before, $after]) {
            $clean[$field] = preg_match(self::SECRET, (string) $field) === 1 ? self::MASK : [self::cut($before), self::cut($after)];
        }

        return $clean;
    }

    private static function cut(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        $encoded = is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);

        return mb_strlen($encoded) > self::MAX_VALUE ? self::MASK : $value;
    }
}
