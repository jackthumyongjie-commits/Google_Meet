# WebRTC, STUN, TURN, and SFU notes

## Mesh prototype

ConnectMeet uses a **peer-to-peer mesh**: every participant maintains an `RTCPeerConnection` to every other participant.

Implications:

- Works well for 2–6 people on good networks
- Upload bandwidth grows roughly with `(N-1)` remote peers
- CPU cost rises with decode/encode of multiple streams
- Default participant limit is intentionally low

## ICE servers

Configure in `config/config.php` under `webrtc.ice_servers`.

Public STUN servers help with NAT discovery but **do not relay media**. Many corporate/school networks still fail without TURN.

### Example TURN entry

```php
[
    'urls' => 'turn:turn.example.com:3478',
    'username' => 'user',
    'credential' => 'secret',
]
```

You can self-host [coturn](https://github.com/coturn/coturn) or use a hosted TURN provider.

## Secure contexts

`getUserMedia` / `getDisplayMedia` require a [secure context](https://developer.mozilla.org/en-US/docs/Web/Security/Secure_Contexts):

- `https://…`
- `http://localhost` / `http://127.0.0.1`

Plain HTTP over a LAN IP frequently blocks media.

## Future upgrade: SFU

For larger rooms, move media through a Selective Forwarding Unit (LiveKit, mediasoup, Janus, Ion-SFU, etc.):

- Each client uploads once
- Server forwards selected streams
- PHP can remain for auth/rooms while the SFU handles media

Do **not** attempt to build a production SFU in PHP alone.
