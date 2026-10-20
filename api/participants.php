<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

cm_apply_cors();
cm_rate_limit('participants', (int) cm_config('security.rate_limit_max_requests', 120));

$method = cm_request_method();
$input = cm_input();
$action = cm_str($input, 'action', $method === 'GET' ? 'list' : 'join');

try {
    switch ($action) {
        case 'join':
            handle_join($input);
            break;
        case 'leave':
            handle_leave($input);
            break;
        case 'heartbeat':
            handle_heartbeat($input);
            break;
        case 'list':
            handle_list($input);
            break;
        case 'update_media':
            handle_update_media($input);
            break;
        case 'raise_hand':
            handle_raise_hand($input, true);
            break;
        case 'lower_hand':
            handle_raise_hand($input, false);
            break;
        case 'remove':
            handle_remove($input);
            break;
        case 'screen_share':
            handle_screen_share($input);
            break;
        default:
            cm_json_error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    cm_log('participants_api_error', ['error' => $e->getMessage()]);
    cm_json_error('Server error while processing participant request.', 500);
}

function handle_join(array $input): void
{
    cm_require_csrf();

    $code = cm_normalize_meeting_code(cm_str($input, 'meeting_code', cm_str($input, 'meeting')));
    if (!cm_is_valid_meeting_code($code)) {
        cm_json_error('Invalid meeting code format.', 422);
    }

    $displayName = cm_sanitize_display_name(cm_str($input, 'display_name'));
    if ($displayName === '' || mb_strlen($displayName) < 2) {
        cm_json_error('Display name must be at least 2 characters.', 422);
    }

    $meeting = cm_find_meeting_by_code($code);
    if (!$meeting) {
        cm_json_error('Meeting not found. Rooms are not created automatically from unknown codes.', 404);
    }

    cm_cleanup_stale_participants((int) $meeting['id']);

    $hostToken = cm_str($input, 'host_token');
    $isHostClaim = $hostToken !== '' && cm_verify_host_token($meeting, $hostToken);

    $reason = cm_meeting_is_joinable($meeting);
    if ($reason !== null && !$isHostClaim) {
        cm_json_error($reason, 403);
    }

    // Activate scheduled meetings when host joins.
    if ($meeting['status'] === 'scheduled' && $isHostClaim) {
        $act = cm_db()->prepare("UPDATE meetings SET status = 'active' WHERE id = ?");
        $act->execute([(int) $meeting['id']]);
        $meeting['status'] = 'active';
    }

    if ($meeting['status'] === 'scheduled' && !$isHostClaim) {
        // Guests may join early if not locked; treat as active lobby.
    }

    $activeCount = cm_count_active_participants((int) $meeting['id']);
    if ($activeCount >= (int) $meeting['participant_limit'] && !$isHostClaim) {
        cm_json_error('This meeting has reached its participant limit.', 403);
    }

    // Prevent multiple simultaneous host roles from forged claims.
    $role = 'participant';
    if ($isHostClaim) {
        $existingHost = cm_db()->prepare(
            "SELECT id FROM meeting_participants
             WHERE meeting_id = ? AND role = 'host' AND left_at IS NULL LIMIT 1"
        );
        $existingHost->execute([(int) $meeting['id']]);
        if ($existingHost->fetch()) {
            // Host already present; join as participant unless same token rejoin handled below.
            $role = 'participant';
        } else {
            $role = 'host';
        }
    }

    $audio = cm_bool($input, 'audio_enabled', true);
    $video = cm_bool($input, 'video_enabled', true);
    $participantToken = cm_generate_token(32);
    $tokenHash = cm_hash_token($participantToken);

    $pdo = cm_db();
    $stmt = $pdo->prepare(
        'INSERT INTO meeting_participants
         (meeting_id, participant_token_hash, display_name, role, audio_enabled, video_enabled, approval_status)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        (int) $meeting['id'],
        $tokenHash,
        $displayName,
        $role,
        $audio ? 1 : 0,
        $video ? 1 : 0,
        'approved',
    ]);
    $participantId = (int) $pdo->lastInsertId();

    cm_publish_event((int) $meeting['id'], $participantId, 'participant_joined', [
        'participant_id' => $participantId,
        'display_name' => $displayName,
        'role' => $role,
        'audio_enabled' => $audio,
        'video_enabled' => $video,
    ]);

    $participant = cm_get_participant_by_token($participantToken);
    $meeting = cm_find_meeting_by_code($code);

    cm_json_success([
        'participant_token' => $participantToken,
        'participant' => cm_public_participant($participant),
        'meeting' => cm_public_meeting($meeting),
        'is_host' => $role === 'host',
    ], 'Joined meeting', 201);
}

function handle_leave(array $input): void
{
    cm_require_csrf();
    $participant = cm_require_participant();
    cm_mark_participant_left((int) $participant['id'], (int) $participant['meeting_id'], true);
    cm_json_success(null, 'Left meeting');
}

