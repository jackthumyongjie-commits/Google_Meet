# Security notes

## Implemented controls

- PDO prepared statements for all SQL
- Server-side validation for codes, names, chat length, reactions
- Output escaping in PHP (`cm_e`) and JS (`escapeHtml`) for chat/UI
- CSRF tokens on state-changing API calls
- Hashed host tokens and participant tokens (SHA-256 at rest)
- Meeting membership checks before signaling/chat/events
- Host-only authorization for end/lock/remove
- Rate limiting buckets for API / chat / reactions
- Restrictive CORS allow-list (no blanket `*` with credentials)
- Secure session cookie flags (enable `secure` on HTTPS)
- Request body size guard for JSON payloads
- Logging that strips token/password fields
- `.htaccess` denial for direct HTTP access to `config/` / `includes/` / `storage/`

## Threat reminders

- Never trust role/meeting IDs sent only from the browser
- Participants cannot end other meetings or remove the host
- Chat must never be rendered as raw HTML
- Production must use HTTPS
- Rotate TURN credentials; do not embed long-lived secrets in public repos

## Secrets

Keep `config/config.php` out of public repositories (see `.gitignore`). Ship only `config.example.php`.

**Never commit or publish:**

- Database host / name / username / password
- Production `base_url` paired with real credentials in the same file on GitHub
- TURN username/credential pairs
- Session dumps or `.env` files

If a hosting password was pasted into chat, email, or a public zip, **rotate it in cPanel immediately**.
