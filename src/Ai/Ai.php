<?php

declare(strict_types=1);

namespace Abeon\SDK\Ai;

use Abeon\SDK\Client\ServiceClient;
use Illuminate\Http\Client\PendingRequest;

/**
 * The AI gateway (ADR-0020), for an application: name a feature, send the messages, get the
 * answer. A completion is tried once: the gateway charges every call it forwards, so a retry after
 * a timeout could pay twice for one answer. The model, the limits and the cost are the gateway's. The service that runs the gateway
 * is configuration (`abeon.ai.service`), so moving it out of AbeonUnified changes no caller.
 */
final class Ai
{
    public const STATUS_TTL = 60;

    /** @var array{0: array<string, mixed>, 1: int}|null */
    private static ?array $status = null;

    public function __construct(private readonly ServiceClient $client) {}

    /**
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages
     * @param  array{max_tokens?: int, temperature?: float, response_format?: array<string, mixed>}  $options
     * @return array{content: string, finish_reason: string|null, model: string, usage: array{input_tokens: int, output_tokens: int, cost_usd: float}}
     *
     * @throws \Abeon\SDK\Client\ServiceCallException  403 ai-disabled / ai-app-not-enabled, 402 ai-cap-reached, 502 ai-provider-error
     */
    public function complete(string $feature, array $messages, array $options = []): array
    {
        /** @var array{content: string, finish_reason: string|null, model: string, usage: array{input_tokens: int, output_tokens: int, cost_usd: float}} $answer */
        $answer = $this->gateway()->timeout((int) config('abeon.ai.timeout', 90))->retry(1, 0, throw: false)
            ->post('/api/v1/ai/completions', ['feature' => $feature, 'messages' => $messages, ...$options])
            ->json('data');

        self::$status = null;

        return $answer;
    }

    /**
     * Whether the organisation and this application may use AI, the cap and what is used, kept in
     * the process for a minute.
     *
     * @return array{enabled: bool, app_enabled: bool, monthly_cap_usd: float, used_usd: float, month: string, features: list<string>}
     */
    public function status(): array
    {
        if (self::$status !== null && self::$status[1] > time()) {
            /** @var array{enabled: bool, app_enabled: bool, monthly_cap_usd: float, used_usd: float, month: string, features: list<string>} */
            return self::$status[0];
        }

        /** @var array{enabled: bool, app_enabled: bool, monthly_cap_usd: float, used_usd: float, month: string, features: list<string>} $status */
        $status = $this->gateway()->get('/api/v1/internal/ai/status')->json('data');
        self::$status = [$status, time() + self::STATUS_TTL];

        return $status;
    }

    public static function forget(): void
    {
        self::$status = null;
    }

    private function gateway(): PendingRequest
    {
        return $this->client->service((string) config('abeon.ai.service', 'unified'));
    }
}
