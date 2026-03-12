-- Hikvision Camera Audit Trail Monitoring System
-- Database: hikvision_audit

CREATE DATABASE IF NOT EXISTS hikvision_audit 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

USE hikvision_audit;

-- Master inventory of cameras
CREATE TABLE IF NOT EXISTS cameras (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  camera_name VARCHAR(190) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  nvr_name VARCHAR(190),
  nvr_area VARCHAR(190),

  status ENUM('ONLINE','OFFLINE','WARNING','UNKNOWN') DEFAULT 'UNKNOWN',
  video_signal_status ENUM('OK','VIDEO_LOSS','UNKNOWN') DEFAULT 'UNKNOWN',
  recording_status ENUM('OK','FAILED','NO_SCHEDULE','UNKNOWN') DEFAULT 'UNKNOWN',
  communication_status ENUM('OK','EXCEPTION','UNKNOWN') DEFAULT 'UNKNOWN',

  last_seen DATETIME,
  last_event_type VARCHAR(64),
  last_event_at DATETIME,

  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_cameras_ip (ip_address),
  INDEX idx_status (status),
  INDEX idx_nvr_area (nvr_area),
  INDEX idx_last_seen (last_seen)
) ENGINE=InnoDB;

-- Event audit trail
CREATE TABLE IF NOT EXISTS camera_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  camera_id INT UNSIGNED NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  event_description VARCHAR(500) NOT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_camera_time (camera_id, created_at),
  INDEX idx_event_type (event_type),

  CONSTRAINT fk_camera_events_camera
    FOREIGN KEY (camera_id) REFERENCES cameras(id)
    ON DELETE CASCADE
) ENGINE=InnoDB;

-- System logs
CREATE TABLE IF NOT EXISTS system_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  log_message VARCHAR(1000) NOT NULL,
  severity ENUM('INFO','WARNING','ERROR') DEFAULT 'INFO',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_severity_time (severity, created_at)
) ENGINE=InnoDB;