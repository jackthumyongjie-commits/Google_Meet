/**
 * ConnectMeet WebRTC mesh engine.
 * Peer-to-peer mesh suitable for small rooms.
 */
(function (global) {
  'use strict';

  class WebRTCEngine {
    constructor(options = {}) {
      this.iceServers = options.iceServers || [{ urls: 'stun:stun.l.google.com:19302' }];
      this.signaling = options.signaling;
      this.localParticipantId = options.localParticipantId;
      this.onRemoteStream = options.onRemoteStream || (() => {});
      this.onPeerState = options.onPeerState || (() => {});
      this.onError = options.onError || (() => {});
      this.onLocalStream = options.onLocalStream || (() => {});

      this.localStream = null;
      this.screenStream = null;
      this.peers = new Map(); // remoteId -> { pc, makingOffer, ignoreOffer, polite, pendingIce, started }
      this.isSharing = false;
      this._audioAnalyser = null;
      this._audioData = null;
      this._speaking = false;
    }

    async getLocalMedia({ audio = true, video = true, audioDeviceId, videoDeviceId } = {}) {
      const constraints = {};
      if (audio) {
        constraints.audio = audioDeviceId
          ? { deviceId: { ideal: audioDeviceId } }
          : { echoCancellation: true, noiseSuppression: true };
      }
      if (video) {
        constraints.video = videoDeviceId
          ? { deviceId: { ideal: videoDeviceId }, width: { ideal: 1280 }, height: { ideal: 720 } }
          : { width: { ideal: 1280 }, height: { ideal: 720 } };
      }

      if (!constraints.audio && !constraints.video) {
        this.localStream = new MediaStream();
        this.onLocalStream(this.localStream);
        return this.localStream;
      }

      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        this.localStream = new MediaStream();
        this.onError(new Error('Media devices API is not available in this browser.'));
        this.onLocalStream(this.localStream);
        return this.localStream;
      }

      try {
        this.localStream = await navigator.mediaDevices.getUserMedia(constraints);
      } catch (err) {
        if (video && audio) {
          try {
            this.localStream = await navigator.mediaDevices.getUserMedia({
              audio: constraints.audio || true,
            });
            this.onError(new Error('Camera unavailable. Continuing with audio only.'));
          } catch (_) {
            this.localStream = new MediaStream();
            this.onError(this._mediaError(err));
          }
        } else {
          this.localStream = new MediaStream();
          this.onError(this._mediaError(err));
        }
      }

      this._setupAnalyser();
      this.onLocalStream(this.localStream);
      return this.localStream;
    }

    _mediaError(err) {
      const name = err && err.name;
      if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
        return new Error('Camera/microphone permission denied. You can still join without media.');
      }
      if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
        return new Error('No camera or microphone found.');
      }
      if (name === 'NotReadableError' || name === 'TrackStartError') {
        return new Error('Camera or microphone is already in use by another application.');
      }
      return new Error(err.message || 'Unable to access media devices.');
    }

    _setupAnalyser() {
      try {
        const track = this.localStream && this.localStream.getAudioTracks()[0];
        if (!track) return;
        const ctx = new (window.AudioContext || window.webkitAudioContext)();
        const source = ctx.createMediaStreamSource(new MediaStream([track]));
        const analyser = ctx.createAnalyser();
        analyser.fftSize = 256;
        source.connect(analyser);
        this._audioAnalyser = analyser;
        this._audioData = new Uint8Array(analyser.frequencyBinCount);
        this._audioCtx = ctx;
      } catch (_) {
        /* analyser optional */
      }
    }

    getSpeakingLevel() {
      if (!this._audioAnalyser || !this._audioData) return 0;
      this._audioAnalyser.getByteFrequencyData(this._audioData);
      let sum = 0;
      for (let i = 0; i < this._audioData.length; i++) sum += this._audioData[i];
      return sum / this._audioData.length / 255;
    }

    isLocalSpeaking(threshold = 0.08) {
      const level = this.getSpeakingLevel();
      this._speaking = level > threshold;
      return this._speaking;
    }

    async ensurePeer(remoteId, { polite = false } = {}) {
      remoteId = Number(remoteId);
      if (remoteId === this.localParticipantId) return null;
      if (this.peers.has(remoteId)) return this.peers.get(remoteId);

      const pc = new RTCPeerConnection({ iceServers: this.iceServers });
      const state = {
        pc,
        makingOffer: false,
        ignoreOffer: false,
        polite,
        pendingIce: [],
        started: false,
      };
      this.peers.set(remoteId, state);

      if (this.localStream) {
        this.localStream.getTracks().forEach((track) => {
          pc.addTrack(track, this.localStream);
        });
      }

      pc.onicecandidate = (event) => {
        if (event.candidate) {
          this.signaling.sendSignal(remoteId, 'ice', event.candidate.toJSON()).catch((err) => {
            this.onError(err);
          });
        }
      };

      pc.ontrack = (event) => {
        const stream = event.streams[0] || new MediaStream([event.track]);
        this.onRemoteStream(remoteId, stream);
      };

      pc.onconnectionstatechange = () => {
        this.onPeerState(remoteId, pc.connectionState);
        if (pc.connectionState === 'failed') {
          this._recoverPeer(remoteId).catch((err) => this.onError(err));
        }
      };

      pc.onnegotiationneeded = async () => {
        try {
          state.makingOffer = true;
          await pc.setLocalDescription();
          await this.signaling.sendSignal(remoteId, 'offer', pc.localDescription);
        } catch (err) {
          this.onError(err);
        } finally {
          state.makingOffer = false;
        }
      };

      return state;
    }

    async _recoverPeer(remoteId) {
      this.closePeer(remoteId);
      const polite = Number(this.localParticipantId) < Number(remoteId);
      await this.ensurePeer(remoteId, { polite });
    }

    async handleSignal(signal) {
      const fromId = Number(signal.from_participant_id);
      const polite = Number(this.localParticipantId) < fromId;
      const state = await this.ensurePeer(fromId, { polite });
      if (!state) return;
      const { pc } = state;
      const type = signal.signal_type;
      const payload = signal.payload;

      if (type === 'offer' || type === 'answer') {
        const description = payload;
        const offerCollision =
          description.type === 'offer' &&
          (state.makingOffer || pc.signalingState !== 'stable');

        state.ignoreOffer = !state.polite && offerCollision;
        if (state.ignoreOffer) return;

        await pc.setRemoteDescription(description);
        await this._flushIce(state);

        if (description.type === 'offer') {
          await pc.setLocalDescription();
          await this.signaling.sendSignal(fromId, 'answer', pc.localDescription);
        }
      } else if (type === 'ice') {
        if (!pc.remoteDescription) {
          state.pendingIce.push(payload);
        } else {
          try {
            await pc.addIceCandidate(payload);
          } catch (err) {
            if (!state.ignoreOffer) this.onError(err);
          }
        }
      }
    }

    async _flushIce(state) {
      while (state.pendingIce.length) {
        const candidate = state.pendingIce.shift();
        try {
          await state.pc.addIceCandidate(candidate);
        } catch (_) {
          /* ignore bad candidates */
        }
      }
    }

    async syncPeers(participantIds) {
      const ids = participantIds.map(Number).filter((id) => id !== this.localParticipantId);
      const existing = Array.from(this.peers.keys());

      for (const id of existing) {
        if (!ids.includes(id)) this.closePeer(id);
      }

      for (const id of ids) {
        const polite = Number(this.localParticipantId) < id;
        // Perfect negotiation: lower ID is polite; higher ID initiates once.
        const state = await this.ensurePeer(id, { polite });
        if (!polite && state && !state.started && state.pc.signalingState === 'stable') {
          try {
            state.started = true;
            state.makingOffer = true;
            await state.pc.setLocalDescription();
            await this.signaling.sendSignal(id, 'offer', state.pc.localDescription);
          } catch (err) {
            state.started = false;
            this.onError(err);
          } finally {
            state.makingOffer = false;
          }
        }
      }
    }

    setAudioEnabled(enabled) {
      if (!this.localStream) return;
      this.localStream.getAudioTracks().forEach((t) => {
        t.enabled = enabled;
      });
    }

    setVideoEnabled(enabled) {
      if (!this.localStream || this.isSharing) return;
      this.localStream.getVideoTracks().forEach((t) => {
        t.enabled = enabled;
      });
    }

    hasAudioTrack() {
      return !!(this.localStream && this.localStream.getAudioTracks().some((t) => t.readyState !== 'ended'));
    }

    hasVideoTrack() {
      return !!(this.localStream && this.localStream.getVideoTracks().some((t) => t.readyState !== 'ended'));
    }

    /**
     * Request missing mic/camera tracks and publish them to all peers.
     * Needed when user joined without media, then turns devices on later.
     */
    async ensureMedia({ audio = false, video = false } = {}) {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        throw new Error('Media devices are not available in this browser.');
      }

      if (!this.localStream) {
        this.localStream = new MediaStream();
      }

      const needAudio = audio && !this.hasAudioTrack();
      const needVideo = video && !this.hasVideoTrack();

      if (needAudio || needVideo) {
        const stream = await navigator.mediaDevices.getUserMedia({
          audio: needAudio
            ? { echoCancellation: true, noiseSuppression: true }
            : false,
          video: needVideo
            ? { width: { ideal: 1280 }, height: { ideal: 720 } }
            : false,
        });

        for (const track of stream.getTracks()) {
          this.localStream.addTrack(track);
          await this.publishTrack(track);
        }
      }

      if (audio) this.setAudioEnabled(true);
      if (video && !this.isSharing) this.setVideoEnabled(true);

      this._setupAnalyser();
      this.onLocalStream(this.localStream);
      return this.localStream;
    }

    async publishTrack(track) {
      const tasks = [];
      this.peers.forEach(({ pc }) => {
        const existing = pc.getSenders().find((s) => s.track && s.track.kind === track.kind);
        if (existing) {
          tasks.push(existing.replaceTrack(track));
          return;
        }
        const emptySender = pc.getSenders().find((s) => !s.track && s.track !== undefined);
        // Prefer addTrack for a fresh kind so negotiationneeded fires.
        try {
          pc.addTrack(track, this.localStream);
        } catch (err) {
          if (emptySender) tasks.push(emptySender.replaceTrack(track));
          else this.onError(err);
        }
      });
      await Promise.all(tasks);
    }

    async replaceVideoTrack(newTrack) {
      const replacements = [];
      this.peers.forEach(({ pc }) => {
        const sender = pc.getSenders().find((s) => s.track && s.track.kind === 'video');
        if (sender) replacements.push(sender.replaceTrack(newTrack));
      });
      await Promise.all(replacements);
    }

    async startScreenShare() {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
        throw new Error('Screen sharing is not supported in this browser.');
      }
      this.screenStream = await navigator.mediaDevices.getDisplayMedia({
        video: true,
        audio: false,
      });
      const screenTrack = this.screenStream.getVideoTracks()[0];
      await this.replaceVideoTrack(screenTrack);
      this.isSharing = true;

      screenTrack.onended = () => {
        this.stopScreenShare().catch(() => {});
      };

      return this.screenStream;
    }

    async stopScreenShare() {
      if (!this.isSharing) return;
      const camTrack = this.localStream ? this.localStream.getVideoTracks()[0] : null;
      await this.replaceVideoTrack(camTrack || null);
      if (this.screenStream) {
        this.screenStream.getTracks().forEach((t) => t.stop());
        this.screenStream = null;
      }
      this.isSharing = false;
    }

    async switchDevices({ audioDeviceId, videoDeviceId, audio = true, video = true } = {}) {
      const old = this.localStream;
      const next = await navigator.mediaDevices.getUserMedia({
        audio: audio
          ? audioDeviceId
            ? { deviceId: { exact: audioDeviceId } }
            : true
          : false,
        video: video
          ? videoDeviceId
            ? { deviceId: { exact: videoDeviceId }, width: { ideal: 1280 }, height: { ideal: 720 } }
            : true
          : false,
      });

      const audioTrack = next.getAudioTracks()[0] || null;
      const videoTrack = next.getVideoTracks()[0] || null;

      const tasks = [];
      this.peers.forEach(({ pc }) => {
        pc.getSenders().forEach((sender) => {
          if (sender.track && sender.track.kind === 'audio') {
            tasks.push(sender.replaceTrack(audioTrack));
          }
          if (sender.track && sender.track.kind === 'video' && !this.isSharing) {
            tasks.push(sender.replaceTrack(videoTrack));
          }
        });
      });
      await Promise.all(tasks);

      if (old) old.getTracks().forEach((t) => t.stop());
      this.localStream = next;
      this._setupAnalyser();
      this.onLocalStream(this.localStream);
      return this.localStream;
    }

    closePeer(remoteId) {
      remoteId = Number(remoteId);
      const state = this.peers.get(remoteId);
      if (!state) return;
      try {
        state.pc.close();
      } catch (_) {}
      this.peers.delete(remoteId);
    }

    stopAll() {
      this.peers.forEach((_, id) => this.closePeer(id));
      if (this.screenStream) {
        this.screenStream.getTracks().forEach((t) => t.stop());
        this.screenStream = null;
      }
      if (this.localStream) {
        this.localStream.getTracks().forEach((t) => t.stop());
        this.localStream = null;
      }
      if (this._audioCtx) {
        try {
          this._audioCtx.close();
        } catch (_) {}
        this._audioCtx = null;
      }
      this.isSharing = false;
    }
  }

  global.ConnectMeetWebRTC = WebRTCEngine;
})(window);
