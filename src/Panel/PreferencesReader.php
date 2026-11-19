<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the user's preferences from Auth during render so the first paint is already
 * themed, and never costs the page (ADR-0009, ADR-0025 §4);
 * see docs/notes/inertia-shared-props.md.
 */
final class PreferencesReader
{
    private const TIMEOUT_SECONDS = 0.5;

    /**
     * @return array<string, mixed>|null
     */
    public function read(?string $accessToken): ?array
    {
        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        $url = rtrim((string) config('abeon.auth.url'), '/').'/api/v1/auth/me/preferences';

        try {
            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->get($url);
        } catch (\Throwable $e) {
            Log::info('Preferences were not available for the first render', ['exception' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $document = $response->json('data');

        return is_array($document) && ($document['version'] ?? null) === 1 ? $document : null;
    }
}
