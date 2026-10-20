# Architecture

## Overview

ConnectMeet separates **media** from **control**:

| Concern | Technology |
|---------|------------|
| Media (audio/video/screen) | Browser WebRTC (`RTCPeerConnection`) |
| Signaling (SDP/ICE) | PHP REST JSON + short polling |
| Presence / chat / reactions | MySQL-backed events + polling |
| UI | Server-rendered PHP pages + vanilla JS modules |

## Components

- `index.php` — landing page
- `room.php` — lobby + in-meeting UI
- `api/*.php` — JSON endpoints
- `assets/js/webrtc.js` — mesh peer connections
- `assets/js/signaling.js` — HTTP client + pollers
- `assets/js/meeting.js` — room orchestration
- `scripts/cleanup.php` — stale/expired cleanup

## Meeting lifecycle

1. Host creates meeting → server stores row + `host_token`
2. Participants join with meeting code → receive `participant_token`
3. Clients poll participants; WebRTC mesh connects to every other peer
4. Offers/answers/ICE travel through `meeting_signaling` (consumed on delivery)
5. Chat/reactions/hand/media state publish to `meeting_events`
6. Heartbeats refresh `last_seen_at`; cleanup marks silent peers as left
7. Leave vs host-end are distinct events

## Security boundaries

Every protected API action requires a valid, active participant token for that meeting. Host-only actions additionally require `role = host`. Guest host authority is established only with the secret `host_token` issued at creation time (stored hashed).

## Why polling?

PHP on typical XAMPP/cPanel hosting does not provide a durable WebSocket process. Short polling keeps the prototype deployable. Production systems often move signaling to WebSockets and media to an SFU.
