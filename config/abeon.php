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
        // Clock-skew tolerance for exp/nbf, in seconds (ADR-0001 validation rule 5).
        // Zero tolerance means a validator one second ahead of the issuer rejects a
        // freshly minted token — intermittently, and only once services land on
        // different nodes.
        // Longest lifetime a token may claim before this service refuses to believe it,
        // regardless of who signed it (ADR-0005 bounds a key compromise by the token
        // lifetime; that only holds if something enforces a lifetime).
        'max_token_lifetime' => (int) env('ABEON_MAX_TOKEN_LIFETIME', 86400),

        'leeway' => (int) env('ABEON_AUTH_LEEWAY', 60),
        // How long this service caches the JWKS document, in seconds. This is what
        // actually bounds key rotation: Auth must keep a retired kid published for at
        // least this long plus one token lifetime (ADR-0005 as amended by ADR-0025).
        'jwks_cache_ttl' => (int) env('ABEON_JWKS_CACHE_TTL', 3600),
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

    'http' => [
        /*
         | Which proxies this service believes about `X-Forwarded-*`.
         |
         | `*` is right when the only route to the pod is through the ingress, which is
         | the usual arrangement here. It is wrong the moment anything can reach the pod
         | directly: a client then sets its own `X-Forwarded-For` and picks its own
         | address, and Auth throttles failed logins per `(email, ip)` — a spoofable
         | address is a spoofable counter. Pin this to the ingress CIDR wherever the
         | network does not already guarantee it. Comma-separated.
         */
        'trusted_proxies' => env('ABEON_TRUSTED_PROXIES', '*'),
    ],

    'logging' => [
        'correlation_field' => env('ABEON_LOG_CORRELATION_FIELD', 'correlation_id'),
    ],

    // CORS allow-list for cross-origin chrome requests (e.g. Inertia / Next.js
    // running on app.abeon.pl calling internal service APIs). CSV in env.
    // Use `*` (development only) to allow any origin; production should list
    // exact origins because credentials cookies are involved.
    'cors' => [
        'allowed_origins' => array_filter(array_map(
            'trim',
            explode(',', (string) env('ABEON_CORS_ALLOWED_ORIGINS', '')),
        )),
    ],

    // Services this consumer talks to. SDK does NOT know about specific services (M1),
    // with two platform-tier exceptions it resolves by name:
    //   'auth'    — identity: users, memberships, roles, permissions
    //   'unified' — AbeonUnified (ADR-0019): notifications, app registry,
    //               organisation↔app assignment, AI gateway (ADR-0020), storage
    //               credentials (ADR-0021)
    'services' => [
        'auth'    => ['url' => env('ABEON_AUTH_URL')],
        'unified' => ['url' => env('ABEON_UNIFIED_URL')],
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
