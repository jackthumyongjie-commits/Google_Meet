<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

cm_apply_cors();
cm_rate_limit('signaling', (int) cm_config('security.rate_limit_max_requests', 120));

$input = cm_input();
$action = cm_str($input, 'action', 'poll');

try {
    switch ($action) {
        case 'send':
            handle_send_signal($input);
            break;
        case 'poll':
            handle_poll_signals($input);
            break;
        default:
            cm_json_error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    cm_log('signaling_api_error', ['error' => $e->getMessage()]);
    cm_json_error('Server error while processing signaling.', 500);
}

function handle_send_signal(array $input): void
{
    cm_require_csrf();
    $participant = cm_require_participant();
    cm_touch_participant((int) $participant['id']);

    $toId = cm_int($input, 'to_participant_id');
    $type = cm_str($input, 'signal_type');
    $payload = $input['payload'] ?? null;

    if (!in_array($type, ['offer', 'answer', 'ice'], true)) {
        cm_json_error('Invalid signal_type.', 422);
    }
    if ($toId <= 0 || $toId === (int) $participant['id']) {
        cm_json_error('Invalid to_participant_id.', 422);
    }

    // Verify target is in same meeting and active.
    $stmt = cm_db()->prepare(
        'SELECT id FROM meeting_participants
         WHERE id = ? AND meeting_id = ? AND left_at IS NULL LIMIT 1'
    );
    $stmt->execute([$toId, (int) $participant['meeting_id']]);
    if (!$stmt->fetch()) {
        cm_json_error('Target participant is not in this meeting.', 404);
    }

    if ($payload === null) {
        cm_json_error('payload is required.', 422);
    }

    $encoded = is_string($payload) ? $payload : json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($encoded === false || strlen($encoded) > 60000) {
        cm_json_error('Signal payload too large.', 413);
    }

    $ins = cm_db()->prepare(
        'INSERT INTO meeting_signaling
         (meeting_id, from_participant_id, to_participant_id, signal_type, payload)
         VALUES (?, ?, ?, ?, ?)'
    );
    $ins->execute([
        (int) $participant['meeting_id'],
        (int) $participant['id'],
        $toId,
        $type,
        $encoded,
    ]);

    cm_json_success([
        'signal_id' => (int) cm_db()->lastInsertId(),
    ], 'Signal queued', 201);
}

function handle_poll_signals(array $input): void
{
    $participant = cm_require_participant();
    cm_touch_participant((int) $participant['id']);

    $afterId = cm_int($input, 'after_id', 0);
    $pdo = cm_db();

    $stmt = $pdo->prepare(
        'SELECT id, from_participant_id, to_participant_id, signal_type, payload, created_at
         FROM meeting_signaling
         WHERE to_participant_id = ?
           AND consumed = 0
           AND id > ?
         ORDER BY id ASC
         LIMIT 50'
    );
    $stmt->execute([(int) $participant['id'], $afterId]);
    $rows = $stmt->fetchAll();

    $ids = [];
    $signals = [];
    foreach ($rows as $row) {
        $ids[] = (int) $row['id'];
        $decoded = json_decode($row['payload'], true);
        $signals[] = [
            'id' => (int) $row['id'],
            'from_participant_id' => (int) $row['from_participant_id'],
            'to_participant_id' => (int) $row['to_participant_id'],
            'signal_type' => $row['signal_type'],
            'payload' => $decoded !== null ? $decoded : $row['payload'],
            'created_at' => $row['created_at'],
        ];
    }

    if ($ids) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $mark = $pdo->prepare("UPDATE meeting_signaling SET consumed = 1 WHERE id IN ($placeholders)");
        $mark->execute($ids);
    }

    cm_json_success([
        'signals' => $signals,
        'last_id' => $ids ? max($ids) : $afterId,
    ]);
}
