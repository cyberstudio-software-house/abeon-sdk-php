<?php

declare(strict_types=1);

namespace Abeon\SDK\Messaging;

use InvalidArgumentException;

/**
 * One transactional message: an invitation, a password reset, an address verification
 * (ADR-0030). Not a notification — preferences do not apply and the recipient may have
 * no account.
 */
final readonly class MessageRequest
{
    public const LOCALES = ['pl', 'en'];

    private const TEMPLATE_PATTERN = '/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/';

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(
        public string $template,
        public string $email,
        public string $idempotencyKey,
        public ?string $name = null,
        public ?int $userId = null,
        public string $locale = 'pl',
        public array $data = [],
    ) {
        if (preg_match(self::TEMPLATE_PATTERN, $template) !== 1) {
            throw new InvalidArgumentException("template must look like service.name, got {$template}");
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('email is not an address');
        }

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('idempotencyKey must not be empty: a redelivered event would send a second message');
        }

        if (! in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException("locale must be one of ".implode(', ', self::LOCALES));
        }

        if ($userId !== null && $userId < 1) {
            throw new InvalidArgumentException('userId must be a positive integer or null');
        }

        if (array_is_list($data) && $data !== []) {
            throw new InvalidArgumentException('data must be an object, not a list');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'template' => $this->template,
            'to'       => [
                'email'   => $this->email,
                'name'    => $this->name,
                'user_id' => $this->userId,
            ],
            'locale'          => $this->locale,
            'data'            => (object) $this->data,
            'idempotency_key' => $this->idempotencyKey,
        ];
    }
}
