<?php

declare(strict_types=1);

function cm_detect_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $dir = dirname($script);
    if (str_ends_with($dir, '/api')) {
        $dir = dirname($dir);
    }
    if ($dir === '/' || $dir === '.' || $dir === '\\') {
        $dir = '';
    }
    return rtrim($scheme . '://' . $host . $dir, '/');
}

function cm_config(?string $key = null, $default = null)
{
    static $config = null;

    if ($config === null) {
        $path = dirname(__DIR__) . '/config/config.php';
        if (!is_file($path)) {
            throw new RuntimeException('Missing config/config.php. Copy config.example.php first.');
        }
        $config = require $path;
        $base = trim((string) ($config['base_url'] ?? ''));
        if ($base === '' || strtolower($base) === 'auto') {
            $config['base_url'] = PHP_SAPI === 'cli' ? '' : cm_detect_base_url();
        }
    }

    if ($key === null) {
        return $config;
    }

    $parts = explode('.', $key);
    $value = $config;
    foreach ($parts as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }

    return $value;
}

function cm_log(string $message, array $context = []): void
{
    if (!cm_config('logging.enabled', false)) {
        return;
    }

    $path = (string) cm_config('logging.path');
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    // Never log secrets.
    unset($context['token'], $context['password'], $context['host_token'], $context['participant_token']);

    $line = sprintf(
        "[%s] %s %s\n",
        date('c'),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_UNICODE) : ''
    );
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
}

function cm_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function cm_request_method(): string
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function cm_read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    if (strlen($raw) > 65536) {
        cm_json_error('Request payload too large.', 413);
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        cm_json_error('Invalid JSON body.', 400);
    }

    return $data;
}

function cm_input(): array
{
    $json = cm_read_json_body();
    return array_merge($_GET, $_POST, $json);
}

function cm_str(array $input, string $key, string $default = ''): string
{
    $value = $input[$key] ?? $default;
    if (!is_scalar($value)) {
        return $default;
    }
    return trim((string) $value);
}

function cm_bool(array $input, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $input)) {
        return $default;
    }
    $value = $input[$key];
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int) $value === 1;
    }
    return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
}

function cm_int(array $input, string $key, int $default = 0): int
{
    if (!isset($input[$key]) || !is_numeric($input[$key])) {
        return $default;
    }
    return (int) $input[$key];
}

