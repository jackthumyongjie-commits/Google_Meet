<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

cm_apply_cors();
cm_rate_limit('events', (int) cm_config('security.rate_limit_max_requests', 120));

$input = cm_input();
$action = cm_str($input, 'action', 'poll');

try {
    switch ($action) {
        case 'poll':
            handle_poll_events($input);
            break;
        case 'reaction':
            handle_reaction($input);
            break;
        default:
            cm_json_error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    cm_log('events_api_error', ['error' => $e->getMessage()]);
    cm_json_error('Server error while processing events.', 500);
}

function handle_poll_events(array $input): void
{
    $participant = cm_require_participant();
    cm_touch_participant((int) $participant['id']);

    $afterId = cm_int($input, 'after_id', 0);

    $stmt = cm_db()->prepare(
        'SELECT id, participant_id, event_type, event_payload, created_at
         FROM meeting_events
         WHERE meeting_id = ? AND id > ?
         ORDER BY id ASC
         LIMIT 100'
    );
    $stmt->execute([(int) $participant['meeting_id'], $afterId]);
    $rows = $stmt->fetchAll();

    $events = [];
    $lastId = $afterId;
    foreach ($rows as $row) {
        $payload = null;
        if ($row['event_payload'] !== null) {
            $payload = json_decode($row['event_payload'], true);
        }
        $events[] = [
            'id' => (int) $row['id'],
            'participant_id' => $row['participant_id'] !== null ? (int) $row['participant_id'] : null,
            'event_type' => $row['event_type'],
            'payload' => $payload,
            'created_at' => $row['created_at'],
        ];
        $lastId = (int) $row['id'];
    }

    cm_json_success([
        'events' => $events,
        'last_id' => $lastId,
    ]);
}

function handle_reaction(array $input): void
{
    cm_require_csrf();
    cm_rate_limit('reactions', (int) cm_config('security.reaction_rate_limit_max', 40));

    $participant = cm_require_participant();
    $emoji = cm_str($input, 'emoji');

    $allowed = ['👍', '👏', '😂', '❤️', '😮', '🎉', '👋', '🔥'];
    if (!in_array($emoji, $allowed, true)) {
        cm_json_error('Unsupported reaction.', 422);
    }

    cm_publish_event((int) $participant['meeting_id'], (int) $participant['id'], 'reaction', [
        'participant_id' => (int) $participant['id'],
        'display_name' => $participant['display_name'],
        'emoji' => $emoji,
    ]);

    cm_json_success(['emoji' => $emoji], 'Reaction sent');
}
