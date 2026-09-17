<?php

declare(strict_types=1);

namespace Abeon\SDK\Notifications;

use InvalidArgumentException;

/**
 * One notification for one user, as `{service}.notification.requested` carries it
 * (schemas/events/notification-requested.json, ADR-0028).
 */
final readonly class NotificationRequest
{
    public const TITLE_MAX = 200;

    public const BODY_MAX = 2000;

    /** @var list<NotificationChannel> */
    public array $channels;

    /**
     * @param  array<array-key, mixed>  $metadata
     * @param  list<NotificationChannel>  $channels
     */
    public function __construct(
        public int $userId,
        public string $type,
        public string $title,
        public string $body,
        public ?string $icon = null,
        public ?string $actionUrl = null,
        public array $metadata = [],
        array $channels = [NotificationChannel::InApp],
    ) {
        if ($userId < 1) {
            throw new InvalidArgumentException('userId must be a positive integer');
        }

        if ($type === '') {
            throw new InvalidArgumentException('type must not be empty');
        }

        if (mb_strlen($title) > self::TITLE_MAX) {
            throw new InvalidArgumentException('title is longer than '.self::TITLE_MAX.' characters');
        }

        if (mb_strlen($body) > self::BODY_MAX) {
            throw new InvalidArgumentException('body is longer than '.self::BODY_MAX.' characters');
        }

        if (array_is_list($metadata) && $metadata !== []) {
            throw new InvalidArgumentException('metadata must be an object, not a list');
        }

        if (! in_array(NotificationChannel::InApp, $channels, true)) {
            throw new InvalidArgumentException('channels must include in_app: the feed is the record (ADR-0028)');
        }

        $unique = [];
        foreach ($channels as $channel) {
            $unique[$channel->value] = $channel;
        }

        $this->channels = array_values($unique);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(string $sourceApp): array
    {
        return [
            'target'     => ['user_id' => $this->userId],
            'type'       => $this->type,
            'title'      => $this->title,
            'body'       => $this->body,
            'icon'       => $this->icon,
            'action_url' => $this->actionUrl,
            'source_app' => $sourceApp,
            'metadata'   => (object) $this->metadata,
            'channels'   => array_map(fn (NotificationChannel $c): string => $c->value, $this->channels),
        ];
    }
}
