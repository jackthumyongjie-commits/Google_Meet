/**
 * Device settings, preferences, captions helpers, local recording helpers.
 */
(function (global) {
  'use strict';

  const STORAGE_KEY = 'connectmeet_prefs_v1';
  // Per-tab identity so two windows can join as different people while testing.
  const TAB_NAME_KEY = 'connectmeet_display_name_v1';

  function loadTabDisplayName() {
    try {
      return String(sessionStorage.getItem(TAB_NAME_KEY) || '').trim();
    } catch (_) {
      return '';
    }
  }

  function saveTabDisplayName(name) {
    try {
      const clean = String(name || '').trim();
      if (clean) sessionStorage.setItem(TAB_NAME_KEY, clean);
      else sessionStorage.removeItem(TAB_NAME_KEY);
    } catch (_) {}
  }

  function loadPrefs() {
    let shared = {};
    try {
      shared = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {};
    } catch (_) {
      shared = {};
    }
    // Never reuse a shared display name across tabs.
    delete shared.displayName;
    const tabName = loadTabDisplayName();
    if (tabName) shared.displayName = tabName;
    return shared;
  }

  function savePrefs(prefs) {
    const safe = { ...prefs };
    delete safe.participant_token;
    delete safe.host_token;
    if (Object.prototype.hasOwnProperty.call(safe, 'displayName')) {
      saveTabDisplayName(safe.displayName);
      delete safe.displayName;
    }
    // Drop any legacy shared name left in localStorage.
    try {
      const existing = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}') || {};
      delete existing.displayName;
      localStorage.setItem(STORAGE_KEY, JSON.stringify({ ...existing, ...safe }));
    } catch (_) {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(safe));
    }
  }

  async function enumerateMediaDevices() {
    if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
      return { audioInputs: [], videoInputs: [], audioOutputs: [] };
    }
    const devices = await navigator.mediaDevices.enumerateDevices();
    return {
      audioInputs: devices.filter((d) => d.kind === 'audioinput'),
      videoInputs: devices.filter((d) => d.kind === 'videoinput'),
      audioOutputs: devices.filter((d) => d.kind === 'audiooutput'),
    };
  }

  function supportsSinkId() {
    return typeof HTMLMediaElement !== 'undefined' && 'setSinkId' in HTMLMediaElement.prototype;
  }

  async function applyAudioOutput(element, deviceId) {
    if (!element || !deviceId || !supportsSinkId()) return false;
    try {
      await element.setSinkId(deviceId);
      return true;
    } catch (_) {
      return false;
    }
  }

  class CaptionService {
    constructor({ onResult, onError } = {}) {
      const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
      this.supported = !!SpeechRecognition;
      this._recognition = null;
      this._active = false;
      this.onResult = onResult || (() => {});
      this.onError = onError || (() => {});
      if (this.supported) {
        this._recognition = new SpeechRecognition();
        this._recognition.continuous = true;
        this._recognition.interimResults = true;
        this._recognition.lang = (loadPrefs().captionLang || navigator.language || 'en-US');
        this._recognition.onresult = (event) => {
          let interim = '';
          let finalText = '';
          for (let i = event.resultIndex; i < event.results.length; i++) {
            const transcript = event.results[i][0].transcript;
            if (event.results[i].isFinal) finalText += transcript;
            else interim += transcript;
          }
          this.onResult({ interim, final: finalText });
        };
        this._recognition.onerror = (e) => this.onError(e);
        this._recognition.onend = () => {
          if (this._active) {
            try {
              this._recognition.start();
            } catch (_) {}
          }
        };
      }
    }

    start() {
      if (!this.supported) {
        this.onError(new Error('Live captions require Chrome/Edge Web Speech API support. Recognition stays local to this browser and only captions your own speech.'));
        return false;
      }
      this._active = true;
      try {
        this._recognition.start();
        return true;
      } catch (err) {
        this.onError(err);
        return false;
      }
    }

    stop() {
      this._active = false;
      if (this._recognition) {
        try {
          this._recognition.stop();
        } catch (_) {}
      }
    }
  }

  class LocalRecorder {
    constructor() {
      this.recorder = null;
      this.chunks = [];
      this.supported = typeof MediaRecorder !== 'undefined';
      this.recording = false;
    }

    pickMimeType() {
      const types = [
        'video/webm;codecs=vp9,opus',
        'video/webm;codecs=vp8,opus',
        'video/webm',
      ];
      for (const t of types) {
        if (MediaRecorder.isTypeSupported(t)) return t;
      }
      return '';
    }

    start(stream) {
      if (!this.supported) throw new Error('Recording is not supported in this browser.');
      if (!stream || stream.getTracks().length === 0) {
        throw new Error('No media available to record.');
      }
      this.chunks = [];
      const mimeType = this.pickMimeType();
      this.recorder = mimeType
        ? new MediaRecorder(stream, { mimeType })
        : new MediaRecorder(stream);
      this.recorder.ondataavailable = (e) => {
        if (e.data && e.data.size > 0) this.chunks.push(e.data);
      };
      this.recorder.start(1000);
      this.recording = true;
    }

    stop() {
      return new Promise((resolve) => {
        if (!this.recorder || !this.recording) {
          resolve(null);
          return;
        }
        this.recorder.onstop = () => {
          const blob = new Blob(this.chunks, { type: this.recorder.mimeType || 'video/webm' });
          this.recording = false;
          this.recorder = null;
          resolve(blob);
        };
        this.recorder.stop();
      });
    }
  }

  async function playTestTone(sinkId) {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.frequency.value = 440;
    gain.gain.value = 0.05;
    osc.connect(gain);
    gain.connect(ctx.destination);
    if (sinkId && supportsSinkId() && ctx.setSinkId) {
      try {
        await ctx.setSinkId(sinkId);
      } catch (_) {}
    }
    osc.start();
    setTimeout(() => {
      osc.stop();
      ctx.close();
    }, 600);
  }

  global.ConnectMeetSettings = {
    loadPrefs,
    savePrefs,
    enumerateMediaDevices,
    supportsSinkId,
    applyAudioOutput,
    CaptionService,
    LocalRecorder,
    playTestTone,
  };
})(window);
