/**
 * ConnectMeet landing page logic.
 */
(function () {
  'use strict';

  const $ = (sel) => document.querySelector(sel);
  const notify = window.ConnectMeetNotify || ((msg) => alert(msg));

  function tickClock() {
    const el = $('#homeClock');
    if (!el) return;
    const now = new Date();
    el.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const dateEl = $('#homeDate');
    if (dateEl) {
      dateEl.textContent = now.toLocaleDateString(undefined, {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
      });
    }
  }

  function normalizeCode(raw) {
    return String(raw || '')
      .trim()
      .toLowerCase()
      .replace(/\s+/g, '')
      .replace(/[_.]/g, '-');
  }

  function isValidCode(code) {
    return /^[a-z0-9]{3}-[a-z0-9]{4}-[a-z0-9]{3}$/.test(code);
  }

  async function init() {
    tickClock();
    setInterval(tickClock, 1000);

    const cfg = window.CONNECTMEET_BOOTSTRAP || {};
    const signaling = new ConnectMeetSignaling({
      baseUrl: cfg.baseUrl || '',
      csrfToken: cfg.csrfToken || '',
    });

    const prefs = ConnectMeetSettings.loadPrefs();
    if ($('#homeDisplayName') && prefs.displayName) {
      $('#homeDisplayName').value = prefs.displayName;
    }

    $('#btnSaveName')?.addEventListener('click', () => {
      const name = ($('#homeDisplayName')?.value || '').trim();
      if (name.length < 2) {
        notify('Display name must be at least 2 characters.', 'warning');
        return;
      }
      ConnectMeetSettings.savePrefs({
        ...ConnectMeetSettings.loadPrefs(),
        displayName: name,
      });
      notify('Display name saved for this tab', 'success');
    });

    $('#btnCreateInstant')?.addEventListener('click', async () => {
      await createAndGo(signaling, { mode: 'instant' });
    });

    $('#btnCreateLater')?.addEventListener('click', () => {
      $('#laterModal').hidden = false;
    });

    $('#btnCloseLater')?.addEventListener('click', () => {
      $('#laterModal').hidden = true;
    });

    $('#btnConfirmLater')?.addEventListener('click', async () => {
      const title = ($('#laterTitle')?.value || 'VeloraMeet Meeting').trim();
      const scheduled = $('#laterSchedule')?.value || '';
      await createAndGo(signaling, {
        mode: 'later',
        title,
        scheduled_at: scheduled ? new Date(scheduled).toISOString() : '',
      });
    });

    $('#joinForm')?.addEventListener('submit', async (e) => {
      e.preventDefault();
      let raw = String($('#joinCode')?.value || '').trim();
      const match = raw.match(/[a-z0-9]{3}-[a-z0-9]{4}-[a-z0-9]{3}/i);
      const code = normalizeCode(match ? match[0] : raw);
      if (!isValidCode(code)) {
        notify('Enter a valid meeting code like abc-defg-hij', 'warning');
        return;
      }
      try {
        const res = await signaling.validateMeeting(code);
        if (!res.data.joinable) {
          notify(res.data.reason || 'Cannot join this meeting', 'error');
          return;
        }
        const name = ($('#homeDisplayName')?.value || '').trim();
        if (name.length >= 2) {
          ConnectMeetSettings.savePrefs({ ...ConnectMeetSettings.loadPrefs(), displayName: name });
        }
        window.location.href = `${cfg.baseUrl}/room.php?meeting=${encodeURIComponent(code)}`;
      } catch (err) {
        notify(err.message, 'error');
      }
    });

    const joinSubmit = $('#btnJoinSubmit');
    $('#joinCode')?.addEventListener('input', (e) => {
      const raw = String(e.target.value || '').trim();
      const code = normalizeCode(raw);
      const hint = $('#joinHint');
      const looksLikeCode = isValidCode(code);
      const looksLikeLink = /meeting=/.test(raw) || /room\.php/.test(raw);
      if (joinSubmit) joinSubmit.disabled = !(looksLikeCode || looksLikeLink || code.length >= 8);

      if (!hint) return;
      if (!raw) {
        hint.textContent = '';
        return;
      }
      if (looksLikeCode) {
        hint.textContent = 'Looks good';
        hint.className = 'field-hint ok';
      } else if (looksLikeLink) {
        hint.textContent = 'Link detected';
        hint.className = 'field-hint ok';
      } else {
        hint.textContent = 'Format: xxx-xxxx-xxx';
        hint.className = 'field-hint warn';
      }
    });

    $('#btnHomeHelp')?.addEventListener('click', () => {
      $('#helpModal').hidden = false;
    });
    $('#btnCloseHelp')?.addEventListener('click', () => {
      $('#helpModal').hidden = true;
    });
    $('#btnHomeSettings')?.addEventListener('click', () => {
      $('#homeSettingsModal').hidden = false;
    });
    $('#btnCloseHomeSettings')?.addEventListener('click', () => {
      $('#homeSettingsModal').hidden = true;
    });
  }

  async function createAndGo(signaling, body) {
    const btn = body.mode === 'later' ? $('#btnConfirmLater') : $('#btnCreateInstant');
    if (btn) btn.disabled = true;
    try {
      const res = await signaling.createMeeting(body);
      const meeting = res.data.meeting;
      const hostToken = res.data.host_token;
      sessionStorage.setItem('cm_host_token_' + meeting.meeting_code, hostToken);
      if (body.mode === 'later') {
        $('#laterResult').hidden = false;
        $('#laterResultCode').textContent = meeting.meeting_code;
        $('#laterResultLink').textContent = meeting.join_url;
        $('#laterResultLink').href = meeting.join_url;
        notify('Meeting scheduled. Share the link with participants.', 'success');
        return;
      }
      window.location.href = meeting.join_url;
    } catch (err) {
      notify(err.message, 'error');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  document.addEventListener('DOMContentLoaded', init);
})();
