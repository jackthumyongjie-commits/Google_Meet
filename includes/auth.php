<?php

declare(strict_types=1);

/**
 * Guest-host mode helpers.
 * Full user accounts are optional; host authority is proven via host_token.
 */

function cm_verify_host_token(array $meeting, string $hostToken): bool
{
    if ($hostToken === '' || empty($meeting['host_token_hash'])) {
        return false;
    }
    return hash_equals($meeting['host_token_hash'], cm_hash_token($hostToken));
}

function cm_participant_is_host(array $participant): bool
{
    return ($participant['role'] ?? '') === 'host';
}

function cm_require_host(array $participant): void
{
    if (!cm_participant_is_host($participant)) {
        cm_json_error('Only the host can perform this action.', 403);
    }
}
