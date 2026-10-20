<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

cm_apply_cors();
cm_rate_limit('chat', (int) cm_config('security.rate_limit_max_requests', 120));

$input = cm_input();
$action = cm_str($input, 'action', 'poll');

try {
    switch ($action) {
        case 'send':
            handle_send_message($input);
            break;
        case 'poll':
            handle_poll_messages($input);
            break;
        default:
            cm_json_error('Unknown action.', 400);
    }
} catch (Throwable $e) {
    cm_log('chat_api_error', ['error' => $e->getMessage()]);
    cm_json_error('Server error while processing chat.', 500);
}

function handle_send_message(array $input): void
{
    cm_require_csrf();
    cm_rate_limit('chat_send', (int) cm_config('security.chat_rate_limit_max', 30));

    $participant = cm_require_participant();
    $content = trim(cm_str($input, 'message'));
    $max = (int) cm_config('security.chat_message_max', 1000);

    if ($content === '') {
        cm_json_error('Message cannot be empty.', 422);
    }
    if (mb_strlen($content) > $max) {
        cm_json_error('Message is too long.', 422);
    }

    // Strip control characters; HTML is escaped on display.
    $content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $content) ?? '';

    $stmt = cm_db()->prepare(
        'INSERT INTO meeting_messages (meeting_id, participant_id, message_type, message_content)
         VALUES (?, ?, \'text\', ?)'
    );
    $stmt->execute([
        (int) $participant['meeting_id'],
        (int) $participant['id'],
        $content,
    ]);
    $messageId = (int) cm_db()->lastInsertId();

    cm_publish_event((int) $participant['meeting_id'], (int) $participant['id'], 'chat_message', [
        'message_id' => $messageId,
    ]);

    cm_json_success([
        'message' => [
            'id' => $messageId,
            'participant_id' => (int) $participant['id'],
            'display_name' => $participant['display_name'],
            'message_type' => 'text',
            'message_content' => $content,
            'created_at' => date('Y-m-d H:i:s'),
        ],
    ], 'Message sent', 201);
}

function handle_poll_messages(array $input): void
{
    $participant = cm_require_participant();
    $afterId = cm_int($input, 'after_id', 0);

    $stmt = cm_db()->prepare(
        'SELECT m.id, m.participant_id, m.message_type, m.message_content, m.created_at, p.display_name
         FROM meeting_messages m
         INNER JOIN meeting_participants p ON p.id = m.participant_id
         WHERE m.meeting_id = ? AND m.id > ?
         ORDER BY m.id ASC
         LIMIT 100'
    );
    $stmt->execute([(int) $participant['meeting_id'], $afterId]);
    $rows = $stmt->fetchAll();

    $messages = [];
    $lastId = $afterId;
    foreach ($rows as $row) {
        $messages[] = [
            'id' => (int) $row['id'],
            'participant_id' => (int) $row['participant_id'],
            'display_name' => $row['display_name'],
            'message_type' => $row['message_type'],
            'message_content' => $row['message_content'],
            'created_at' => $row['created_at'],
        ];
        $lastId = (int) $row['id'];
    }

    cm_json_success([
        'messages' => $messages,
        'last_id' => $lastId,
    ]);
}
