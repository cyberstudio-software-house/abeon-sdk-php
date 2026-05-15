<?php

declare(strict_types=1);

return [
    'service' => [
        'name' => env('ABEON_SERVICE_NAME'),
    ],

    'client' => [
        'timeout'        => (float) env('ABEON_CLIENT_TIMEOUT', 10.0),
        'connect_timeout'=> (float) env('ABEON_CLIENT_CONNECT_TIMEOUT', 3.0),
        'max_retries'    => (int)   env('ABEON_CLIENT_MAX_RETRIES', 2),
        'retry_delay_ms' => (int)   env('ABEON_CLIENT_RETRY_DELAY_MS', 500),
    ],

    'auth' => [
        'url'      => env('ABEON_AUTH_URL', 'http://auth-service.abeon.svc.cluster.local'),
        'jwks_url' => env('ABEON_AUTH_JWKS_URL'),
        'issuer'   => env('ABEON_AUTH_ISSUER', 'abeon-auth'),
        'audience' => env('ABEON_AUTH_AUDIENCE', 'abeon'),
        // Canonical cookie names — Auth service issues them, frontends (Inertia + Next.js
        // via @abeon/shared) read them. Frontends MUST swap from cookie → Authorization
        // Bearer header before calling downstream services, since AuthMiddleware only
        // reads the header (not cookies).
        'cookies' => [
            'access'  => env('ABEON_JWT_COOKIE_NAME', 'abeon_token'),
            'refresh' => env('ABEON_REFRESH_COOKIE_NAME', 'abeon_refresh'),
        ],
        'service_jwt' => [
            'private_key' => env('ABEON_SERVICE_JWT_PRIVATE_KEY'),
            'kid'         => env('ABEON_SERVICE_JWT_KID'),
        ],
    ],

    'events' => [
        'dsn'                  => env('ABEON_RABBITMQ_DSN'),
        'exchange'             => env('ABEON_RABBITMQ_EXCHANGE', 'abeon.events'),
        'dlx_exchange'         => env('ABEON_RABBITMQ_DLX', 'abeon.events.dlx'),
        'connection_timeout'   => (float) env('ABEON_RABBITMQ_CONNECTION_TIMEOUT', 3.0),
        'read_write_timeout'   => (float) env('ABEON_RABBITMQ_RW_TIMEOUT', 3.0),
        'health_probe_timeout' => (float) env('ABEON_RABBITMQ_HEALTH_TIMEOUT', 2.0),
        'outbox' => [
            'connection'    => env('ABEON_OUTBOX_CONNECTION'), // default DB connection if null
            'batch_size'    => (int) env('ABEON_OUTBOX_BATCH_SIZE', 100),
            'poll_interval' => (int) env('ABEON_OUTBOX_POLL_INTERVAL', 1),
            'max_attempts'  => (int) env('ABEON_OUTBOX_MAX_ATTEMPTS', 5),
            'lag_threshold' => (int) env('ABEON_OUTBOX_LAG_THRESHOLD', 60),
        ],
        'consumer' => [
            'queue_prefix' => env('ABEON_QUEUE_PREFIX'), // defaults to service.name
            // Routing keys this service subscribes to. Each entry becomes a queue
            // {queue_prefix}.{routing_key} bound to the main exchange.
            'subscriptions' => [
                // 'crm.contact.created',
            ],
            'prefetch_count' => (int) env('ABEON_CONSUMER_PREFETCH', 10),
        ],
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