function cm_generate_token(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function cm_hash_token(string $token): string
{
    return hash('sha256', $token);
}

/**
 * Human-readable meeting code: xxx-xxxx-xxx
 */
function cm_generate_meeting_code(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $parts = [];
    foreach ([3, 4, 3] as $len) {
        $chunk = '';
        for ($i = 0; $i < $len; $i++) {
            $chunk .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $parts[] = $chunk;
    }
    return implode('-', $parts);
}

function cm_normalize_meeting_code(string $code): string
{
    $code = strtolower(trim($code));
    $code = preg_replace('/\s+/', '', $code) ?? '';
    $code = str_replace(['_', '.'], '-', $code);
    return $code;
}

function cm_is_valid_meeting_code(string $code): bool
{
    return (bool) preg_match('/^[a-z0-9]{3}-[a-z0-9]{4}-[a-z0-9]{3}$/', $code);
}

function cm_sanitize_display_name(string $name): string
{
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
    $max = (int) cm_config('security.display_name_max', 80);
    if (mb_strlen($name) > $max) {
        $name = mb_substr($name, 0, $max);
    }
    return $name;
}

function cm_e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cm_meeting_url(string $meetingCode): string
{
    $base = rtrim((string) cm_config('base_url'), '/');
    return $base . '/room.php?meeting=' . rawurlencode($meetingCode);
}

function cm_apply_cors(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowed = cm_config('security.allowed_origins', []);
    if (!is_array($allowed)) {
        $allowed = [];
    }

    // Always allow the configured app origin (helps cPanel deploys that forget allowed_origins).
    $base = rtrim((string) cm_config('base_url'), '/');
    if ($base !== '') {
        $parts = parse_url($base);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $appOrigin = $parts['scheme'] . '://' . $parts['host'];
            if (!empty($parts['port'])) {
                $appOrigin .= ':' . $parts['port'];
            }
            $allowed[] = $appOrigin;
        }
    }

    if ($origin !== '') {
        foreach ($allowed as $allowedOrigin) {
            $allowedOrigin = rtrim((string) $allowedOrigin, '/');
            if ($origin === $allowedOrigin || str_starts_with($origin, $allowedOrigin)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Vary: Origin');
                header('Access-Control-Allow-Credentials: true');
                header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Participant-Token, X-Requested-With');
                header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
                break;
            }
        }
    }

    if (cm_request_method() === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function cm_rate_limit(string $bucket, int $max, ?int $windowSeconds = null): void
{
    $windowSeconds = $windowSeconds ?? (int) cm_config('security.rate_limit_window_seconds', 60);
    $key = $bucket . ':' . cm_client_ip();

    try {
        $pdo = cm_db();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, hit_count, window_start FROM rate_limits WHERE rate_key = ? FOR UPDATE');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $now = new DateTimeImmutable('now');

        if (!$row) {
            $ins = $pdo->prepare('INSERT INTO rate_limits (rate_key, hit_count, window_start) VALUES (?, 1, ?)');
            $ins->execute([$key, $now->format('Y-m-d H:i:s')]);
            $pdo->commit();
            return;
        }

        $windowStart = new DateTimeImmutable($row['window_start']);
        if ($now->getTimestamp() - $windowStart->getTimestamp() >= $windowSeconds) {
            $upd = $pdo->prepare('UPDATE rate_limits SET hit_count = 1, window_start = ? WHERE id = ?');
            $upd->execute([$now->format('Y-m-d H:i:s'), $row['id']]);
            $pdo->commit();
            return;
        }

        if ((int) $row['hit_count'] >= $max) {
            $pdo->commit();
            cm_json_error('Too many requests. Please slow down.', 429);
        }

        $upd = $pdo->prepare('UPDATE rate_limits SET hit_count = hit_count + 1 WHERE id = ?');
        $upd->execute([$row['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cm_log('rate_limit_error', ['error' => $e->getMessage()]);
    }
}

function cm_publish_event(int $meetingId, ?int $participantId, string $type, array $payload = []): void
{
    $stmt = cm_db()->prepare(
        'INSERT INTO meeting_events (meeting_id, participant_id, event_type, event_payload)
         VALUES (?, ?, ?, ?)'
    );
    $stmt->execute([
        $meetingId,
        $participantId,
        $type,
        $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
    ]);
}

function cm_find_meeting_by_code(string $code): ?array
{
    $stmt = cm_db()->prepare('SELECT * FROM meetings WHERE meeting_code = ? LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function cm_meeting_is_joinable(array $meeting): ?string
{
    if (in_array($meeting['status'], ['ended', 'expired'], true)) {
        return 'This meeting has ended.';
    }

    if (!empty($meeting['expires_at'])) {
        $expires = strtotime($meeting['expires_at']);
        if ($expires !== false && $expires < time()) {
            $upd = cm_db()->prepare("UPDATE meetings SET status = 'expired' WHERE id = ?");
            $upd->execute([(int) $meeting['id']]);
            return 'This meeting has expired.';
        }
    }

    if ((int) $meeting['is_locked'] === 1) {
        return 'This meeting is locked by the host.';
    }

    return null;
}

function cm_count_active_participants(int $meetingId): int
{
    $stmt = cm_db()->prepare(
        'SELECT COUNT(*) FROM meeting_participants
         WHERE meeting_id = ? AND left_at IS NULL AND approval_status = \'approved\''
    );
    $stmt->execute([$meetingId]);
    return (int) $stmt->fetchColumn();
}

function cm_get_participant_by_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $hash = cm_hash_token($token);
    $stmt = cm_db()->prepare(
        'SELECT p.*, m.meeting_code, m.status AS meeting_status, m.is_locked, m.host_token_hash
         FROM meeting_participants p
         INNER JOIN meetings m ON m.id = p.meeting_id
         WHERE p.participant_token_hash = ? AND p.left_at IS NULL
         LIMIT 1'
    );
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function cm_require_participant(?string $token = null): array
{
    $token = $token ?? ($_SERVER['HTTP_X_PARTICIPANT_TOKEN'] ?? cm_str(cm_input(), 'participant_token'));
    $participant = cm_get_participant_by_token($token);
    if (!$participant) {
        cm_json_error('Unauthorized participant.', 401);
    }
    return $participant;
}

function cm_touch_participant(int $participantId): void
{
    $stmt = cm_db()->prepare('UPDATE meeting_participants SET last_seen_at = NOW() WHERE id = ? AND left_at IS NULL');
    $stmt->execute([$participantId]);
}

function cm_mark_participant_left(int $participantId, int $meetingId, bool $publish = true): void
{
    $stmt = cm_db()->prepare(
        'UPDATE meeting_participants
         SET left_at = NOW(), connection_state = \'disconnected\', hand_raised = 0, is_sharing_screen = 0
         WHERE id = ? AND left_at IS NULL'
    );
    $stmt->execute([$participantId]);

    if ($publish) {
        cm_publish_event($meetingId, $participantId, 'participant_left', [
            'participant_id' => $participantId,
        ]);
    }
}

function cm_cleanup_stale_participants(?int $meetingId = null): int
{
    $seconds = (int) cm_config('security.stale_participant_seconds', 45);
    $pdo = cm_db();

    $sql = 'SELECT id, meeting_id FROM meeting_participants
            WHERE left_at IS NULL AND last_seen_at < (NOW() - INTERVAL ' . (int) $seconds . ' SECOND)';
    $params = [];
    if ($meetingId !== null) {
        $sql .= ' AND meeting_id = ?';
        $params[] = $meetingId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $count = 0;

    foreach ($rows as $row) {
        cm_mark_participant_left((int) $row['id'], (int) $row['meeting_id'], true);
        $count++;
    }

    return $count;
}

function cm_cleanup_expired_meetings(): int
{
    $pdo = cm_db();
    $stmt = $pdo->prepare(
        "UPDATE meetings
         SET status = 'expired'
         WHERE status IN ('active', 'scheduled')
           AND expires_at IS NOT NULL
           AND expires_at < NOW()"
    );
    $stmt->execute();
    return $stmt->rowCount();
}

function cm_public_participant(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'display_name' => $row['display_name'],
        'role' => $row['role'],
        'audio_enabled' => (bool) $row['audio_enabled'],
        'video_enabled' => (bool) $row['video_enabled'],
        'hand_raised' => (bool) $row['hand_raised'],
        'is_sharing_screen' => (bool) ($row['is_sharing_screen'] ?? false),
        'connection_state' => $row['connection_state'] ?? 'connected',
        'joined_at' => $row['joined_at'] ?? null,
    ];
}

function cm_public_meeting(array $meeting, ?array $extra = null): array
{
    $data = [
        'id' => (int) $meeting['id'],
        'meeting_code' => $meeting['meeting_code'],
        'title' => $meeting['title'],
        'status' => $meeting['status'],
        'scheduled_at' => $meeting['scheduled_at'],
        'expires_at' => $meeting['expires_at'],
        'is_locked' => (bool) $meeting['is_locked'],
        'waiting_room_enabled' => (bool) ($meeting['waiting_room_enabled'] ?? false),
        'participant_limit' => (int) $meeting['participant_limit'],
        'join_url' => cm_meeting_url($meeting['meeting_code']),
        'participant_count' => cm_count_active_participants((int) $meeting['id']),
        'created_at' => $meeting['created_at'],
    ];

    if ($extra) {
        $data = array_merge($data, $extra);
    }

    return $data;
}
