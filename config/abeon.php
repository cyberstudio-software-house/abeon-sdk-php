<?php

declare(strict_types=1);

return [
    'service' => [
        'name' => env('ABEON_SERVICE_NAME'),
    ],

    'auth' => [
        'url'      => env('ABEON_AUTH_URL', 'http://auth-service.abeon.svc.cluster.local'),
        'jwks_url' => env('ABEON_AUTH_JWKS_URL'),
        'issuer'   => env('ABEON_AUTH_ISSUER', 'abeon-auth'),
        'audience' => env('ABEON_AUTH_AUDIENCE', 'abeon'),
        'service_jwt' => [
            'private_key' => env('ABEON_SERVICE_JWT_PRIVATE_KEY'),
            'kid'         => env('ABEON_SERVICE_JWT_KID'),
        ],
    ],

    'events' => [
        'dsn'      => env('ABEON_RABBITMQ_DSN'),
        'exchange' => env('ABEON_RABBITMQ_EXCHANGE', 'abeon.events'),
    ],

    'health' => [
        'checks' => array_filter(array_map('trim', explode(',', (string) env('ABEON_HEALTH_CHECKS', 'db')))),
    ],

    'logging' => [
        'correlation_field' => env('ABEON_LOG_CORRELATION_FIELD', 'correlation_id'),
    ],

    // Services this consumer talks to. SDK does NOT know about specific services (M1).
    'services' => [
        // 'auth' => ['url' => 'http://auth-service.abeon.svc.cluster.local'],
    ],

    // Permissions DECLARED by this service (M5).
    // SDK publishes service.permissions.declared event on bootstrap.
    'permissions' => [
        // 'crm.contacts.read',
    ],

    // Self-registration metadata for ServiceRegistry (M4).
    'app_descriptor' => [
        'name'  => env('ABEON_SERVICE_NAME'),
        'label' => env('ABEON_APP_LABEL'),
        'path'  => env('ABEON_APP_PATH'),
        'icon'  => env('ABEON_APP_ICON'),
    ],
];
