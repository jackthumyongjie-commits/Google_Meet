<?php
/**
 * CLI smoke test for ConnectMeet APIs.
 * Usage: php tests/smoke_test.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

function pass(string $name): void
{
    echo "[PASS] $name\n";
}

function fail(string $name, string $reason): void
{
    echo "[FAIL] $name — $reason\n";
}

$failed = 0;

try {
    cm_db()->query('SELECT 1');
    pass('Database connection');
} catch (Throwable $e) {
    fail('Database connection', $e->getMessage());
    exit(1);
}

// Simulate CSRF session
$_SESSION[cm_config('security.csrf_token_key')] = cm_generate_token(16);
$csrf = cm_csrf_token();

// Create meeting directly via DB helpers / functions used by API
$code = cm_generate_meeting_code();
$hostToken = cm_generate_token(32);
$ttl = (int) cm_config('security.meeting_ttl_hours', 24);
$stmt = cm_db()->prepare(
    'INSERT INTO meetings (meeting_code, host_token_hash, title, status, expires_at, participant_limit)
     VALUES (?, ?, ?, \'active\', ?, 8)'
);
$stmt->execute([
    $code,
    cm_hash_token($hostToken),
    'Smoke Test Meeting',
    date('Y-m-d H:i:s', time() + $ttl * 3600),
]);
$meetingId = (int) cm_db()->lastInsertId();
pass('Meeting creation (unique code: ' . $code . ')');

$found = cm_find_meeting_by_code($code);
if (!$found) {
    fail('Meeting lookup', 'not found');
    $failed++;
} else {
    pass('Meeting lookup');
}

$bogus = cm_find_meeting_by_code('zzz-zzzz-zzz');
if ($bogus) {
    fail('Unknown code rejection', 'unexpected row');
    $failed++;
} else {
    pass('Unknown codes do not exist / are not auto-created');
}

if (!cm_is_valid_meeting_code('badcode')) {
    pass('Invalid meeting code format rejected');
} else {
    fail('Invalid meeting code format rejected', 'accepted badcode');
    $failed++;
}

// Join host
$pToken = cm_generate_token(32);
$ins = cm_db()->prepare(
    'INSERT INTO meeting_participants
     (meeting_id, participant_token_hash, display_name, role, audio_enabled, video_enabled)
     VALUES (?, ?, ?, ?, 1, 1)'
);
$ins->execute([$meetingId, cm_hash_token($pToken), 'Host User', 'host']);
$hostId = (int) cm_db()->lastInsertId();
pass('Host participant join');

// Guest join
$gToken = cm_generate_token(32);
$ins->execute([$meetingId, cm_hash_token($gToken), 'Guest <script>alert(1)</script>', 'participant']);
$guestId = (int) cm_db()->lastInsertId();
pass('Guest participant join with special characters in name');

// Chat message with HTML
$msg = cm_db()->prepare(
    'INSERT INTO meeting_messages (meeting_id, participant_id, message_type, message_content)
     VALUES (?, ?, \'text\', ?)'
);
$html = '<img src=x onerror=alert(1)> hello';
$msg->execute([$meetingId, $guestId, $html]);
$row = cm_db()->query('SELECT message_content FROM meeting_messages ORDER BY id DESC LIMIT 1')->fetch();
if ($row && $row['message_content'] === $html) {
    $escaped = cm_e($row['message_content']);
    if (str_contains($escaped, '<img') === false && str_contains($escaped, '&lt;img')) {
        pass('Chat HTML stored raw but escaped for display');
    } else {
        fail('Chat HTML escaping', $escaped);
        $failed++;
    }
} else {
    fail('Chat insert', 'mismatch');
    $failed++;
}

// Unauthorized host action simulation: guest should not be host
$guest = cm_get_participant_by_token($gToken);
if ($guest && $guest['role'] !== 'host') {
    pass('Guest is not host');
} else {
    fail('Guest is not host', 'role incorrect');
    $failed++;
}

if (!cm_verify_host_token($found, 'wrong-token')) {
    pass('Invalid host token rejected');
} else {
    fail('Invalid host token rejected', 'accepted');
    $failed++;
}

if (cm_verify_host_token($found, $hostToken)) {
    pass('Valid host token accepted');
} else {
    fail('Valid host token accepted', 'rejected');
    $failed++;
}

cm_cleanup_stale_participants($meetingId);
cm_cleanup_expired_meetings();
pass('Cleanup routines callable');

echo "\nDone. Failures: $failed\n";
exit($failed > 0 ? 1 : 0);
