<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

cm_apply_cors();

$checks = [
    'app' => cm_config('app_name'),
    'version' => cm_config('app_version'),
    'php' => PHP_VERSION,
    'time' => date('c'),
];

try {
    $pdo = cm_db();
    $pdo->query('SELECT 1');
    $checks['database'] = 'ok';
} catch (Throwable $e) {
    cm_json_error('Database unavailable.', 503, [
        'checks' => array_merge($checks, ['database' => 'error']),
    ]);
}

$logPath = (string) cm_config('logging.path', dirname(__DIR__) . '/storage/logs/app.log');
$logDir = dirname($logPath);
$checks['storage_writable'] = is_dir($logDir) && is_writable($logDir) ? 'ok' : 'warn';
$checks['base_url'] = cm_config('base_url');
$checks['https_hint'] = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
    ? 'ok'
    : 'use HTTPS on cPanel for camera/mic';

cm_json_success($checks, 'Healthy');
