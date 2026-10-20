# Known limitations and future upgrades

## Current prototype limits

- Mesh WebRTC only (small rooms)
- HTTP polling instead of WebSockets
- Guest-host mode (optional full accounts table exists but login UI is not required)
- Captions cover **local** speech via Web Speech API where available; remote participant speech is not reliably captioned in a pure P2P mesh without additional infrastructure
- Recording is **local browser MediaRecorder** of the client’s mixed tracks — not a server-side composite of all participants
- Waiting-room column exists; full admit/deny UI is minimal (auto-approved joins)
- No mobile native apps
- Public STUN alone does not guarantee connectivity

## Recommended upgrades

1. Managed TURN for production networks
2. WebSocket signaling service
3. SFU media layer for larger meetings
4. Optional authenticated user accounts + calendar invites
5. Server-side recording pipeline (separate media worker)
6. Stronger abuse controls (captcha on create, IP reputation)
