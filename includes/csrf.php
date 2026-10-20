<?php

declare(strict_types=1);

function cm_csrf_token(): string
{
    $key = (string) cm_config('security.csrf_token_key', 'cm_csrf');
    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = cm_generate_token(32);
    }
    return $_SESSION[$key];
}

function cm_csrf_validate(?string $token): bool
{
    $key = (string) cm_config('security.csrf_token_key', 'cm_csrf');
    $sessionToken = $_SESSION[$key] ?? '';
    if ($sessionToken === '' || $token === null || $token === '') {
        return false;
    }
    return hash_equals($sessionToken, $token);
}

function cm_require_csrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? cm_str(cm_input(), 'csrf_token');
    if (!cm_csrf_validate($token)) {
        cm_json_error('Invalid CSRF token.', 403);
    }
}
