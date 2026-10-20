# ConnectMeet Testing Checklist

## Automated / local API checks

| # | Test | Result | Notes |
|---|------|--------|-------|
| 1 | Landing page loads without PHP errors | Pending | |
| 2 | Health endpoint returns database ok | Pending | |
| 3 | Meeting creation returns unique code + host token | Pending | |
| 4 | Invalid meeting codes rejected | Pending | |
| 5 | Unknown codes do not auto-create rooms | Pending | |
| 6 | Join requires valid display name | Pending | |
| 7 | Chat HTML is stored as text (escaped on display) | Pending | |
| 8 | Unauthorized end/remove rejected | Pending | |
| 9 | Cleanup script runs | Pending | |

## Manual / multi-browser checks

| # | Test | Result | Notes |
|---|------|--------|-------|
| 10 | Two browser sessions join same room | Needs manual | Use two windows/profiles |
| 11 | Local camera/mic permissions | Needs manual | Secure context required |
| 12 | Remote A/V via WebRTC | Needs manual | May need TURN off-LAN |
| 13 | Mute/camera sync | Needs manual | |
| 14 | Screen share start/stop | Needs manual | Browser permission prompt |
| 15 | Chat delivery | Needs manual | |
| 16 | Raised hand sync | Needs manual | |
| 17 | Leave releases media tracks | Needs manual | Check camera LED |
| 18 | Network interruption status | Needs manual | |
| 19 | Responsive layout | Needs manual | DevTools device mode |
| 20 | Captions / recording limitations disclosed | Needs manual | Chrome/Edge best |

## Environment dependencies

- HTTPS or localhost for media APIs
- External TURN for restrictive NATs / cross-network peers
- Two physical devices for full mesh verification
