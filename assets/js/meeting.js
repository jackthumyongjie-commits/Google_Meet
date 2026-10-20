/**
 * ConnectMeet in-meeting controller: lobby + room UI wiring.
 */
(function (global) {
  'use strict';

  const $ = (sel, root = document) => root.querySelector(sel);
  const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function notify(message, type = 'info') {
    const host = $('#toastHost');
    if (!host) {
      console.log(`[${type}]`, message);
      return;
    }
    const el = document.createElement('div');
    el.className = `toast toast-${type}`;
    el.textContent = message;
    host.appendChild(el);
    setTimeout(() => el.classList.add('show'), 10);
    setTimeout(() => {
      el.classList.remove('show');
      setTimeout(() => el.remove(), 300);
    }, 4200);
  }

  class MeetingApp {
    constructor(config) {
      this.config = config;
      this.prefs = ConnectMeetSettings.loadPrefs();
      this.signaling = new ConnectMeetSignaling({
        baseUrl: config.baseUrl,
        csrfToken: config.csrfToken,
        timeoutMs: (config.polling && config.polling.request_timeout_ms) || 10000,
      });
      this.webrtc = null;
      this.meeting = null;
      this.participant = null;
      this.participantToken = null;
      this.isHost = false;
      this.participants = [];
      this.localStream = null;
      this.remoteStreams = new Map();
      this.audioEnabled = this.prefs.audioEnabled !== false;
      this.videoEnabled = this.prefs.videoEnabled !== false;
      this.handRaised = false;
      this.captions = null;
      this.recorder = new ConnectMeetSettings.LocalRecorder();
      this.connectionStatus = 'idle';
      this._speakingTimer = null;
      this._left = false;
    }

    async init() {
      this.bindLobby();
      this.bindMeetingControls();
      this.bindPanels();
      this.bindShortcuts();
      this.renderClock();
      setInterval(() => this.renderClock(), 1000);
      const infoDock = document.getElementById('btnInfoDock');
      if (infoDock) {
        infoDock.addEventListener('click', () => document.getElementById('infoPanel')?.classList.toggle('open'));
      }

      const code = this.config.meetingCode;
      if (code) {
        $('#lobbyMeetingCode').textContent = code;
        try {
          const res = await this.signaling.validateMeeting(code);
          this.meeting = res.data.meeting;
          if (!res.data.joinable) {
            notify(res.data.reason || 'Meeting unavailable', 'error');
          }
        } catch (err) {
          notify(err.message, 'error');
          $('#btnJoinNow').disabled = true;
        }
      }

      const nameInput = $('#displayName');
      if (nameInput && this.prefs.displayName) nameInput.value = this.prefs.displayName;

      await this.startLobbyPreview();
      await this.refreshDeviceLists();

      if (navigator.mediaDevices) {
        navigator.mediaDevices.addEventListener('devicechange', () => this.refreshDeviceLists());
      }
    }

    renderClock() {
      const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
      const el = $('#localClock');
      if (el) el.textContent = time;
      const meetingClock = $('#meetingClock');
      if (meetingClock) meetingClock.textContent = time;
    }

    async startLobbyPreview() {
      const video = $('#lobbyPreview');
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        this.audioEnabled = false;
        this.videoEnabled = false;
        this.syncLobbyMediaButtons();
        notify('Camera/microphone not available in this browser. You can still join.', 'warning');
        return;
      }

      try {
        const stream = await Promise.race([
          navigator.mediaDevices.getUserMedia({
            audio: true,
            video: true,
          }),
          new Promise((_, reject) =>
            setTimeout(() => reject(new Error('Permission timeout')), 10000)
          ),
        ]);
        this.localStream = stream;
        this.audioEnabled = true;
        this.videoEnabled = true;
        if (video) {
          video.srcObject = stream;
          video.muted = true;
          await video.play().catch(() => {});
        }
        this.updateLobbyMeters();
      } catch (err) {
        // Try audio-only quickly, then allow join with no media.
        try {
          const audioOnly = await Promise.race([
            navigator.mediaDevices.getUserMedia({ audio: true }),
            new Promise((_, reject) => setTimeout(() => reject(err), 5000)),
          ]);
          this.localStream = audioOnly;
          this.audioEnabled = true;
          this.videoEnabled = false;
          notify('Camera unavailable. Microphone only for now.', 'warning');
          this.updateLobbyMeters();
        } catch (_) {
          this.audioEnabled = false;
          this.videoEnabled = false;
          notify('Unable to access camera/microphone. You can still join without media.', 'warning');
        }
      }
      this.syncLobbyMediaButtons();
    }

    updateLobbyMeters() {
      const meter = $('#micMeterFill');
      if (!meter || !this.localStream) return;
      try {
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const track = this.localStream.getAudioTracks()[0];
        if (!track) return;
        const source = ctx.createMediaStreamSource(new MediaStream([track]));
        const analyser = ctx.createAnalyser();
        source.connect(analyser);
        const data = new Uint8Array(analyser.frequencyBinCount);
        const tick = () => {
          if (!$('#lobbyView') || $('#lobbyView').hidden) {
            ctx.close();
            return;
          }
          analyser.getByteFrequencyData(data);
          let sum = 0;
          data.forEach((v) => (sum += v));
          const level = sum / data.length / 255;
          meter.style.width = `${Math.min(100, Math.round(level * 140))}%`;
          requestAnimationFrame(tick);
        };
        tick();
      } catch (_) {}
    }

    hasLiveVideoTrack() {
      return !!(
        this.localStream &&
        this.localStream.getVideoTracks().some((t) => t.readyState === 'live' && t.enabled)
      );
    }

    hasLiveAudioTrack() {
      return !!(
        this.localStream &&
        this.localStream.getAudioTracks().some((t) => t.readyState === 'live')
      );
    }

    syncLobbyMediaButtons() {
      const mic = $('#lobbyMicBtn');
      const cam = $('#lobbyCamBtn');
      if (mic) {
        mic.classList.toggle('is-off', !this.audioEnabled);
        const icon = mic.querySelector('i');
        if (icon) icon.className = this.audioEnabled ? 'fa-solid fa-microphone' : 'fa-solid fa-microphone-slash';
      }
      if (cam) {
        cam.classList.toggle('is-off', !this.videoEnabled);
        const icon = cam.querySelector('i');
        if (icon) icon.className = this.videoEnabled ? 'fa-solid fa-video' : 'fa-solid fa-video-slash';
      }
      if (this.localStream) {
        this.localStream.getAudioTracks().forEach((t) => {
          t.enabled = this.audioEnabled;
        });
        this.localStream.getVideoTracks().forEach((t) => {
          t.enabled = this.videoEnabled;
        });
      }

      const preview = $('#lobbyPreview');
      const empty = $('#previewEmpty');
      const showVideo = this.videoEnabled && this.hasLiveVideoTrack();

      if (preview) {
        preview.style.opacity = showVideo ? '1' : '0';
        if (this.localStream && preview.srcObject !== this.localStream) {
          preview.srcObject = this.localStream;
          preview.muted = true;
          preview.play().catch(() => {});
        }
      }
      if (empty) {
        empty.hidden = showVideo;
        const letter =
          ($('#displayName')?.value || this.prefs.displayName || '?').trim().charAt(0).toUpperCase() ||
          '?';
        const av = empty.querySelector('.preview-avatar');
        if (av) av.textContent = letter;
        const label = empty.querySelector('p');
        if (label) {
          label.textContent = this.videoEnabled && !showVideo ? 'Starting camera…' : 'Camera is off';
        }
      }
    }

    async ensureLobbyMedia({ audio = false, video = false } = {}) {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        throw new Error('Camera/microphone not available in this browser.');
      }

      const needAudio = audio && !this.hasLiveAudioTrack();
      const needVideo = video && !this.localStream?.getVideoTracks().some((t) => t.readyState === 'live');

      if (!needAudio && !needVideo) {
        if (this.localStream) {
          if (audio) this.localStream.getAudioTracks().forEach((t) => (t.enabled = true));
          if (video) this.localStream.getVideoTracks().forEach((t) => (t.enabled = true));
        }
        return this.localStream;
      }

      const stream = await navigator.mediaDevices.getUserMedia({
        audio: needAudio ? { echoCancellation: true, noiseSuppression: true } : false,
        video: needVideo ? { width: { ideal: 1280 }, height: { ideal: 720 } } : false,
      });

      if (!this.localStream) {
        this.localStream = stream;
      } else {
        stream.getTracks().forEach((track) => {
          this.localStream.addTrack(track);
        });
      }

      const preview = $('#lobbyPreview');
      if (preview) {
        preview.srcObject = this.localStream;
        preview.muted = true;
        await preview.play().catch(() => {});
      }
      if (audio) this.updateLobbyMeters();
      return this.localStream;
    }

    bindLobby() {
      $('#lobbyMicBtn')?.addEventListener('click', async () => {
        const turningOn = !this.audioEnabled;
        if (turningOn) {
          try {
            await this.ensureLobbyMedia({ audio: true });
            this.audioEnabled = true;
          } catch (err) {
            this.audioEnabled = false;
            notify(err.message || 'Could not access microphone.', 'error');
          }
        } else {
          this.audioEnabled = false;
        }
        this.syncLobbyMediaButtons();
      });

      $('#lobbyCamBtn')?.addEventListener('click', async () => {
        const turningOn = !this.videoEnabled;
        if (turningOn) {
          try {
            notify('Starting camera…', 'info');
            await this.ensureLobbyMedia({ video: true });
            this.videoEnabled = true;
          } catch (err) {
            this.videoEnabled = false;
            notify(
              err.message ||
                'Could not access camera. Close other apps using it, then try again.',
              'error'
            );
          }
        } else {
          this.videoEnabled = false;
        }
        this.syncLobbyMediaButtons();
      });

      $('#btnJoinNow')?.addEventListener('click', () => this.joinMeeting());
      $('#btnLobbySettings')?.addEventListener('click', () => this.openSettings());
    }

    async refreshDeviceLists() {
      const devices = await ConnectMeetSettings.enumerateMediaDevices();
      const fill = (selectId, list, selected) => {
        const sel = $(selectId);
        if (!sel) return;
        sel.innerHTML = list
          .map(
            (d, i) =>
              `<option value="${escapeHtml(d.deviceId)}" ${
                d.deviceId === selected ? 'selected' : ''
              }>${escapeHtml(d.label || `${d.kind} ${i + 1}`)}</option>`
          )
          .join('');
      };
      fill('#settingMic', devices.audioInputs, this.prefs.audioDeviceId);
      fill('#settingCamera', devices.videoInputs, this.prefs.videoDeviceId);
      fill('#settingSpeaker', devices.audioOutputs, this.prefs.audioOutputId);
      const sinkNote = $('#sinkSupportNote');
      if (sinkNote) {
        sinkNote.hidden = ConnectMeetSettings.supportsSinkId();
      }
    }

    openSettings() {
      const modal = $('#settingsModal');
      if (modal) modal.hidden = false;
      this.refreshDeviceLists();
    }

    closeSettings() {
      const modal = $('#settingsModal');
      if (modal) modal.hidden = true;
    }

    bindPanels() {
      $('#btnCloseSettings')?.addEventListener('click', () => this.closeSettings());
      $('#btnApplySettings')?.addEventListener('click', () => this.applySettings());
      $('#btnTestSpeaker')?.addEventListener('click', () => {
        ConnectMeetSettings.playTestTone($('#settingSpeaker')?.value);
      });

      $$('[data-close-panel]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const id = btn.getAttribute('data-close-panel');
          const panel = document.getElementById(id);
          if (panel) panel.classList.remove('open');
        });
      });

      $('#btnChat')?.addEventListener('click', () => $('#chatPanel')?.classList.toggle('open'));
      $('#btnPeople')?.addEventListener('click', () => $('#peoplePanel')?.classList.toggle('open'));
      $('#btnInfo')?.addEventListener('click', () => $('#infoPanel')?.classList.toggle('open'));
      $('#btnCopyLink')?.addEventListener('click', () => this.copyLink());
      $('#chatForm')?.addEventListener('submit', (e) => {
        e.preventDefault();
        this.sendChat();
      });
      $$('[data-reaction]').forEach((btn) => {
        btn.addEventListener('click', () => this.sendReaction(btn.getAttribute('data-reaction')));
      });
    }

    async applySettings() {
      const displayName = ($('#settingDisplayName')?.value || '').trim();
      const audioDeviceId = $('#settingMic')?.value || '';
      const videoDeviceId = $('#settingCamera')?.value || '';
      const audioOutputId = $('#settingSpeaker')?.value || '';

      this.prefs = {
        ...this.prefs,
        displayName: displayName || this.prefs.displayName,
        audioDeviceId,
        videoDeviceId,
        audioOutputId,
      };
      ConnectMeetSettings.savePrefs(this.prefs);

      if ($('#displayName') && displayName) $('#displayName').value = displayName;

      if (this.webrtc && this.participant) {
        try {
          await this.webrtc.switchDevices({
            audioDeviceId,
            videoDeviceId,
            audio: this.audioEnabled,
            video: this.videoEnabled,
          });
          this.localStream = this.webrtc.localStream;
          this.renderVideos();
        } catch (err) {
          notify(err.message, 'error');
        }
      } else if (this.localStream) {
        this.localStream.getTracks().forEach((t) => t.stop());
        await this.startLobbyPreview();
      }

      $$('video').forEach((v) => {
        if (audioOutputId) ConnectMeetSettings.applyAudioOutput(v, audioOutputId);
      });

      this.closeSettings();
      notify('Settings saved', 'success');
    }

    async joinMeeting() {
      const displayName = ($('#displayName')?.value || '').trim();
      if (displayName.length < 2) {
        notify('Please enter a display name (at least 2 characters).', 'warning');
        return;
      }

      if (this._joining) return;
      this._joining = true;

      this.prefs.displayName = displayName;
      this.prefs.audioEnabled = this.audioEnabled;
      this.prefs.videoEnabled = this.videoEnabled;
      ConnectMeetSettings.savePrefs(this.prefs);

      const hostToken = sessionStorage.getItem('cm_host_token_' + this.config.meetingCode) || '';
      const btn = $('#btnJoinNow');
      if (btn) {
        btn.disabled = true;
        btn.textContent = 'Joining…';
      }

      try {
        const res = await this.signaling.joinMeeting({
          meeting_code: this.config.meetingCode,
          display_name: displayName,
          audio_enabled: this.audioEnabled,
          video_enabled: this.videoEnabled,
          host_token: hostToken,
        });

        this.participantToken = res.data.participant_token;
        this.participant = res.data.participant;
        this.meeting = res.data.meeting;
        this.isHost = !!res.data.is_host;
        this.signaling.setTokens({ participantToken: this.participantToken });

        this.webrtc = new ConnectMeetWebRTC({
          iceServers: this.config.iceServers,
          signaling: this.signaling,
          localParticipantId: this.participant.id,
          onLocalStream: (stream) => {
            this.localStream = stream;
            this.renderVideos();
          },
          onRemoteStream: (id, stream) => {
            this.remoteStreams.set(Number(id), stream);
            this.renderVideos();
          },
          onPeerState: (id, state) => {
            this.setConnectionStatus(state === 'connected' ? 'connected' : state);
          },
          onError: (err) => notify(err.message || String(err), 'warning'),
        });

        // Enter the meeting UI immediately so a stuck camera/mic prompt
        // cannot leave the user forever on "Joining…".
        if (this.localStream) {
          this.localStream.getTracks().forEach((t) => t.stop());
          this.localStream = null;
        }

        this.enterMeetingUI();
        if (btn) {
          btn.disabled = false;
          btn.textContent = 'Join now';
        }
        this.startPolling();
        this.startSpeakingLoop();
        this.setConnectionStatus('connected');
        notify('You joined the meeting', 'success');

        // Do not block UI on media permission
        this.setupLocalMediaSafe().catch(() => {});
      } catch (err) {
        notify(err.message || 'Could not join the meeting.', 'error');
        if (btn) {
          btn.disabled = false;
          btn.textContent = 'Join now';
        }
      } finally {
        this._joining = false;
      }
    }

    /**
     * Acquire camera/mic without blocking join. Times out so permission
     * dialogs cannot freeze the UI forever.
     */
    async setupLocalMediaSafe() {
      if (!this.webrtc) return;

      const wantAudio = this.audioEnabled;
      const wantVideo = this.videoEnabled;

      if (!wantAudio && !wantVideo) {
        this.localStream = new MediaStream();
        this.webrtc.localStream = this.localStream;
        this.renderVideos();
        return;
      }

      try {
        await Promise.race([
          this.webrtc.getLocalMedia({
            audio: wantAudio,
            video: wantVideo,
            audioDeviceId: this.prefs.audioDeviceId,
            videoDeviceId: this.prefs.videoDeviceId,
          }),
          new Promise((_, reject) =>
            setTimeout(() => reject(new Error('Camera/microphone permission timed out. You are in the meeting without media.')), 12000)
          ),
        ]);
        this.webrtc.setAudioEnabled(this.audioEnabled);
        this.webrtc.setVideoEnabled(this.videoEnabled);
        this.syncControlButtons();
        this.renderVideos();
      } catch (err) {
        this.audioEnabled = false;
        this.videoEnabled = false;
        if (!this.webrtc.localStream) {
          this.webrtc.localStream = new MediaStream();
          this.localStream = this.webrtc.localStream;
        }
        this.syncControlButtons();
        this.renderVideos();
        notify(err.message || 'Joined without camera/microphone.', 'warning');
        try {
          await this.signaling.updateMedia({
            audio_enabled: false,
            video_enabled: false,
          });
        } catch (_) {}
      }
    }

    enterMeetingUI() {
      $('#lobbyView').hidden = true;
      $('#meetingView').hidden = false;
      document.body.classList.add('in-meeting');
      $('#meetingCodeLabel').textContent = this.meeting.meeting_code;
      $('#meetingTitleLabel').textContent = this.meeting.title;
      $('#infoMeetingCode').textContent = this.meeting.meeting_code;
      $('#infoJoinUrl').textContent = this.meeting.join_url;
      $$('.host-only').forEach((el) => {
        el.hidden = !this.isHost;
      });
      this.syncControlButtons();
      this.renderVideos();
    }

    startPolling() {
      const p = this.config.polling || {};
      this.signaling.startPoller('participants', p.participants_ms || 2000, () => this.pollParticipants());
      this.signaling.startPoller('signaling', p.signaling_ms || 800, () => this.pollSignals());
      this.signaling.startPoller('events', p.events_ms || 1200, () => this.pollEvents());
      this.signaling.startPoller('chat', p.chat_ms || 1500, () => this.pollChat());
      this.signaling.startPoller('heartbeat', p.heartbeat_ms || 5000, () => this.pollHeartbeat());

      this.signaling.onPollError = (name, err) => {
        if (err.status === 410 || (err.data && err.data.meeting_ended)) {
          this.forceEnd('The host ended this meeting.');
          return;
        }
        if (name === 'heartbeat') this.setConnectionStatus('reconnecting');
      };
    }

    async pollParticipants() {
      const res = await this.signaling.listParticipants();
      this.participants = res.data.participants || [];
      $('#participantCount').textContent = String(res.data.count || this.participants.length);
      this.renderPeopleList();
      this.renderVideos();
      if (this.webrtc) {
        await this.webrtc.syncPeers(this.participants.map((p) => p.id));
      }
    }

    async pollSignals() {
      const res = await this.signaling.pollSignals();
      const signals = res.data.signals || [];
      for (const signal of signals) {
        this.signaling.rememberSignalId(signal.id);
        if (this.webrtc) await this.webrtc.handleSignal(signal);
      }
      if (res.data.last_id) this.signaling.rememberSignalId(res.data.last_id);
    }

    async pollEvents() {
      const res = await this.signaling.pollEvents();
      for (const event of res.data.events || []) {
        this.signaling.rememberEventId(event.id);
        if (!this.signaling.isNewEvent(event.id)) continue;
        this.handleEvent(event);
      }
      if (res.data.last_id) this.signaling.rememberEventId(res.data.last_id);
    }

    async pollChat() {
      const res = await this.signaling.pollChat();
      for (const msg of res.data.messages || []) {
        this.signaling.rememberMessageId(msg.id);
        this.appendChat(msg);
      }
      if (res.data.last_id) this.signaling.rememberMessageId(res.data.last_id);
    }

    async pollHeartbeat() {
      const res = await this.signaling.heartbeat();
      this.setConnectionStatus('connected');
      if (res.data.participant_count != null) {
        $('#participantCount').textContent = String(res.data.participant_count);
      }
    }

    handleEvent(event) {
      const type = event.event_type;
      const payload = event.payload || {};
      if (type === 'meeting_ended') {
        this.forceEnd('The host ended this meeting.');
      } else if (type === 'participant_removed' && payload.participant_id === this.participant.id) {
        this.forceEnd('You were removed from the meeting.');
      } else if (type === 'reaction') {
        this.spawnReaction(payload.emoji, payload.display_name);
      } else if (type === 'hand_raised' || type === 'hand_lowered') {
        this.pollParticipants().catch(() => {});
      } else if (type === 'media_state' || type === 'participant_joined' || type === 'participant_left') {
        this.pollParticipants().catch(() => {});
      } else if (type === 'screen_share_started') {
        notify(`${payload.display_name || 'Someone'} started sharing their screen`, 'info');
      } else if (type === 'meeting_locked') {
        notify('Meeting locked', 'warning');
      } else if (type === 'meeting_unlocked') {
        notify('Meeting unlocked', 'info');
      }
    }

    getParticipantStream(p) {
      if (p.local) {
        if (this.webrtc && this.webrtc.isSharing && this.webrtc.screenStream) {
          return this.webrtc.screenStream;
        }
        return this.localStream;
      }
      return this.remoteStreams.get(Number(p.id)) || null;
    }

    buildTileModelList() {
      const local = this.participant
        ? {
            id: this.participant.id,
            display_name: this.participant.display_name + ' (You)',
            audio_enabled: this.audioEnabled,
            video_enabled: this.videoEnabled && !(this.webrtc && this.webrtc.isSharing),
            hand_raised: this.handRaised,
            is_sharing_screen: !!(this.webrtc && this.webrtc.isSharing),
            local: true,
          }
        : null;

      const all = [];
      if (local) all.push(local);
      this.participants
        .filter((p) => !local || p.id !== local.id)
        .forEach((p) => all.push({ ...p, local: false }));
      return all;
    }

    /**
     * Update video tiles in place. Avoid innerHTML rebuilds that flash the camera.
     */
    renderVideos() {
      const grid = $('#videoGrid');
      if (!grid) return;

      const all = this.buildTileModelList();
      grid.dataset.count = String(all.length || 1);

      const keep = new Set(all.map((p) => String(p.id)));
      $$('.video-tile', grid).forEach((tile) => {
        if (!keep.has(tile.getAttribute('data-pid'))) tile.remove();
      });

      all.forEach((p) => {
        const stream = this.getParticipantStream(p);
        const showVideo = p.local
          ? !!(
              stream &&
              (p.video_enabled || p.is_sharing_screen) &&
              stream.getVideoTracks().some((t) => t.readyState === 'live' && (t.enabled || p.is_sharing_screen))
            )
          : !!(
              stream &&
              p.video_enabled &&
              stream.getVideoTracks().some((t) => t.readyState === 'live')
            );

        let tile = grid.querySelector(`.video-tile[data-pid="${p.id}"]`);
        if (!tile) {
          tile = document.createElement('div');
          tile.className = 'video-tile' + (p.local ? ' local' : '');
          tile.dataset.pid = String(p.id);
          tile.innerHTML = `
            <video playsinline ${p.local ? 'muted' : ''} autoplay></video>
            <div class="avatar"></div>
            <div class="tile-meta">
              <span class="mute-badge badges" hidden><i class="fa-solid fa-microphone-slash"></i></span>
              <span class="name"></span>
              <span class="extra-badges badges"></span>
            </div>
          `;
          grid.appendChild(tile);
        }

        tile.classList.toggle('local', !!p.local);
        tile.classList.toggle('hand-up', !!p.hand_raised);
        tile.classList.toggle('sharing', !!p.is_sharing_screen);

        const avatar = tile.querySelector('.avatar');
        const nameEl = tile.querySelector('.name');
        const muteBadge = tile.querySelector('.mute-badge');
        const extra = tile.querySelector('.extra-badges');
        const video = tile.querySelector('video');

        if (avatar) {
          avatar.textContent = (p.display_name || '?').replace(/\s*\(You\)\s*/i, '').charAt(0).toUpperCase();
          avatar.classList.toggle('hidden', showVideo);
        }
        if (nameEl) nameEl.textContent = p.display_name || 'Guest';
        if (muteBadge) muteBadge.hidden = !!p.audio_enabled;
        if (extra) {
          extra.innerHTML =
            (p.hand_raised ? '<i class="fa-solid fa-hand"></i>' : '') +
            (p.is_sharing_screen ? '<i class="fa-solid fa-desktop"></i>' : '');
        }

        if (video) {
          if (stream && video.srcObject !== stream) {
            video.srcObject = stream;
            video.play().catch(() => {});
            if (!p.local && this.prefs.audioOutputId) {
              ConnectMeetSettings.applyAudioOutput(video, this.prefs.audioOutputId);
            }
          } else if (!stream && video.srcObject) {
            video.srcObject = null;
          }
        }
      });
    }

    renderPeopleList() {
      const list = $('#peopleList');
      if (!list) return;
      list.innerHTML = this.participants
        .map((p) => {
          const you = this.participant && p.id === this.participant.id;
          return `<li>
            <div>
              <strong>${escapeHtml(p.display_name)}${you ? ' (You)' : ''}</strong>
              <small>${p.role === 'host' ? 'Host' : 'Participant'}</small>
            </div>
            <div class="people-icons">
              ${p.audio_enabled ? '<i class="fa-solid fa-microphone"></i>' : '<i class="fa-solid fa-microphone-slash"></i>'}
              ${p.video_enabled ? '<i class="fa-solid fa-video"></i>' : '<i class="fa-solid fa-video-slash"></i>'}
              ${p.hand_raised ? '<i class="fa-solid fa-hand"></i>' : ''}
              ${
                this.isHost && !you && p.role !== 'host'
                  ? `<button type="button" class="text-btn" data-remove="${p.id}">Remove</button>`
                  : ''
              }
            </div>
          </li>`;
        })
        .join('');

      $$('[data-remove]', list).forEach((btn) => {
        btn.addEventListener('click', async () => {
          try {
            await this.signaling.removeParticipant(Number(btn.getAttribute('data-remove')));
            notify('Participant removed', 'success');
          } catch (err) {
            notify(err.message, 'error');
          }
        });
      });
    }

    bindMeetingControls() {
      $('#btnToggleMic')?.addEventListener('click', () => this.toggleMic());
      $('#btnToggleCam')?.addEventListener('click', () => this.toggleCam());
      $('#btnShare')?.addEventListener('click', () => this.toggleShare());
      $('#btnHand')?.addEventListener('click', () => this.toggleHand());
      $('#btnCaptions')?.addEventListener('click', () => this.toggleCaptions());
      $('#btnRecord')?.addEventListener('click', () => this.toggleRecord());
      $('#btnLeave')?.addEventListener('click', () => this.leave(false));
      $('#btnEndMeeting')?.addEventListener('click', () => this.leave(true));
      $('#btnLockMeeting')?.addEventListener('click', async () => {
        try {
          const locked = !this.meeting.is_locked;
          await this.signaling.lockMeeting(locked);
          this.meeting.is_locked = locked;
          notify(locked ? 'Meeting locked' : 'Meeting unlocked', 'success');
        } catch (err) {
          notify(err.message, 'error');
        }
      });
      $('#btnSettings')?.addEventListener('click', () => this.openSettings());
      window.addEventListener('beforeunload', () => {
        if (this.participantToken && !this._left) {
          const payload = JSON.stringify({ action: 'leave' });
          navigator.sendBeacon?.(
            this.config.baseUrl + '/api/participants.php',
            new Blob([payload], { type: 'application/json' })
          );
        }
      });
    }

    syncControlButtons() {
      const mic = $('#btnToggleMic');
      const cam = $('#btnToggleCam');
      mic?.classList.toggle('is-off', !this.audioEnabled);
      cam?.classList.toggle('is-off', !this.videoEnabled);
      const micIcon = mic?.querySelector('i');
      const camIcon = cam?.querySelector('i');
      if (micIcon) micIcon.className = this.audioEnabled ? 'fa-solid fa-microphone' : 'fa-solid fa-microphone-slash';
      if (camIcon) camIcon.className = this.videoEnabled ? 'fa-solid fa-video' : 'fa-solid fa-video-slash';
      $('#btnHand')?.classList.toggle('active', this.handRaised);
      $('#btnShare')?.classList.toggle('active', !!(this.webrtc && this.webrtc.isSharing));
      $('#btnCaptions')?.classList.toggle('active', !!$('#captionsPanel')?.classList.contains('open'));
      const badge = $('#recordingBadge');
      if (badge) badge.hidden = !this.recorder.recording;
    }

    async toggleMic() {
      const turningOn = !this.audioEnabled;
      try {
        if (turningOn) {
          if (!this.webrtc) throw new Error('Not in a meeting yet.');
          notify('Requesting microphone…', 'info');
          await this.webrtc.ensureMedia({ audio: true, video: false });
          this.localStream = this.webrtc.localStream;
          this.audioEnabled = true;
        } else {
          this.audioEnabled = false;
          this.webrtc?.setAudioEnabled(false);
        }
        this.syncControlButtons();
        this.renderVideos();
        await this.signaling.updateMedia({
          audio_enabled: this.audioEnabled,
          video_enabled: this.videoEnabled,
        });
      } catch (err) {
        this.audioEnabled = false;
        this.syncControlButtons();
        notify(err.message || 'Could not access microphone. Check browser permission.', 'error');
      }
    }

    async toggleCam() {
      const turningOn = !this.videoEnabled;
      try {
        if (turningOn) {
          if (!this.webrtc) throw new Error('Not in a meeting yet.');
          notify('Requesting camera…', 'info');
          await this.webrtc.ensureMedia({ audio: false, video: true });
          this.localStream = this.webrtc.localStream;
          this.videoEnabled = true;
        } else {
          this.videoEnabled = false;
          this.webrtc?.setVideoEnabled(false);
        }
        this.syncControlButtons();
        this.renderVideos();
        await this.signaling.updateMedia({
          audio_enabled: this.audioEnabled,
          video_enabled: this.videoEnabled,
        });
      } catch (err) {
        this.videoEnabled = false;
        this.syncControlButtons();
        this.renderVideos();
        notify(err.message || 'Could not access camera. Check browser permission.', 'error');
      }
    }

    async toggleShare() {
      if (!this.webrtc) return;
      try {
        if (this.webrtc.isSharing) {
          await this.webrtc.stopScreenShare();
          await this.signaling.setScreenShare(false);
          notify('Screen sharing stopped', 'info');
        } else {
          await this.webrtc.startScreenShare();
          await this.signaling.setScreenShare(true);
          notify('You are sharing your screen', 'success');
        }
        this.syncControlButtons();
        this.renderVideos();
      } catch (err) {
        notify(err.message, 'error');
      }
    }

    async toggleHand() {
      this.handRaised = !this.handRaised;
      this.syncControlButtons();
      try {
        await this.signaling.raiseHand(this.handRaised);
      } catch (err) {
        this.handRaised = !this.handRaised;
        this.syncControlButtons();
        notify(err.message, 'error');
      }
      this.renderVideos();
    }

    toggleCaptions() {
      const panel = $('#captionsPanel');
      if (!this.captions) {
        this.captions = new ConnectMeetSettings.CaptionService({
          onResult: ({ interim, final }) => {
            const el = $('#captionsText');
            if (!el) return;
            el.textContent = final || interim || '';
          },
          onError: (err) => notify(err.message || 'Captions unavailable', 'warning'),
        });
      }
      if (!this.captions.supported) {
        notify(
          'Live captions use the browser Web Speech API (best on Chrome/Edge) and only caption your own microphone audio — not remote participants.',
          'warning'
        );
        return;
      }
      if (panel.classList.contains('open')) {
        this.captions.stop();
        panel.classList.remove('open');
        $('#btnCaptions')?.classList.remove('active');
      } else {
        if (this.captions.start()) {
          panel.classList.add('open');
          $('#btnCaptions')?.classList.add('active');
        }
      }
    }

    async toggleRecord() {
      try {
        if (this.recorder.recording) {
          const blob = await this.recorder.stop();
          this.syncControlButtons();
          if (blob) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `connectmeet-${this.meeting.meeting_code}-${Date.now()}.webm`;
            a.click();
            URL.revokeObjectURL(url);
            notify('Local recording saved. This records your browser mix only, not a server composite.', 'success');
          }
        } else {
          const streams = [this.localStream, ...this.remoteStreams.values()].filter(Boolean);
          if (!streams.length) throw new Error('No media to record');
          // Mix first available video + all audio tracks into a single stream for MediaRecorder.
          const mixed = new MediaStream();
          streams.forEach((s) => s.getTracks().forEach((t) => mixed.addTrack(t)));
          this.recorder.start(mixed);
          this.syncControlButtons();
          notify('Local recording started', 'warning');
        }
      } catch (err) {
        notify(err.message, 'error');
      }
    }

    async sendChat() {
      const input = $('#chatInput');
      const text = (input?.value || '').trim();
      if (!text) return;
      try {
        const res = await this.signaling.sendChat(text);
        this.appendChat(res.data.message);
        this.signaling.rememberMessageId(res.data.message.id);
        input.value = '';
      } catch (err) {
        notify(err.message, 'error');
      }
    }

    appendChat(msg) {
      const box = $('#chatMessages');
      if (!box || box.querySelector(`[data-mid="${msg.id}"]`)) return;
      const el = document.createElement('div');
      el.className = 'chat-item';
      el.dataset.mid = msg.id;
      const time = msg.created_at ? new Date(msg.created_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';
      el.innerHTML = `<div class="chat-meta"><strong>${escapeHtml(msg.display_name)}</strong><span>${escapeHtml(time)}</span></div>
        <div class="chat-body">${escapeHtml(msg.message_content)}</div>`;
      box.appendChild(el);
      box.scrollTop = box.scrollHeight;
    }

    async sendReaction(emoji) {
      try {
        await this.signaling.sendReaction(emoji);
        this.spawnReaction(emoji, 'You');
      } catch (err) {
        notify(err.message, 'error');
      }
    }

    spawnReaction(emoji, name) {
      const host = $('#reactionLayer');
      if (!host) return;
      const el = document.createElement('div');
      el.className = 'floating-reaction';
      el.textContent = `${emoji} ${name || ''}`;
      el.style.left = `${20 + Math.random() * 60}%`;
      host.appendChild(el);
      setTimeout(() => el.remove(), 2500);
    }

    async copyLink() {
      const url = this.meeting?.join_url || window.location.href;
      try {
        await navigator.clipboard.writeText(url);
        notify('Meeting link copied', 'success');
      } catch (_) {
        notify(url, 'info');
      }
    }

    setConnectionStatus(status) {
      this.connectionStatus = status;
      const el = $('#connectionStatus');
      if (!el) return;
      el.dataset.status = status;
      const labels = {
        idle: 'Idle',
        connected: 'Connected',
        connecting: 'Connecting…',
        reconnecting: 'Reconnecting…',
        failed: 'Connection issue',
        disconnected: 'Disconnected',
      };
      const label = labels[status] || status;
      if (el.tagName === 'SPAN' && el.classList.contains('status-dot')) {
        el.title = label;
        el.setAttribute('aria-label', label);
      } else {
        el.textContent = label;
      }
    }

    startSpeakingLoop() {
      this._speakingTimer = setInterval(() => {
        if (!this.webrtc || !this.audioEnabled) return;
        const speaking = this.webrtc.isLocalSpeaking();
        const tile = $(`.video-tile[data-pid="${this.participant.id}"]`);
        if (tile) tile.classList.toggle('speaking', speaking);
      }, 200);
    }

    bindShortcuts() {
      document.addEventListener('keydown', (e) => {
        if (e.target.matches('input, textarea')) return;
        if (!$('#meetingView') || $('#meetingView').hidden) return;
        if (e.key === 'm' || e.key === 'M') this.toggleMic();
        if (e.key === 'v' || e.key === 'V') this.toggleCam();
        if (e.key === 'c' || e.key === 'C') $('#chatPanel')?.classList.toggle('open');
        if (e.key === 'h' || e.key === 'H') this.toggleHand();
        if (e.key === 'Escape') {
          $$('.side-panel.open').forEach((p) => p.classList.remove('open'));
          this.closeSettings();
        }
      });

      $('#btnHelp')?.addEventListener('click', () => {
        $('#helpModal').hidden = false;
      });
      $('#btnCloseHelp')?.addEventListener('click', () => {
        $('#helpModal').hidden = true;
      });
    }

    async leave(endForAll) {
      if (this._left) return;
      this._left = true;
      try {
        if (endForAll && this.isHost) {
          await this.signaling.endMeeting();
        } else {
          await this.signaling.leaveMeeting();
        }
      } catch (_) {}
      this.cleanup();
      window.location.href = this.config.baseUrl + '/index.php';
    }

    forceEnd(message) {
      if (this._left) return;
      notify(message, 'warning');
      this._left = true;
      this.cleanup();
      setTimeout(() => {
        window.location.href = this.config.baseUrl + '/index.php';
      }, 1200);
    }

    cleanup() {
      this.signaling.stopAllPollers();
      if (this._speakingTimer) clearInterval(this._speakingTimer);
      if (this.captions) this.captions.stop();
      if (this.recorder.recording) this.recorder.stop();
      if (this.webrtc) this.webrtc.stopAll();
      else if (this.localStream) this.localStream.getTracks().forEach((t) => t.stop());
    }
  }

  global.ConnectMeetApp = MeetingApp;
  global.ConnectMeetNotify = notify;
})(window);
