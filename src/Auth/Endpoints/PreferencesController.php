<?php

declare(strict_types=1);

namespace Abeon\SDK\Auth\Endpoints;

use Abeon\SDK\Auth\AuthContext;
use Abeon\SDK\Exceptions\AuthException;
use Abeon\SDK\Http\ApiResponse;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base implementation of `/api/v1/auth/me/preferences` per ADR-0009.
 *
 * Stored in `user_preferences.preferences` as a JSON blob keyed by `user_id`.
 * The blob is a versioned object whose top-level keys are namespaces — `chrome`
 * is the namespace owned by the federated chrome.
 *
 * `GET`  → returns the blob (with defaults synthesised if no row exists).
 * `PATCH` → deep-merges top-level keys with the incoming body and persists.
 *
 * Auth service owns this endpoint canonically. Other services do NOT read the
 * `user_preferences` table directly — they go through the REST API or call
 * `ServiceClient::service('auth')->get('/api/v1/auth/me/preferences')`.
 *
 * Requires `abeon.auth` middleware.
 */
class PreferencesController
{
    /**
     * Hard cap to prevent runaway blobs. ADR-0009 §Consequences.
     */
    private const MAX_BLOB_BYTES = 65_536;

    public const TABLE = 'user_preferences';

    public function __construct(
        private readonly AuthContext $authContext,
        private readonly ConnectionInterface $db,
    ) {
    }

    public function show(): JsonResponse
    {
        $user = $this->authContext->user();
        if ($user === null) {
            throw AuthException::unauthenticated();
        }

        return ApiResponse::data($this->read((int) $user->id));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $this->authContext->user();
        if ($user === null) {
            throw AuthException::unauthenticated();
        }

        $incoming = $request->all();
        if (! is_array($incoming)) {
            $incoming = [];
        }

        $current = $this->read((int) $user->id);
        $merged  = $this->mergeTopLevel($current, $incoming);

        $encoded = json_encode($merged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > self::MAX_BLOB_BYTES) {
            throw AuthException::forbidden('Preferences blob exceeds maximum size');
        }

        $this->write((int) $user->id, $encoded);

        return ApiResponse::data($merged);
    }

    /**
     * @return array<string, mixed>
     */
    public function read(int $userId): array
    {
        $row = $this->db->table(self::TABLE)
            ->where('user_id', $userId)
            ->first();

        if ($row === null) {
            return self::defaults();
        }

        $raw     = is_object($row) ? ($row->preferences ?? null) : ($row['preferences'] ?? null);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($decoded)) {
            return self::defaults();
        }

        return $decoded;
    }

    private function write(int $userId, string $json): void
    {
        $now = (string) (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $existing = $this->db->table(self::TABLE)->where('user_id', $userId)->exists();

        if ($existing) {
            $this->db->table(self::TABLE)->where('user_id', $userId)->update([
                'preferences' => $json,
                'updated_at'  => $now,
            ]);
        } else {
            $this->db->table(self::TABLE)->insert([
                'user_id'     => $userId,
                'preferences' => $json,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'version' => 1,
            'chrome'  => [
                'appOrder'         => [],
                'pinned'           => [],
                'theme'            => 'system',
                'sidebarCollapsed' => false,
                'recents'          => [],
            ],
        ];
    }

    /**
     * Deep-merge two top-level keys only — `chrome.*` is replaced wholesale per
     * key, not merged recursively, to keep behaviour predictable for clients.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeTopLevel(array $base, array $incoming): array
    {
        foreach ($incoming as $namespace => $value) {
            if (! is_string($namespace)) {
                continue;
            }
            if ($namespace === 'version') {
                continue; // version is owned by the server
            }
            if (is_array($value) && isset($base[$namespace]) && is_array($base[$namespace])) {
                $base[$namespace] = array_replace($base[$namespace], $value);
            } else {
                $base[$namespace] = $value;
            }
        }

        $base['version'] = 1;

        return $base;
    }
}
