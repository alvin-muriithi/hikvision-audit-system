-- Active: 1718011205495@@127.0.0.1@3306@hikvision_audit
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

-- Daily availability aggregates (for reporting)
CREATE TABLE IF NOT EXISTS camera_availability_daily (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  day_date DATE NOT NULL,
  total_polls INT UNSIGNED NOT NULL DEFAULT 0,
  total_cameras_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
  online_cameras_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
  offline_cameras_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
  warning_cameras_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
  unknown_cameras_sum BIGINT UNSIGNED NOT NULL DEFAULT 0,
  offline_peak INT UNSIGNED NOT NULL DEFAULT 0,
  video_loss_events BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  UNIQUE KEY uq_availability_day (day_date),
  INDEX idx_availability_day (day_date)
) ENGINE=InnoDB;

-- System logs
CREATE TABLE IF NOT EXISTS system_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  log_message VARCHAR(1000) NOT NULL,
  severity ENUM('INFO','WARNING','ERROR') DEFAULT 'INFO',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

  INDEX idx_severity_time (severity, created_at)
) ENGINE=InnoDB;

-- Seed 7 days of availability data for testing
INSERT INTO camera_availability_daily
  (day_date, total_polls, total_cameras_sum, online_cameras_sum,
   offline_cameras_sum, warning_cameras_sum, unknown_cameras_sum,
   offline_peak, video_loss_events)
VALUES
  (CURDATE() - INTERVAL 6 DAY, 480, 228000, 210960, 9120, 5700, 2220, 22, 14),
  (CURDATE() - INTERVAL 5 DAY, 480, 228000, 213840, 7200, 4560, 2400, 18, 9),
  (CURDATE() - INTERVAL 4 DAY, 480, 228000, 216240, 5760, 4320, 1680, 14, 6),
  (CURDATE() - INTERVAL 3 DAY, 480, 228000, 218400, 4800, 3360, 1440, 11, 4),
  (CURDATE() - INTERVAL 2 DAY, 480, 228000, 219120, 4320, 3120, 1440, 10, 7),
  (CURDATE() - INTERVAL 1 DAY, 480, 228000, 220560, 3840, 2400, 1200,  9, 3),
  (CURDATE(),                  480, 228000, 221280, 3360, 2160,  1200,  8, 2)
AS new_vals
ON DUPLICATE KEY UPDATE
  total_polls = new_vals.total_polls,
  total_cameras_sum = new_vals.total_cameras_sum,
  online_cameras_sum = new_vals.online_cameras_sum,
  offline_cameras_sum = new_vals.offline_cameras_sum,
  warning_cameras_sum = new_vals.warning_cameras_sum,
  unknown_cameras_sum = new_vals.unknown_cameras_sum,
  offline_peak = new_vals.offline_peak,
  video_loss_events = new_vals.video_loss_events;

  from camera_events
  
 SELECT version ();

 -- Seed mock events from existing cameras (safe to run multiple times)
INSERT INTO camera_events (camera_id, event_type, event_description, created_at)
SELECT
    c.id,
    ELT(FLOOR(RAND()*6)+1,
        'CAMERA_OFFLINE',
        'DEVICE_RECONNECT',
        'VIDEO_LOSS',
        'VIDEO_RESTORED',
        'COMMUNICATION_EXCEPTION',
        'RECORDING_FAILED'
    ),
    ELT(FLOOR(RAND()*6)+1,
        'Camera became unreachable (network offline / comm exception).',
        'Camera reachable again (device reconnected).',
        'Video signal loss detected on streaming channel.',
        'Video signal restored on streaming channel.',
        'Communication exception reported.',
        'Recording failure detected.'
    ),
    NOW() - INTERVAL FLOOR(RAND()*10080) MINUTE  -- random time in last 7 days
FROM cameras c
-- Generate ~3 events per camera
CROSS JOIN (SELECT 1 UNION SELECT 2 UNION SELECT 3) multiplier
ORDER BY RAND()
LIMIT 1000;
