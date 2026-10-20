/**
 * ConnectMeet signaling client — REST JSON over HTTP with short polling.
 */
(function (global) {
  'use strict';

  class SignalingClient {
    constructor(options = {}) {
      this.baseUrl = (options.baseUrl || '').replace(/\/$/, '');
      this.csrfToken = options.csrfToken || '';
      this.participantToken = options.participantToken || '';
      this.timeoutMs = options.timeoutMs || 10000;
      this._pollers = {};
      this._backoff = {};
      this._seenEventIds = new Set();
      this._lastIds = {
        signals: 0,
        events: 0,
        messages: 0,
      };
    }

    setTokens({ csrfToken, participantToken } = {}) {
      if (csrfToken) this.csrfToken = csrfToken;
      if (participantToken) this.participantToken = participantToken;
    }

    async request(path, { action, method = 'POST', body = {}, query = {} } = {}) {
      const url = new URL(this.baseUrl + path, window.location.origin);
      const q = { ...query };
      if (action && method === 'GET') q.action = action;
      Object.entries(q).forEach(([k, v]) => {
        if (v !== undefined && v !== null) url.searchParams.set(k, String(v));
      });

      const payload = { ...body };
      if (action && method !== 'GET') payload.action = action;

      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), this.timeoutMs);

      const headers = {
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      };
      if (this.csrfToken) headers['X-CSRF-Token'] = this.csrfToken;
      if (this.participantToken) headers['X-Participant-Token'] = this.participantToken;

      try {
        const res = await fetch(url.toString(), {
          method,
          headers,
          credentials: 'same-origin',
          body: method === 'GET' ? undefined : JSON.stringify(payload),
          signal: controller.signal,
        });

        let data;
        try {
          data = await res.json();
        } catch (_) {
          throw new Error('Invalid server response');
        }

        if (!res.ok || !data.success) {
          const err = new Error(data.message || `Request failed (${res.status})`);
          err.status = res.status;
          err.data = data.data || null;
          throw err;
        }
        return data;
      } finally {
        clearTimeout(timer);
      }
    }

    bootstrap() {
      return this.request('/api/meetings.php', { action: 'bootstrap', method: 'POST', body: {} });
    }

    createMeeting(body) {
      return this.request('/api/meetings.php', { action: 'create', body });
    }

    validateMeeting(meetingCode) {
      return this.request('/api/meetings.php', {
        action: 'validate',
        method: 'GET',
        query: { meeting_code: meetingCode },
      });
    }

    joinMeeting(body) {
      return this.request('/api/participants.php', { action: 'join', body });
    }

    leaveMeeting() {
      return this.request('/api/participants.php', { action: 'leave', body: {} });
    }

    heartbeat() {
      return this.request('/api/participants.php', { action: 'heartbeat', body: {} });
    }

    listParticipants() {
      return this.request('/api/participants.php', { action: 'list', method: 'GET' });
    }

    updateMedia(state) {
      return this.request('/api/participants.php', { action: 'update_media', body: state });
    }

    raiseHand(raised) {
      return this.request('/api/participants.php', {
        action: raised ? 'raise_hand' : 'lower_hand',
        body: {},
      });
    }

    removeParticipant(targetId) {
      return this.request('/api/participants.php', {
        action: 'remove',
        body: { target_participant_id: targetId },
      });
    }

    setScreenShare(isSharing) {
      return this.request('/api/participants.php', {
        action: 'screen_share',
        body: { is_sharing: isSharing },
      });
    }

    lockMeeting(lock) {
      return this.request('/api/meetings.php', {
        action: lock ? 'lock' : 'unlock',
        body: {},
      });
    }

    endMeeting() {
      return this.request('/api/meetings.php', { action: 'end', body: {} });
    }

    sendSignal(toParticipantId, signalType, payload) {
      return this.request('/api/signaling.php', {
        action: 'send',
        body: {
          to_participant_id: toParticipantId,
          signal_type: signalType,
          payload,
        },
      });
    }

    pollSignals() {
      return this.request('/api/signaling.php', {
        action: 'poll',
        method: 'GET',
        query: { after_id: this._lastIds.signals },
      });
    }

    pollEvents() {
      return this.request('/api/events.php', {
        action: 'poll',
        method: 'GET',
        query: { after_id: this._lastIds.events },
      });
    }

    sendReaction(emoji) {
      return this.request('/api/events.php', { action: 'reaction', body: { emoji } });
    }

    sendChat(message) {
      return this.request('/api/chat.php', { action: 'send', body: { message } });
    }

    pollChat() {
      return this.request('/api/chat.php', {
        action: 'poll',
        method: 'GET',
        query: { after_id: this._lastIds.messages },
      });
    }

    startPoller(name, intervalMs, fn) {
      this.stopPoller(name);
      this._backoff[name] = intervalMs;
      let inFlight = false;
      let active = true;

      const loop = async () => {
        if (!active) return;
        if (!inFlight) {
          inFlight = true;
          try {
            await fn();
            this._backoff[name] = intervalMs;
          } catch (err) {
            this._backoff[name] = Math.min((this._backoff[name] || intervalMs) * 1.5, 15000);
            if (typeof this.onPollError === 'function') {
              this.onPollError(name, err);
            }
          } finally {
            inFlight = false;
          }
        }
        if (!active) return;
        this._pollers[name] = setTimeout(loop, this._backoff[name] || intervalMs);
      };

      this._pollers[name] = setTimeout(loop, 0);
      this._pollers[name + '_stop'] = () => {
        active = false;
      };
    }

    stopPoller(name) {
      if (typeof this._pollers[name + '_stop'] === 'function') {
        this._pollers[name + '_stop']();
        delete this._pollers[name + '_stop'];
      }
      if (this._pollers[name]) {
        clearTimeout(this._pollers[name]);
        delete this._pollers[name];
      }
    }

    stopAllPollers() {
      Object.keys(this._pollers).forEach((n) => this.stopPoller(n));
    }

    rememberSignalId(id) {
      if (id > this._lastIds.signals) this._lastIds.signals = id;
    }

    rememberEventId(id) {
      if (id > this._lastIds.events) this._lastIds.events = id;
    }

    rememberMessageId(id) {
      if (id > this._lastIds.messages) this._lastIds.messages = id;
    }

    isNewEvent(id) {
      if (this._seenEventIds.has(id)) return false;
      this._seenEventIds.add(id);
      if (this._seenEventIds.size > 500) {
        const arr = Array.from(this._seenEventIds).slice(-250);
        this._seenEventIds = new Set(arr);
      }
      return true;
    }
  }

  global.ConnectMeetSignaling = SignalingClient;
})(window);
