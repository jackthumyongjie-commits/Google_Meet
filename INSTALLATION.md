# ConnectMeet Installation Guide

## Requirements

- Windows 10/11 with XAMPP (Apache + MySQL/MariaDB + PHP 8.2+; 8.3 preferred — verified here on 8.2.12)
- PHP extensions: `pdo_mysql`, `json`, `mbstring`, `openssl`
- Modern Chrome, Edge, or Firefox
- For production: HTTPS and ideally a TURN server

## Local XAMPP setup

### 1. Start services

Open XAMPP Control Panel and start **Apache** and **MySQL**.

### 2. Place the project

This workspace path works out of the box with the default config:

`C:\xampp\htdocs\project\Google_Meet\`

Optional: copy/rename the folder to `C:\xampp\htdocs\connectmeet\` and update `base_url` accordingly.

### 3. Create the database

Using phpMyAdmin (`http://localhost/phpmyadmin`):

1. Import `database/install.sql`
2. Confirm database `connectmeet` and tables exist

Or via CLI (adjust path to your XAMPP mysql):

```bat
C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\project\Google_Meet\database\install.sql
```

### 4. Configure the app

```bat
copy config\config.example.php config\config.php
```

Edit `config/config.php`:

- `base_url` — must match how you open the site, e.g. `http://localhost/project/Google_Meet`
- `db.*` — host, port, name, user, password (this machine uses MySQL on port `3306`; set the password your XAMPP MySQL root account actually uses)
- Optional helper: `php tests/setup_db.php` imports the schema via PDO if the mysql CLI plugin fails
- `webrtc.ice_servers` — add TURN credentials for cross-network testing
- `session.secure` — set `true` only behind HTTPS

### 5. Permissions

Ensure `storage/logs` is writable by Apache/PHP.

### 6. Open the app

Visit:

`http://localhost/project/Google_Meet/`

Health check:

`http://localhost/project/Google_Meet/api/health.php`

### 7. Camera / microphone notes

- Browsers treat **localhost** as a secure context, so media usually works there.
- Opening the site from another computer via plain `http://LAN-IP/...` often **blocks** camera/microphone.
- For remote devices, use HTTPS (or a tunnel that terminates TLS).

### 8. Cleanup cron (optional)

```bat
C:\xampp\php\php.exe C:\xampp\htdocs\project\Google_Meet\scripts\cleanup.php
```

Schedule with Windows Task Scheduler every 5–15 minutes.

---

## cPanel deployment

Full checklist (Chinese): see [`docs/CPANEL.md`](docs/CPANEL.md).

Short version:

1. Enable **HTTPS** (camera/mic will not work on plain HTTP).
2. PHP **8.2+** in MultiPHP Manager.
3. Create MySQL DB + user in cPanel.
4. Import **`database/install_cpanel.sql`** (not the local `install.sql` that creates a database).
5. Upload files; edit `config/config.php`:
   - `base_url` → your `https://...` URL (no trailing slash)
   - `db.*` → cPanel credentials
   - `session.secure` → `true`
   - `allowed_origins` → your domain
6. Confirm `storage/logs` is writable.
7. Open `/api/health.php` — expect `"database":"ok"`.
8. Optional cron: `php /home/USER/path/scripts/cleanup.php`

### Hosting limitations

Shared PHP hosting usually **cannot** run:

- WebSocket signaling daemons
- Coturn TURN servers
- SFU media servers (mediasoup / LiveKit / Janus)

Use external TURN / SFU providers when you outgrow the mesh prototype.

---

## Smoke test after install

1. Open landing page — no PHP errors
2. Start instant meeting — receive code + enter lobby
3. Open the join URL in a second browser profile
4. Allow camera/mic and confirm remote video when network permits
5. Send a chat message both ways
