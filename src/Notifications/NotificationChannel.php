<?php

declare(strict_types=1);

namespace Abeon\SDK\Notifications;

enum NotificationChannel: string
{
    case InApp = 'in_app';
    case Email = 'email';
}
