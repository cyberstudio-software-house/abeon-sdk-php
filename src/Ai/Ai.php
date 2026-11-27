<?php

declare(strict_types=1);

namespace Abeon\SDK\Ai;

use Abeon\SDK\Client\ServiceClient;
use Illuminate\Http\Client\PendingRequest;

/**
 * The AI gateway (ADR-0020), for an application: name a feature, send the messages, get the
 * answer. A completion is tried once: the gateway charges every call it forwards, so a retry after
 * a timeout could pay twice for one answer. The gateway picks the model the organisation chose for the
 * feature (or the platform default), rotates free models when the organisation allows it, and bills
 * the provider cost with the platform markup. The service that runs the gateway is configuration
 * (`abeon.ai.service`), so moving it out of AbeonUnified changes no caller.
 */
final class Ai
{
    public const STATUS_TTL = 60;

    /** @var array{0: array<string, mixed>, 1: int}|null */
    private static ?array $status = null;

    public function __construct(private readonly ServiceClient $client) {}

    /**
     * @param  list<array{role: string, content: string|list<array<string, mixed>>}>  $messages
     * @param  array{max_tokens?: int, temperature?: float, response_format?: array<string, mixed>, user_id?: string|null}  $options
     * @return array{content: string, finish_reason: string|null, model: string, free?: bool, attempts?: int, usage: array{input_tokens: int, output_tokens: int, cost_usd: float}}
     *
     * @throws \Abeon\SDK\Client\ServiceCallException  403 ai-disabled / ai-app-not-enabled, 402 ai-cap-reached, 409 ai-model-unavailable,
     *                                                    502 ai-provider-error, 503 ai-free-exhausted
     */
    public function complete(string $feature, array $messages, array $options = []): array
    {
        /** @var array{content: string, finish_reason: string|null, model: string, free?: bool, attempts?: int, usage: array{input_tokens: int, output_tokens: int, cost_usd: float}} $answer */
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
     * @return array{enabled: bool, app_enabled: bool, monthly_cap_usd: float, used_usd: float, month: string, features: list<string>, models?: array<string, array{model: string, chosen: bool}>, free_mode?: bool, free_fallback?: string}
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

    /**
     * Models from the catalogue that can do the feature, with prices per million tokens.
     *
     * @return list<array{id: string, name: string, prompt_usd_per_mtok: float, completion_usd_per_mtok: float, context_length: int, image_input: bool, structured_output: bool, free: bool}>
     */
    public function models(string $feature): array
    {
        /** @var list<array{id: string, name: string, prompt_usd_per_mtok: float, completion_usd_per_mtok: float, context_length: int, image_input: bool, structured_output: bool, free: bool}> */
        return $this->gateway()->get('/api/v1/ai/models', ['feature' => $feature])->json('data');
    }

    /**
     * The model per feature (chosen or the platform default), free mode and the markup.
     *
     * @return array{models: array<string, array{model: string, chosen: bool}>, free_mode: bool, free_fallback: string, free_consent_by: string|null, free_consent_at: string|null, markup: float}
     */
    public function settings(): array
    {
        /** @var array{models: array<string, array{model: string, chosen: bool}>, free_mode: bool, free_fallback: string, free_consent_by: string|null, free_consent_at: string|null, markup: float} */
        return $this->gateway()->get('/api/v1/ai/settings')->json('data');
    }

    /**
     * @param  array{models?: array<string, string|null>, free_mode?: bool, free_fallback?: 'paid'|'stop', consent_by?: string|null}  $changes
     * @return array{models: array<string, array{model: string, chosen: bool}>, free_mode: bool, free_fallback: string, free_consent_by: string|null, free_consent_at: string|null, markup: float}
     *
     * @throws \Abeon\SDK\Client\ServiceCallException  422 ai-model-unsupported / ai-free-consent-required
     */
    public function updateSettings(array $changes): array
    {
        /** @var array{models: array<string, array{model: string, chosen: bool}>, free_mode: bool, free_fallback: string, free_consent_by: string|null, free_consent_at: string|null, markup: float} $settings */
        $settings = $this->gateway()->put('/api/v1/ai/settings', $changes)->json('data');
        self::$status = null;

        return $settings;
    }

    /**
     * The month as billed: totals and splits by application, feature, model and day, and the latest
     * calls without content. `organisation` covers every application of the organisation.
     *
     * @param  'app'|'organisation'  $scope
     * @return array<string, mixed>
     */
    public function usage(?string $month = null, string $scope = 'app'): array
    {
        /** @var array<string, mixed> */
        return $this->gateway()->get('/api/v1/ai/usage', array_filter(['month' => $month, 'scope' => $scope]))->json('data');
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
