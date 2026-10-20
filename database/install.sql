-- ConnectMeet database schema
-- Compatible with MySQL 5.7+ / MariaDB 10.3+

CREATE DATABASE IF NOT EXISTS connectmeet
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE connectmeet;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS meeting_events;
DROP TABLE IF EXISTS meeting_messages;
DROP TABLE IF EXISTS meeting_signaling;
DROP TABLE IF EXISTS meeting_invitations;
DROP TABLE IF EXISTS meeting_participants;
DROP TABLE IF EXISTS meetings;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS app_settings;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  display_name VARCHAR(80) NOT NULL,
  email VARCHAR(190) NULL UNIQUE,
  password_hash VARCHAR(255) NULL,
  role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
  status ENUM('active', 'disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meetings (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_code VARCHAR(32) NOT NULL UNIQUE,
  host_user_id INT UNSIGNED NULL,
  host_token_hash CHAR(64) NOT NULL,
  title VARCHAR(160) NOT NULL DEFAULT 'ConnectMeet Meeting',
  status ENUM('scheduled', 'active', 'ended', 'expired') NOT NULL DEFAULT 'active',
  scheduled_at DATETIME NULL,
  expires_at DATETIME NULL,
  is_locked TINYINT(1) NOT NULL DEFAULT 0,
  waiting_room_enabled TINYINT(1) NOT NULL DEFAULT 0,
  participant_limit INT UNSIGNED NOT NULL DEFAULT 8,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_meetings_host_user
    FOREIGN KEY (host_user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  INDEX idx_meetings_status (status),
  INDEX idx_meetings_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meeting_participants (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  participant_token_hash CHAR(64) NOT NULL,
  display_name VARCHAR(80) NOT NULL,
  role ENUM('host', 'participant') NOT NULL DEFAULT 'participant',
  audio_enabled TINYINT(1) NOT NULL DEFAULT 1,
  video_enabled TINYINT(1) NOT NULL DEFAULT 1,
  hand_raised TINYINT(1) NOT NULL DEFAULT 0,
  is_sharing_screen TINYINT(1) NOT NULL DEFAULT 0,
  connection_state VARCHAR(32) NOT NULL DEFAULT 'connected',
  approval_status ENUM('approved', 'waiting', 'rejected') NOT NULL DEFAULT 'approved',
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  left_at DATETIME NULL,
  CONSTRAINT fk_participants_meeting
    FOREIGN KEY (meeting_id) REFERENCES meetings(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_participants_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  INDEX idx_participants_meeting_active (meeting_id, left_at),
  INDEX idx_participants_last_seen (last_seen_at),
  INDEX idx_participants_token (participant_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meeting_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT UNSIGNED NOT NULL,
  participant_id INT UNSIGNED NOT NULL,
  message_type ENUM('text', 'system') NOT NULL DEFAULT 'text',
  message_content VARCHAR(1000) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_messages_meeting
    FOREIGN KEY (meeting_id) REFERENCES meetings(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_messages_participant
    FOREIGN KEY (participant_id) REFERENCES meeting_participants(id)
    ON DELETE CASCADE,
  INDEX idx_messages_meeting_created (meeting_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meeting_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT UNSIGNED NOT NULL,
  participant_id INT UNSIGNED NULL,
  event_type VARCHAR(64) NOT NULL,
  event_payload JSON NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_events_meeting
    FOREIGN KEY (meeting_id) REFERENCES meetings(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_events_participant
    FOREIGN KEY (participant_id) REFERENCES meeting_participants(id)
    ON DELETE SET NULL,
  INDEX idx_events_poll (meeting_id, id),
  INDEX idx_events_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Transient WebRTC signaling (offers/answers/ICE). Not permanent media storage.
CREATE TABLE meeting_signaling (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT UNSIGNED NOT NULL,
  from_participant_id INT UNSIGNED NOT NULL,
  to_participant_id INT UNSIGNED NOT NULL,
  signal_type ENUM('offer', 'answer', 'ice') NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  consumed TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_signaling_meeting
    FOREIGN KEY (meeting_id) REFERENCES meetings(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_signaling_from
    FOREIGN KEY (from_participant_id) REFERENCES meeting_participants(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_signaling_to
    FOREIGN KEY (to_participant_id) REFERENCES meeting_participants(id)
    ON DELETE CASCADE,
  INDEX idx_signaling_delivery (to_participant_id, consumed, id),
  INDEX idx_signaling_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE meeting_invitations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  meeting_id INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invitations_meeting
    FOREIGN KEY (meeting_id) REFERENCES meetings(id)
    ON DELETE CASCADE,
  INDEX idx_invitations_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rate_key VARCHAR(190) NOT NULL,
  hit_count INT UNSIGNED NOT NULL DEFAULT 1,
  window_start DATETIME NOT NULL,
  UNIQUE KEY uq_rate_key (rate_key),
  INDEX idx_rate_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE app_settings (
  setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO app_settings (setting_key, setting_value) VALUES
  ('app_name', 'ConnectMeet'),
  ('schema_version', '1.0.0');