function handle_heartbeat(array $input): void
{
    $participant = cm_require_participant();
    cm_touch_participant((int) $participant['id']);
    cm_cleanup_stale_participants((int) $participant['meeting_id']);

    $meeting = cm_find_meeting_by_code($participant['meeting_code']);
    if (!$meeting || in_array($meeting['status'], ['ended', 'expired'], true)) {
        cm_json_error('Meeting has ended.', 410, ['meeting_ended' => true]);
    }

    cm_json_success([
        'server_time' => date('c'),
        'meeting_status' => $meeting['status'],
        'participant_count' => cm_count_active_participants((int) $meeting['id']),
    ], 'Heartbeat ok');
}

function handle_list(array $input): void
{
    $participant = cm_require_participant();
    cm_touch_participant((int) $participant['id']);
    cm_cleanup_stale_participants((int) $participant['meeting_id']);

    $stmt = cm_db()->prepare(
        "SELECT * FROM meeting_participants
         WHERE meeting_id = ? AND left_at IS NULL AND approval_status = 'approved'
         ORDER BY FIELD(role, 'host', 'participant'), joined_at ASC"
    );
    $stmt->execute([(int) $participant['meeting_id']]);
    $rows = $stmt->fetchAll();

    $list = array_map('cm_public_participant', $rows);

    cm_json_success([
        'participants' => $list,
        'count' => count($list),
        'you_id' => (int) $participant['id'],
    ]);
}

function handle_update_media(array $input): void
{
    cm_require_csrf();
    $participant = cm_require_participant();

    $audio = array_key_exists('audio_enabled', $input)
        ? (cm_bool($input, 'audio_enabled') ? 1 : 0)
        : (int) $participant['audio_enabled'];
    $video = array_key_exists('video_enabled', $input)
        ? (cm_bool($input, 'video_enabled') ? 1 : 0)
        : (int) $participant['video_enabled'];

    $stmt = cm_db()->prepare(
        'UPDATE meeting_participants SET audio_enabled = ?, video_enabled = ?, last_seen_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$audio, $video, (int) $participant['id']]);

    cm_publish_event((int) $participant['meeting_id'], (int) $participant['id'], 'media_state', [
        'participant_id' => (int) $participant['id'],
        'audio_enabled' => (bool) $audio,
        'video_enabled' => (bool) $video,
    ]);

    cm_json_success([
        'audio_enabled' => (bool) $audio,
        'video_enabled' => (bool) $video,
    ], 'Media state updated');
}

function handle_raise_hand(array $input, bool $raised): void
{
    cm_require_csrf();
    $participant = cm_require_participant();

    $stmt = cm_db()->prepare(
        'UPDATE meeting_participants SET hand_raised = ?, last_seen_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$raised ? 1 : 0, (int) $participant['id']]);

    cm_publish_event((int) $participant['meeting_id'], (int) $participant['id'], $raised ? 'hand_raised' : 'hand_lowered', [
        'participant_id' => (int) $participant['id'],
        'display_name' => $participant['display_name'],
    ]);

    cm_json_success(['hand_raised' => $raised], $raised ? 'Hand raised' : 'Hand lowered');
}

function handle_remove(array $input): void
{
    cm_require_csrf();
    $participant = cm_require_participant();
    cm_require_host($participant);

    $targetId = cm_int($input, 'target_participant_id');
    if ($targetId <= 0) {
        cm_json_error('target_participant_id is required.', 422);
    }
    if ($targetId === (int) $participant['id']) {
        cm_json_error('Use leave to remove yourself.', 422);
    }

    $stmt = cm_db()->prepare(
        'SELECT * FROM meeting_participants WHERE id = ? AND meeting_id = ? AND left_at IS NULL LIMIT 1'
    );
    $stmt->execute([$targetId, (int) $participant['meeting_id']]);
    $target = $stmt->fetch();
    if (!$target) {
        cm_json_error('Participant not found.', 404);
    }
    if ($target['role'] === 'host') {
        cm_json_error('Cannot remove the host.', 403);
    }

    cm_mark_participant_left($targetId, (int) $participant['meeting_id'], true);
    cm_publish_event((int) $participant['meeting_id'], (int) $participant['id'], 'participant_removed', [
        'participant_id' => $targetId,
        'removed_by' => (int) $participant['id'],
    ]);

    cm_json_success(null, 'Participant removed');
}

function handle_screen_share(array $input): void
{
    cm_require_csrf();
    $participant = cm_require_participant();
    $sharing = cm_bool($input, 'is_sharing', false);

    $stmt = cm_db()->prepare(
        'UPDATE meeting_participants SET is_sharing_screen = ?, last_seen_at = NOW() WHERE id = ?'
    );
    $stmt->execute([$sharing ? 1 : 0, (int) $participant['id']]);

    cm_publish_event(
        (int) $participant['meeting_id'],
        (int) $participant['id'],
        $sharing ? 'screen_share_started' : 'screen_share_stopped',
        [
            'participant_id' => (int) $participant['id'],
            'display_name' => $participant['display_name'],
        ]
    );

    cm_json_success(['is_sharing_screen' => $sharing], $sharing ? 'Screen sharing started' : 'Screen sharing stopped');
}
