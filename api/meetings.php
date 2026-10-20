<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

cm_apply_cors();
cm_rate_limit('meetings', (int) cm_config('security.rate_limit_max_requests', 120));

$method = cm_request_method();
$input = cm_input();
$action = cm_str($input, 'action', $method === 'GET' ? 'validate' : 'create');

try {
    switch ($action) {
        case 'create':
            handle_create_meeting($input);
            break;
        case 'validate':
            handle_validate_meeting($input);
            break;
        case 'info':
            handle_meeting_info($input);
            break;
        case 'lock':
        case 'unlock':
            handle_lock_meeting($input, $action === 'lock');
            break;
        case 'end':
            handle_end_meeting($input);
            break;
        case 'bootstrap':
            handle_bootstrap($input);
            break;
        default:
            cm_json_error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    cm_log('meetings_api_error', ['error' => $e->getMessage()]);
    cm_json_error('Server error while processing meeting request.', 500);
}

function handle_bootstrap(array $input): void
{
    cm_json_success([
        'csrf_token' => cm_csrf_token(),
        'app_name' => cm_config('app_name'),
        'app_version' => cm_config('app_version'),
        'base_url' => cm_config('base_url'),
        'ice_servers' => cm_config('webrtc.ice_servers', []),
        'polling' => cm_config('polling', []),
        'limits' => [
            'display_name_max' => (int) cm_config('security.display_name_max', 80),
            'chat_message_max' => (int) cm_config('security.chat_message_max', 1000),
            'participant_limit_default' => (int) cm_config('security.participant_limit_default', 8),
            'participant_limit_max' => (int) cm_config('security.participant_limit_max', 12),
        ],
    ], 'Bootstrap data');
}

function handle_create_meeting(array $input): void
{
    cm_require_csrf();

    $title = cm_sanitize_display_name(cm_str($input, 'title', 'VeloraMeet Meeting'));
    if ($title === '') {
        $title = 'VeloraMeet Meeting';
    }

    $mode = cm_str($input, 'mode', 'instant'); // instant | later
    $scheduledAt = null;
    if ($mode === 'later') {
        $scheduledRaw = cm_str($input, 'scheduled_at');
        if ($scheduledRaw !== '') {
            $ts = strtotime($scheduledRaw);
            if ($ts === false) {
                cm_json_error('Invalid scheduled time.', 422);
            }
            $scheduledAt = date('Y-m-d H:i:s', $ts);
        }
    }

    $limit = cm_int($input, 'participant_limit', (int) cm_config('security.participant_limit_default', 8));
    $maxLimit = (int) cm_config('security.participant_limit_max', 12);
    if ($limit < 2) {
        $limit = 2;
    }
    if ($limit > $maxLimit) {
        $limit = $maxLimit;
    }

    $ttlHours = (int) cm_config('security.meeting_ttl_hours', 24);
    $expiresAt = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));
    $hostToken = cm_generate_token(32);
    $hostTokenHash = cm_hash_token($hostToken);

    $pdo = cm_db();
    $meetingCode = '';
    $meetingId = 0;

    for ($attempt = 0; $attempt < 8; $attempt++) {
        $meetingCode = cm_generate_meeting_code();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO meetings
                 (meeting_code, host_token_hash, title, status, scheduled_at, expires_at, participant_limit)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $status = $mode === 'later' ? 'scheduled' : 'active';
            $stmt->execute([
                $meetingCode,
                $hostTokenHash,
                $title,
                $status,
                $scheduledAt,
                $expiresAt,
                $limit,
            ]);
            $meetingId = (int) $pdo->lastInsertId();
            break;
        } catch (PDOException $e) {
            if ($attempt === 7) {
                throw $e;
            }
        }
    }

    $meeting = cm_find_meeting_by_code($meetingCode);
    if (!$meeting) {
        cm_json_error('Failed to create meeting.', 500);
    }

    cm_publish_event($meetingId, null, 'meeting_created', [
        'meeting_code' => $meetingCode,
    ]);

    cm_json_success([
        'meeting' => cm_public_meeting($meeting),
        'host_token' => $hostToken,
    ], 'Meeting created', 201);
}

function handle_validate_meeting(array $input): void
{
    $code = cm_normalize_meeting_code(cm_str($input, 'meeting_code', cm_str($input, 'meeting')));
    if (!cm_is_valid_meeting_code($code)) {
        cm_json_error('Invalid meeting code format.', 422);
    }

    $meeting = cm_find_meeting_by_code($code);
    if (!$meeting) {
        cm_json_error('Meeting not found.', 404);
    }

    cm_cleanup_stale_participants((int) $meeting['id']);

    $reason = cm_meeting_is_joinable($meeting);
    $joinable = $reason === null;

    // Locked meetings still "exist" but are not joinable.
    if ($meeting['status'] === 'scheduled') {
        $joinable = true;
        $reason = null;
    }

    cm_json_success([
        'valid' => true,
        'joinable' => $joinable,
        'reason' => $reason,
        'meeting' => cm_public_meeting($meeting),
    ], $joinable ? 'Meeting is valid' : ($reason ?? 'Meeting unavailable'));
}

function handle_meeting_info(array $input): void
{
    $participant = cm_require_participant();
    $meeting = cm_find_meeting_by_code($participant['meeting_code']);
    if (!$meeting) {
        cm_json_error('Meeting not found.', 404);
    }

    cm_touch_participant((int) $participant['id']);
    cm_json_success([
        'meeting' => cm_public_meeting($meeting),
        'you' => cm_public_participant($participant),
    ]);
}

function handle_lock_meeting(array $input, bool $lock): void
{
    cm_require_csrf();
    $participant = cm_require_participant();
    cm_require_host($participant);

    $stmt = cm_db()->prepare('UPDATE meetings SET is_locked = ? WHERE id = ?');
    $stmt->execute([$lock ? 1 : 0, (int) $participant['meeting_id']]);

    cm_publish_event((int) $participant['meeting_id'], (int) $participant['id'], $lock ? 'meeting_locked' : 'meeting_unlocked', []);

    cm_json_success([
        'is_locked' => $lock,
    ], $lock ? 'Meeting locked' : 'Meeting unlocked');
}

function handle_end_meeting(array $input): void
{
    cm_require_csrf();
    $participant = cm_require_participant();
    cm_require_host($participant);

    $pdo = cm_db();
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("UPDATE meetings SET status = 'ended' WHERE id = ?");
        $upd->execute([(int) $participant['meeting_id']]);

        $leave = $pdo->prepare(
            "UPDATE meeting_participants
             SET left_at = NOW(), connection_state = 'disconnected'
             WHERE meeting_id = ? AND left_at IS NULL"
        );
        $leave->execute([(int) $participant['meeting_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    cm_publish_event((int) $participant['meeting_id'], (int) $participant['id'], 'meeting_ended', [
        'ended_by' => (int) $participant['id'],
    ]);

    cm_json_success(null, 'Meeting ended for everyone');
}
