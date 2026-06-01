<?php

return [
    'database' => [
        'connection' => (string) env('INVENTORY_DB_CONNECTION', 'inventory'),
        'source_connection' => (string) env('INVENTORY_SOURCE_DB_CONNECTION', env('DB_CONNECTION', 'mysql')),
    ],

    'default_password' => (string) env('INVENTORY_DEFAULT_PASSWORD', '123456789'),
    'sms' => [
        'enabled' => (bool) env('INVENTORY_SMS_ENABLED', true),
        'gateway' => (string) env('INVENTORY_SMS_GATEWAY', 'advanta'),
    ],
    'router_api' => [
        'enabled' => (bool) env('INVENTORY_ROUTER_API_ENABLED', true),
        'base_url' => (string) env('INVENTORY_ROUTER_API_BASE_URL', 'https://api.skybrix.co.ke/v1'),
        'username' => (string) env('INVENTORY_ROUTER_API_USERNAME', env('PETTY_ONT_USERNAME', '')),
        'password' => (string) env('INVENTORY_ROUTER_API_PASSWORD', env('PETTY_ONT_PASSWORD', '')),
        'timeout_seconds' => (int) env('INVENTORY_ROUTER_API_TIMEOUT_SECONDS', 12),
        'default_page_size' => (int) env('INVENTORY_ROUTER_API_PAGE_SIZE', 20),
        'max_page_size' => (int) env('INVENTORY_ROUTER_API_MAX_PAGE_SIZE', 100),
        'collection_page_limit' => (int) env('INVENTORY_ROUTER_API_COLLECTION_PAGE_LIMIT', 0),
    ],
    'api' => [
        'version' => (string) env('INVENTORY_API_VERSION', 'v1'),

        // 0 or negative means token does not expire automatically
        'token_ttl_days' => (int) env('INVENTORY_API_TOKEN_TTL_DAYS', 30),
        // 0 disables idle timeout expiration
        'token_idle_ttl_minutes' => (int) env('INVENTORY_API_TOKEN_IDLE_TTL_MINUTES', 0),
        // Extend token expiry window on each authenticated request
        'token_refresh_on_use' => (bool) env('INVENTORY_API_TOKEN_REFRESH_ON_USE', false),
        // Max active tokens kept per user (oldest are revoked/deleted)
        'max_active_tokens_per_user' => (int) env('INVENTORY_API_MAX_ACTIVE_TOKENS_PER_USER', 10),

        // Per-minute request limits
        'rate_limit_per_minute' => (int) env('INVENTORY_API_RATE_LIMIT_PER_MINUTE', 120),
        'login_rate_limit_per_minute' => (int) env('INVENTORY_API_LOGIN_RATE_LIMIT_PER_MINUTE', 20),

        // Optional deprecation metadata for clients
        'deprecated' => (bool) env('INVENTORY_API_DEPRECATED', false),
        // Example: "Wed, 31 Dec 2026 23:59:59 GMT"
        'sunset_at' => env('INVENTORY_API_SUNSET_AT'),
    ],
];
