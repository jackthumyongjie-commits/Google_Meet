# Testing report — ConnectMeet

Date: 2026-10-20  
Environment: Windows + XAMPP (Apache, PHP 8.2.12, MySQL 8.0.43)

## Automated results

| # | Test | Result | Evidence |
|---|------|--------|----------|
| 1 | Landing page loads without PHP errors | **PASS** | `GET /` → 200, contains ConnectMeet branding |
| 2 | Health endpoint | **PASS** | `api/health.php` → database `ok` |
| 3 | Meeting creation unique code | **PASS** | HTTP create returned codes like `73u-rkuu-mf5` + host token |
| 4 | Invalid / unknown codes rejected | **PASS** | validate `zzz-zzzz-zzz` → 404; join unknown → 404 |
| 5 | Rooms not auto-created | **PASS** | Unknown codes absent from DB |
| 6 | Two participants can join same room (API) | **PASS** | Host + guest join; list count = 2 |
| 7 | Chat accepts HTML payload safely (escaped on render) | **PASS** | Stored as text; `cm_e()` escapes `<script>` |
| 8 | Guest cannot end meeting | **PASS** | `action=end` as guest → 403 |
| 9 | Host token verification | **PASS** | smoke_test.php |
| 10 | PHP syntax lint (all project PHP files) | **PASS** | `php -l` clean |
| 11 | Cleanup script | **PASS** | `scripts/cleanup.php` runs |

CLI: `php tests/smoke_test.php` — **0 failures**  
CLI: `php tests/setup_db.php` — schema import OK

## Manual / environment-dependent (not fully verified here)

| # | Test | Status | Why |
|---|------|--------|-----|
| 12 | Two browser windows with live camera/mic | **Needs manual** | Requires user media permission prompts |
| 13 | Remote WebRTC audio/video between peers | **Needs manual** | Needs two sessions; may need TURN off-LAN |
| 14 | Mute / camera state sync in UI | **Needs manual** | Depends on live room |
| 15 | Screen sharing | **Needs manual** | Browser permission UI |
| 16 | Raised hand / reactions animations | **Needs manual** | |
| 17 | Leave releases media tracks | **Needs manual** | Observe camera LED / `chrome://webrtc-internals` |
| 18 | Network interruption status | **Needs manual** | |
| 19 | Responsive phone/tablet layout | **Needs manual** | Use DevTools device mode |
| 20 | Captions (Web Speech API) | **Needs manual** | Chrome/Edge; local speech only |
| 21 | Local MediaRecorder download | **Needs manual** | |

## How to run the two-browser WebRTC check

1. Open `http://localhost/project/Google_Meet/`
2. Click **Start instant meeting**
3. Allow camera/mic, set name, Join
4. Copy the meeting link into a second Chrome profile / Incognito window
5. Join as a different display name
6. Confirm remote video tiles and two-way audio

If peers connect on signaling but media stays black across networks, configure TURN (see `docs/WEBRTC.md`).
