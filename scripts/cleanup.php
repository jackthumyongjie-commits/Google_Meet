<?php

/**
 * Cleanup stale participants, expired meetings, old signaling and rate-limit rows.
 * Run via CLI or cron: php scripts/cleanup.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    // Allow manual browser trigger only from localhost.
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($ip, ['127.0.0.1', '::1'], true)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
}

$stale = cm_cleanup_stale_participants();
$expired = cm_cleanup_expired_meetings();

$pdo = cm_db();

$sig = $pdo->exec('DELETE FROM meeting_signaling WHERE created_at < (NOW() - INTERVAL 2 HOUR)');
$evt = $pdo->exec('DELETE FROM meeting_events WHERE created_at < (NOW() - INTERVAL 2 DAY)');
$rate = $pdo->exec('DELETE FROM rate_limits WHERE window_start < (NOW() - INTERVAL 1 DAY)');

$result = [
    'stale_participants' => $stale,
    'expired_meetings' => $expired,
    'deleted_signals' => (int) $sig,
    'deleted_events' => (int) $evt,
    'deleted_rate_limits' => (int) $rate,
    'ran_at' => date('c'),
];

if ($isCli) {
    echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
} else {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT);
}
