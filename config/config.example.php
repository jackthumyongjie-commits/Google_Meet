<?php
/**
 * VeloraMeet configuration EXAMPLE (safe to commit).
 * Copy to config.php and fill real values locally / on the server.
 * NEVER commit config.php — it is gitignored for a reason.
 */

declare(strict_types=1);

return [
    'app_name' => 'VeloraMeet',
    'app_version' => '1.0.0',
    // Local:  http://localhost/project/Google_Meet
    // cPanel: https://your-domain.com   or  https://your-domain.com/velorameet
    'base_url' => 'http://localhost/project/Google_Meet',
    'timezone' => 'Asia/Kuala_Lumpur',

    'db' => [
        'host' => '127.0.0.1', // cPanel usually: localhost
        'port' => 3306,
        'name' => 'connectmeet', // cPanel full DB name, e.g. user_velorameet
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    'session' => [
        'name' => 'CONNECTMEETSESSID',
        'lifetime' => 86400,
        'secure' => false, // cPanel HTTPS: MUST be true
        'httponly' => true,
        'samesite' => 'Lax',
    ],

    'security' => [
        'csrf_token_key' => 'cm_csrf',
        'display_name_max' => 80,
        'chat_message_max' => 1000,
        'participant_limit_default' => 8,
        'participant_limit_max' => 12,
        'meeting_ttl_hours' => 24,
        'stale_participant_seconds' => 45,
        'rate_limit_window_seconds' => 60,
        'rate_limit_max_requests' => 120,
        'chat_rate_limit_max' => 30,
        'reaction_rate_limit_max' => 40,
        'allowed_origins' => [
            'http://localhost',
            'http://127.0.0.1',
            // 'https://your-domain.com',
        ],
    ],

    'webrtc' => [
        // Mesh prototype: keep rooms small.
        'ice_servers' => [
            ['urls' => 'stun:stun.l.google.com:19302'],
            ['urls' => 'stun:stun1.l.google.com:19302'],
            // Example TURN (configure for production / restrictive NATs):
            // [
            //     'urls' => 'turn:turn.example.com:3478',
            //     'username' => 'user',
            //     'credential' => 'pass',
            // ],
        ],
    ],

    'polling' => [
        'participants_ms' => 2000,
        'signaling_ms' => 800,
        'events_ms' => 1200,
        'chat_ms' => 1500,
        'heartbeat_ms' => 5000,
        'request_timeout_ms' => 10000,
    ],

    'logging' => [
        'enabled' => true,
        'path' => __DIR__ . '/../storage/logs/app.log',
    ],
];
