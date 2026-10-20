<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';

$appName = (string) cm_config('app_name', 'ConnectMeet');
$baseUrl = rtrim((string) cm_config('base_url'), '/');
$csrf = cm_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= cm_e($appName) ?> — Clear signal, real presence</title>
  <meta name="description" content="VeloraMeet is an independent web video conferencing app with real WebRTC connections.">
  <link rel="icon" href="<?= cm_e($baseUrl) ?>/assets/images/logo.svg" type="image/svg+xml">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&family=Syne:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A==" crossorigin="anonymous" referrerpolicy="no-referrer">
  <link rel="stylesheet" href="<?= cm_e($baseUrl) ?>/assets/css/style.css?v=20261020m">
  <link rel="stylesheet" href="<?= cm_e($baseUrl) ?>/assets/css/responsive.css?v=20261020m">
</head>
<body class="page-home">
  <div id="toastHost" aria-live="polite"></div>
  <div class="home-atmosphere" aria-hidden="true"></div>

  <div class="home-shell">
    <header class="home-nav">
      <a class="brand brand-nav" href="<?= cm_e($baseUrl) ?>/index.php">
        <span class="brand-mark" aria-hidden="true"></span>
        <span class="brand-name"><?= cm_e($appName) ?></span>
      </a>
      <div class="home-nav-right">
        <div class="home-clock" aria-live="polite">
          <strong id="homeClock">--:--</strong>
          <span id="homeDate"></span>
        </div>
        <button type="button" class="icon-btn" id="btnHomeHelp" title="Help" aria-label="Help">
          <i class="fa-solid fa-circle-question"></i>
        </button>
        <button type="button" class="icon-btn" id="btnHomeSettings" title="Settings" aria-label="Settings">
          <i class="fa-solid fa-gear"></i>
        </button>
      </div>
    </header>

    <main class="home-main">
      <section class="home-intro">
        <p class="home-kicker">Live video rooms</p>
        <h1 class="home-brand-title" aria-label="<?= cm_e($appName) ?>">
          <span class="title-stem">Velora</span><span class="title-accent">Meet</span>
        </h1>
        <p class="home-lead">Clear signal. Real presence. Zero install.</p>
        <p class="home-sub">Start instantly, share a code, and meet with real audio and video — no app download.</p>

        <div class="home-actions" aria-label="Meeting actions">
          <div class="new-meeting-wrap">
            <button type="button" class="btn btn-primary btn-meet" id="btnCreateInstant">
              <i class="fa-solid fa-video"></i> New meeting
            </button>
            <button type="button" class="btn btn-ghost" id="btnCreateLater">
              <i class="fa-solid fa-calendar-plus"></i> Create for later
            </button>
          </div>

          <form id="joinForm" class="join-box" autocomplete="off">
            <div class="join-input-wrap">
              <i class="fa-solid fa-keyboard"></i>
              <label class="sr-only" for="joinCode">Meeting code</label>
              <input id="joinCode" name="meeting" placeholder="Enter a code or link" maxlength="64" required>
            </div>
            <button type="submit" class="btn btn-text btn-join" id="btnJoinSubmit" disabled>Join</button>
          </form>
          <div class="field-hint" id="joinHint"></div>

          <div class="name-row">
            <label for="homeDisplayName">Your name</label>
            <input id="homeDisplayName" maxlength="80" placeholder="Enter display name">
            <button type="button" class="btn btn-text" id="btnSaveName">Save</button>
          </div>
        </div>
      </section>

      <aside class="home-visual" aria-hidden="true">
        <div class="visual-stage">
          <div class="visual-grid">
            <div class="visual-tile">
              <img src="<?= cm_e($baseUrl) ?>/assets/images/people/alex.jpg" alt="">
              <span class="tile-name">Alex</span>
            </div>
            <div class="visual-tile">
              <img src="<?= cm_e($baseUrl) ?>/assets/images/people/sam.jpg" alt="">
              <span class="tile-name">Sam</span>
            </div>
            <div class="visual-tile">
              <img src="<?= cm_e($baseUrl) ?>/assets/images/people/jordan.jpg" alt="">
              <span class="tile-name">Jordan</span>
            </div>
            <div class="visual-tile">
              <img src="<?= cm_e($baseUrl) ?>/assets/images/people/you.jpg" alt="">
              <span class="tile-name">You</span>
            </div>
          </div>
          <div class="visual-bar">
            <span class="dot" title="Mic"><i class="fa-solid fa-microphone"></i></span>
            <span class="dot" title="Camera"><i class="fa-solid fa-video"></i></span>
            <span class="dot end" title="Leave"><i class="fa-solid fa-phone"></i></span>
          </div>
        </div>
      </aside>
    </main>

    <footer class="home-footer">
      Independent signal room — not affiliated with Google or other meeting providers.
    </footer>
  </div>

  <div class="modal" id="laterModal" hidden>
    <div class="modal-card">
      <h2>Create meeting for later</h2>
      <div class="field">
        <label for="laterTitle">Title</label>
        <input id="laterTitle" maxlength="160" value="VeloraMeet Meeting">
      </div>
      <div class="field">
        <label for="laterSchedule">Optional start time</label>
        <input id="laterSchedule" type="datetime-local">
      </div>
      <div id="laterResult" hidden class="later-result">
        <p>Meeting code: <strong id="laterResultCode"></strong></p>
        <p><a id="laterResultLink" href="#" target="_blank" rel="noopener"></a></p>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-text" id="btnCloseLater">Close</button>
        <button type="button" class="btn btn-primary" id="btnConfirmLater">Create</button>
      </div>
    </div>
  </div>

  <div class="modal" id="homeSettingsModal" hidden>
    <div class="modal-card">
      <h2>Settings</h2>
      <p>Camera and microphone can be chosen in the lobby before you join. Display name is saved in this browser.</p>
      <div class="modal-actions">
        <button type="button" class="btn btn-primary" id="btnCloseHomeSettings">Done</button>
      </div>
    </div>
  </div>

  <div class="modal" id="helpModal" hidden>
    <div class="modal-card">
      <h2>Help &amp; shortcuts</h2>
      <ul class="shortcut-list">
        <li><span>Mute / unmute</span><kbd>M</kbd></li>
        <li><span>Camera on / off</span><kbd>V</kbd></li>
        <li><span>Toggle chat</span><kbd>C</kbd></li>
        <li><span>Raise hand</span><kbd>H</kbd></li>
        <li><span>Close panels</span><kbd>Esc</kbd></li>
      </ul>
      <p>Camera access needs HTTPS or localhost. Restrictive networks may need TURN.</p>
      <div class="modal-actions">
        <button type="button" class="btn btn-primary" id="btnCloseHelp">Got it</button>
      </div>
    </div>
  </div>

  <script>
    window.CONNECTMEET_BOOTSTRAP = {
      baseUrl: <?= json_encode($baseUrl, JSON_UNESCAPED_SLASHES) ?>,
      csrfToken: <?= json_encode($csrf) ?>,
      appName: <?= json_encode($appName) ?>
    };
  </script>
  <script src="<?= cm_e($baseUrl) ?>/assets/js/settings.js?v=20261020m"></script>
  <script src="<?= cm_e($baseUrl) ?>/assets/js/signaling.js?v=20261020m"></script>
  <script src="<?= cm_e($baseUrl) ?>/assets/js/meeting.js?v=20261020m"></script>
  <script src="<?= cm_e($baseUrl) ?>/assets/js/app.js?v=20261020m"></script>
</body>
</html>
