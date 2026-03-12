-- Hikvision Camera Audit Trail Monitoring System
-- Database: hikvision_audit

CREATE DATABASE IF NOT EXISTS hikvision_audit
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE hikvision_audit;

-- Master inventory of cameras (seed this table with your devices)
CREATE TABLE IF NOT EXISTS cameras (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  camera_name VARCHAR(190) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  nvr_name VARCHAR(190) NULL,
  area VARCHAR(190) NULL,

  -- High-level statuses used by the dashboard
  status ENUM('ONLINE','OFFLINE','WARNING','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  video_signal_status ENUM('OK','VIDEO_LOSS','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  recording_status ENUM('OK','FAILED','NO_SCHEDULE','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',
  communication_status ENUM('OK','EXCEPTION','UNKNOWN') NOT NULL DEFAULT 'UNKNOWN',

  last_seen DATETIME NULL,
  last_event_type VARCHAR(64) NULL,
  last_event_at DATETIME NULL,

  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  UNIQUE KEY uq_cameras_ip (ip_address),
  KEY idx_status (status),
  KEY idx_area (area),
  KEY idx_last_seen (last_seen)
) ENGINE=InnoDB;

-- Event audit trail (append-only)
CREATE TABLE IF NOT EXISTS camera_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  camera_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  event_description VARCHAR(500) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (id),
  KEY idx_camera_time (camera_id, created_at),
  KEY idx_event_type (event_type),
  CONSTRAINT fk_camera_events_camera
    FOREIGN KEY (camera_id) REFERENCES cameras(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- Operational/system logs (errors, warnings, polling results)
CREATE TABLE IF NOT EXISTS system_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  log_message VARCHAR(1000) NOT NULL,
  severity ENUM('INFO','WARNING','ERROR') NOT NULL DEFAULT 'INFO',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_severity_time (severity, created_at)
) ENGINE=InnoDB;

-- Optional: seed example camera (edit/remove)
-- INSERT INTO cameras (camera_name, ip_address, nvr_name, area)
-- VALUES ('IPcamera 43', '10.97.10.2', 'STM-NVR-2', 'STM-NVR-2');

