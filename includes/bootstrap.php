<?php

declare(strict_types=1);

require_once __DIR__ . '/response.php';
require_once __DIR__ . '/functions.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/auth.php';

$config = cm_config();
date_default_timezone_set($config['timezone'] ?? 'UTC');

$session = $config['session'] ?? [];
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name($session['name'] ?? 'CONNECTMEETSESSID');
    session_set_cookie_params([
        'lifetime' => (int) ($session['lifetime'] ?? 86400),
        'path' => '/',
        'secure' => (bool) ($session['secure'] ?? false),
        'httponly' => (bool) ($session['httponly'] ?? true),
        'samesite' => $session['samesite'] ?? 'Lax',
    ]);
    session_start();
}

$logDir = dirname((string) cm_config('logging.path', dirname(__DIR__) . '/storage/logs/app.log'));
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

$storageDir = dirname(__DIR__) . '/storage';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0755, true);
}
