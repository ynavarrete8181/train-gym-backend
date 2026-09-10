<?php

$normalizarOrigen = static function (string $origen): ?string {
    $origen = trim($origen);
    if ($origen === '') {
        return null;
    }

    if ($origen === '*') {
        return '*';
    }

    if (str_contains($origen, '://')) {
        $partes = parse_url($origen);
        $host = $partes['host'] ?? null;
        if (! $host) {
            return null;
        }

        $puerto = $partes['port'] ?? null;
        return $puerto ? $host.':'.$puerto : $host;
    }

    return rtrim($origen, '/');
};

$origenesPermitidos = env('APP_ENV', 'production') === 'local'
    ? ['*']
    : array_values(array_filter(array_map(
        $normalizarOrigen,
        explode(',', env('REVERB_ALLOWED_ORIGINS', '')),
    )));

return [
    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'hostname' => env('REVERB_HOST', '127.0.0.1'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', 6379),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', 0),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],
    ],

    'apps' => [
        'provider' => 'config',
        'apps' => [
            [
                'key' => env('REVERB_APP_KEY', 'revive-local-key'),
                'secret' => env('REVERB_APP_SECRET', 'revive-local-secret'),
                'app_id' => env('REVERB_APP_ID', 'revive-local-app'),
                'options' => [
                    'host' => env('REVERB_HOST', '127.0.0.1'),
                    'port' => env('REVERB_PORT', 8080),
                    'scheme' => env('REVERB_SCHEME', 'http'),
                    'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
                ],
                'allowed_origins' => $origenesPermitidos,
                'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
            ],
        ],
    ],
];
