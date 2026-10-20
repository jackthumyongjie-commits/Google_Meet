<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$appName = (string) cm_config('app_name', 'ConnectMeet');
$baseUrl = rtrim((string) cm_config('base_url'), '/');
$csrf = cm_csrf_token();
$meetingCode = cm_normalize_meeting_code(cm_str($_GET, 'meeting'));
$iceServers = cm_config('webrtc.ice_servers', []);
$polling = cm_config('polling', []);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Meeting — <?= cm_e($appName) ?></title>
  <link rel="icon" href="<?= cm_e($baseUrl) ?>/assets/images/logo.svg" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="<?= cm_e($baseUrl) ?>/assets/css/style.css?v=20261020m">
  <link rel="stylesheet" href="<?= cm_e($baseUrl) ?>/assets/css/responsive.css?v=20261020m">
</head>
<body class="page-room">
  <div id="toastHost" aria-live="polite"></div>
  <div class="room-atmosphere" aria-hidden="true"></div>

  <?php if (!cm_is_valid_meeting_code($meetingCode)): ?>
    <div class="lobby-shell">
      <div class="error-card">
        <a class="brand brand-nav" href="<?= cm_e($baseUrl) ?>/index.php">
          <span class="brand-mark"></span>
          <span class="brand-name"><?= cm_e($appName) ?></span>
        </a>
        <h1>Invalid meeting link</h1>
        <p>The meeting code is missing or malformed. Codes look like <code>abc-defg-hij</code>.</p>
        <a class="btn btn-primary" href="<?= cm_e($baseUrl) ?>/index.php">Return home</a>
      </div>
    </div>
  <?php else: ?>

  <div id="lobbyView" class="lobby-shell">
    <header class="lobby-top">
      <a class="brand brand-nav" href="<?= cm_e($baseUrl) ?>/index.php">
        <span class="brand-mark"></span>
        <span class="brand-name"><?= cm_e($appName) ?></span>
      </a>
      <button type="button" class="icon-btn dark" id="btnHelp" title="Help"><i class="fa-solid fa-circle-question"></i></button>
    </header>

    <div class="lobby-layout">
      <div class="lobby-preview-col">
        <div class="preview-wrap">
          <video id="lobbyPreview" playsinline muted autoplay></video>
          <div class="preview-empty" id="previewEmpty" hidden>
            <div class="preview-avatar">?</div>
            <p>Camera is off</p>
          </div>
          <div class="lobby-controls">
            <button type="button" class="meet-btn" id="lobbyMicBtn" title="Microphone"><i class="fa-solid fa-microphone"></i></button>
            <button type="button" class="meet-btn" id="lobbyCamBtn" title="Camera"><i class="fa-solid fa-video"></i></button>
          </div>
        </div>
        <div class="mic-meter" aria-hidden="true"><span id="micMeterFill"></span></div>
        <button type="button" class="btn btn-text dark-link" id="btnLobbySettings"><i class="fa-solid fa-sliders"></i> Settings</button>
      </div>

      <div class="lobby-side">
        <p class="lobby-kicker">Signal check</p>
        <h1>Ready when you are</h1>
        <p class="lobby-code"><i class="fa-solid fa-link"></i> <span id="lobbyMeetingCode"><?= cm_e($meetingCode) ?></span></p>
        <div class="field">
          <label for="displayName">Your name</label>
          <input id="displayName" class="control-input" maxlength="80" placeholder="Enter your name" required>
        </div>
        <button type="button" class="btn btn-primary btn-join-now" id="btnJoinNow">Join now</button>
        <p class="lobby-note">No account needed. If your camera is unavailable, you can still join with audio only.</p>
        <a href="<?= cm_e($baseUrl) ?>/index.php" class="btn btn-text dark-link">Cancel</a>
      </div>
    </div>
  </div>

  <div id="meetingView" class="meeting-shell" hidden>
    <header class="meeting-top">
      <div class="meeting-top-left">
        <span class="meeting-time" id="meetingClock">--:--</span>
        <span class="meeting-sep">|</span>
        <span class="meeting-code-top" id="meetingCodeLabel"><?= cm_e($meetingCode) ?></span>
        <span class="status-dot" id="connectionStatus" data-status="connecting" title="Connecting…"></span>
        <span class="recording-badge" id="recordingBadge" hidden><i class="fa-solid fa-circle"></i> REC</span>
      </div>
      <div class="meeting-top-right">
        <span class="people-chip"><i class="fa-solid fa-user-group"></i> <span id="participantCount">1</span></span>
        <button type="button" class="icon-btn dark" id="btnInfo" title="Meeting details"><i class="fa-solid fa-circle-info"></i></button>
      </div>
    </header>

    <div class="stage">
      <div id="videoGrid" class="video-grid" data-count="1"></div>
      <div id="reactionLayer"></div>
      <div id="captionsPanel" class="captions-panel">
        <div id="captionsText">Listening…</div>
      </div>

      <aside id="chatPanel" class="side-panel" aria-label="Chat">
        <header>
          <strong>In-call messages</strong>
          <button type="button" class="icon-btn dark" data-close-panel="chatPanel"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="panel-body">
          <div class="reaction-bar">
            <button type="button" data-reaction="👍">👍</button>
            <button type="button" data-reaction="👏">👏</button>
            <button type="button" data-reaction="😂">😂</button>
            <button type="button" data-reaction="❤️">❤️</button>
            <button type="button" data-reaction="🎉">🎉</button>
            <button type="button" data-reaction="🔥">🔥</button>
          </div>
          <div id="chatMessages"></div>
        </div>
        <form id="chatForm" class="chat-compose">
          <input id="chatInput" maxlength="1000" placeholder="Send a message to everyone" autocomplete="off">
          <button type="submit" class="btn btn-primary">Send</button>
        </form>
      </aside>

      <aside id="peoplePanel" class="side-panel" aria-label="Participants">
        <header>
          <strong>People</strong>
          <button type="button" class="icon-btn dark" data-close-panel="peoplePanel"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="panel-body">
          <ul id="peopleList"></ul>
        </div>
      </aside>

      <aside id="infoPanel" class="side-panel" aria-label="Meeting details">
        <header>
          <strong>Meeting details</strong>
          <button type="button" class="icon-btn dark" data-close-panel="infoPanel"><i class="fa-solid fa-xmark"></i></button>
        </header>
        <div class="panel-body">
          <p class="detail-label">Meeting title</p>
          <p id="meetingTitleLabel">Meeting</p>
          <p class="detail-label">Joining info</p>
          <p>Code: <strong id="infoMeetingCode"><?= cm_e($meetingCode) ?></strong></p>
          <p id="infoJoinUrl" class="join-url"></p>
          <button type="button" class="btn btn-soft" id="btnCopyLink">Copy joining info</button>
          <div class="host-only host-actions" hidden>
            <button type="button" class="btn btn-text dark-link" id="btnLockMeeting">Lock / unlock meeting</button>
            <button type="button" class="btn btn-danger" id="btnEndMeeting">End meeting for all</button>
          </div>
        </div>
      </aside>
    </div>

    <footer class="control-dock">
      <div class="dock-cluster">
        <button type="button" class="meet-btn" id="btnToggleMic" title="Turn off microphone (M)"><i class="fa-solid fa-microphone"></i></button>
        <button type="button" class="meet-btn" id="btnToggleCam" title="Turn off camera (V)"><i class="fa-solid fa-video"></i></button>
        <button type="button" class="meet-btn" id="btnCaptions" title="Captions"><i class="fa-solid fa-closed-captioning"></i></button>
        <button type="button" class="meet-btn" id="btnHand" title="Raise hand (H)"><i class="fa-solid fa-hand"></i></button>
        <button type="button" class="meet-btn" id="btnShare" title="Present now"><i class="fa-solid fa-arrow-up-from-bracket"></i></button>
        <button type="button" class="meet-btn" id="btnRecord" title="Record"><i class="fa-solid fa-record-vinyl"></i></button>
        <button type="button" class="meet-btn more" id="btnSettings" title="Settings"><i class="fa-solid fa-ellipsis-vertical"></i></button>
        <button type="button" class="meet-btn leave" id="btnLeave" title="Leave call"><i class="fa-solid fa-phone"></i></button>
      </div>
      <div class="dock-side">
        <button type="button" class="meet-btn ghost" id="btnInfoDock" title="Info"><i class="fa-solid fa-circle-info"></i></button>
        <button type="button" class="meet-btn ghost" id="btnPeople" title="People"><i class="fa-solid fa-user-group"></i></button>
        <button type="button" class="meet-btn ghost" id="btnChat" title="Chat (C)"><i class="fa-solid fa-message"></i></button>
      </div>
    </footer>
  </div>

  <div class="modal" id="settingsModal" hidden>
    <div class="modal-card">
      <h2>Settings</h2>
      <div class="field">
        <label for="settingDisplayName">Display name</label>
        <input id="settingDisplayName" maxlength="80">
      </div>
      <div class="field">
        <label for="settingCamera">Camera</label>
        <select id="settingCamera"></select>
      </div>
      <div class="field">
        <label for="settingMic">Microphone</label>
        <select id="settingMic"></select>
      </div>
      <div class="field">
        <label for="settingSpeaker">Speaker</label>
        <select id="settingSpeaker"></select>
        <div class="field-hint" id="sinkSupportNote">Audio output selection is not supported in this browser.</div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-text" id="btnTestSpeaker">Test speaker</button>
        <button type="button" class="btn btn-text" id="btnCloseSettings">Cancel</button>
        <button type="button" class="btn btn-primary" id="btnApplySettings">Apply</button>
      </div>
    </div>
  </div>

  <div class="modal" id="helpModal" hidden>
    <div class="modal-card">
      <h2>Keyboard shortcuts</h2>
      <ul class="shortcut-list">
        <li><span>Mute / unmute</span><kbd>M</kbd></li>
        <li><span>Camera on / off</span><kbd>V</kbd></li>
        <li><span>Toggle chat</span><kbd>C</kbd></li>
        <li><span>Raise hand</span><kbd>H</kbd></li>
        <li><span>Close panels</span><kbd>Esc</kbd></li>
      </ul>
      <p>Captions use the browser Web Speech API for your microphone. Local recording captures your browser mix only.</p>
      <div class="modal-actions">
        <button type="button" class="btn btn-primary" id="btnCloseHelp">Close</button>
      </div>
    </div>
  </div>

  <script>
    window.CONNECTMEET_ROOM = {
      baseUrl: <?= json_encode($baseUrl, JSON_UNESCAPED_SLASHES) ?>,
      csrfToken: <?= json_encode($csrf) ?>,
      meetingCode: <?= json_encode($meetingCode) ?>,
      iceServers: <?= json_encode($iceServers, JSON_UNESCAPED_SLASHES) ?>,
      polling: <?= json_encode($polling) ?>
    };
  </script>
  <script src="<?= cm_e($baseUrl) ?>/assets/js/settings.js?v=20261020m"></script>
  <script src="<?= cm_e($baseUrl) ?>/assets/js/signaling.js?v=20261020m"></script>
  <script src="<?= cm_e($baseUrl) ?>/assets/js/webrtc.js?v=20261020m"></script>
  <script src="<?= cm_e($baseUrl) ?>/assets/js/meeting.js?v=20261020m"></script>
  <script>
    document.addEventListener('DOMContentLoaded', () => {
      const prefs = ConnectMeetSettings.loadPrefs();
      const nameField = document.getElementById('settingDisplayName');
      if (nameField && prefs.displayName) nameField.value = prefs.displayName;
      const app = new ConnectMeetApp(window.CONNECTMEET_ROOM);
      app.init();
    });
  </script>

  <?php endif; ?>
</body>
</html>
