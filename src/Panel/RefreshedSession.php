<?php

declare(strict_types=1);

namespace Abeon\SDK\Panel;

/**
 * The result of a successful token refresh: access token, Auth's response body, and
 * Auth's own `Set-Cookie` headers verbatim; see docs/notes/token-refresh.md.
 */
final readonly class RefreshedSession
{
    /**
     * @param  list<string>  $setCookies
     * @param  string  $body  Auth's response, so a caller relaying `/auth/refresh` can
     *         answer with exactly what Auth said rather than an approximation of it.
     */
    public function __construct(
        public string $accessToken,
        public array $setCookies,
        public string $body = '',
    ) {
    }

    /**
     * @return array{access_token: string, set_cookies: list<string>, body: string}
     */
    public function toArray(): array
    {
        return [
            'access_token' => $this->accessToken,
            'set_cookies'  => $this->setCookies,
            'body'         => $this->body,
        ];
    }

    /**
     * The same session, minus the browser's copy of the rotated refresh token (ADR-0023 §2);
     * see docs/notes/token-refresh.md.
     */
    public function withoutCookies(): self
    {
        return new self($this->accessToken, [], $this->body);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): ?self
    {
        $token = $payload['access_token'] ?? null;
        $cookies = $payload['set_cookies'] ?? null;

        if (! is_string($token) || $token === '' || ! is_array($cookies)) {
            return null;
        }

        return new self(
            $token,
            array_values(array_filter($cookies, 'is_string')),
            is_string($payload['body'] ?? null) ? $payload['body'] : '',
        );
    }
}
