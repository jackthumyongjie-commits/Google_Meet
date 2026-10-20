# Troubleshooting

## Blank page / PHP error

- Confirm Apache is running and the URL matches `base_url`
- Check `storage/logs/app.log`
- Run `api/health.php` to verify DB connectivity

## “Meeting not found”

Rooms are **not** auto-created from unknown codes. Create a meeting from the landing page first.

## Camera / microphone blocked

- Use localhost or HTTPS
- Check browser site permissions
- Close other apps locking the device
- Join with audio-only / no media if needed

## Joined but no remote video

- Confirm both peers show in the People panel
- Check connection status pill
- Same LAN often works with STUN only; remote/restrictive NATs need TURN
- Browser console may show ICE `failed`

## Chat not appearing

- Ensure both users remain in the meeting (heartbeat)
- Check rate limit responses (HTTP 429)

## Host controls missing

Only the participant who joined with the creation `host_token` becomes host. Open the meeting from the same browser session that created it, or re-create and share a normal join link for guests.

## Screen share ends unexpectedly

Browsers stop `getDisplayMedia` tracks when the user clicks “Stop sharing” in the browser UI. ConnectMeet restores the camera track when that happens.
