-- =====================================================================
-- Smart Electricity Management System (SEMS) — MySQL/MariaDB schema
-- Academic prototype. Run:  mysql -u <user> -p sems < schema_mysql.sql
-- All timestamps are UTC (DATETIME). History tables are append-only.
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS password_resets;
DROP TABLE IF EXISTS auth_tokens;
DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS support_messages;
DROP TABLE IF EXISTS fault_reports;
DROP TABLE IF EXISTS alerts;
DROP TABLE IF EXISTS consumption_records;
DROP TABLE IF EXISTS meter_readings;
DROP TABLE IF EXISTS token_transactions;
DROP TABLE IF EXISTS user_meters;
DROP TABLE IF EXISTS meters;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------------- users
CREATE TABLE users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name     VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  phone         VARCHAR(30)  NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('consumer','admin','technician','support') NOT NULL DEFAULT 'consumer',
  status        ENUM('active','suspended') NOT NULL DEFAULT 'active',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- meters
CREATE TABLE meters (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  meter_number       VARCHAR(32) NOT NULL,
  meter_type         VARCHAR(40) NOT NULL DEFAULT 'single_phase_prepaid',
  service_location   VARCHAR(190) NOT NULL,
  integration_status ENUM('connected','simulated','offline','integration_unavailable') NOT NULL DEFAULT 'simulated',
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_meters_number (meter_number),
  KEY idx_meters_status (integration_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- user_meters
CREATE TABLE user_meters (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      INT UNSIGNED NOT NULL,
  meter_id     INT UNSIGNED NOT NULL,
  relationship ENUM('owner','tenant') NOT NULL DEFAULT 'owner',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_meters (user_id, meter_id),
  KEY idx_um_meter (meter_id),
  CONSTRAINT fk_um_user  FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
  CONSTRAINT fk_um_meter FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------- token_transactions
-- Prepaid purchase records. token_reference is stored MASKED (last 4
-- digits only) — full token values must never be persisted.
CREATE TABLE token_transactions (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  meter_id        INT UNSIGNED NOT NULL,
  amount          DECIMAL(12,2) NOT NULL,
  token_reference VARCHAR(40) NULL,
  transaction_type ENUM('purchase','adjustment') NOT NULL DEFAULT 'purchase',
  status          ENUM('recorded','pending','failed') NOT NULL DEFAULT 'recorded',
  source          ENUM('simulated','manual','imported','hardware') NOT NULL DEFAULT 'simulated',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_tt_meter_time (meter_id, created_at),
  KEY idx_tt_status (status),
  CONSTRAINT chk_tt_amount CHECK (amount >= 0),
  CONSTRAINT fk_tt_meter FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------- meter_readings
-- Append-only cumulative readings.
CREATE TABLE meter_readings (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  meter_id        INT UNSIGNED NOT NULL,
  reading_time    DATETIME NOT NULL,
  cumulative_kwh  DECIMAL(12,3) NOT NULL,
  voltage         DECIMAL(6,2) NULL,
  current_amps    DECIMAL(7,3) NULL,
  source          ENUM('simulated','manual','imported','hardware') NOT NULL DEFAULT 'simulated',
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_mr_meter_time (meter_id, reading_time),
  KEY idx_mr_time (reading_time),
  CONSTRAINT fk_mr_meter FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------- consumption_records
-- Daily energy derived from successive valid readings.
CREATE TABLE consumption_records (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  meter_id     INT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  period_end   DATE NOT NULL,
  energy_kwh   DECIMAL(12,3) NOT NULL,
  source       ENUM('simulated','manual','imported','hardware','derived') NOT NULL DEFAULT 'derived',
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_cr_meter_day (meter_id, period_start),
  KEY idx_cr_period (period_start, period_end),
  CONSTRAINT chk_cr_energy CHECK (energy_kwh >= 0),
  CONSTRAINT fk_cr_meter FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------- alerts
CREATE TABLE alerts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  meter_id     INT UNSIGNED NOT NULL,
  alert_type   VARCHAR(40) NOT NULL,
  severity     ENUM('info','warning','critical') NOT NULL DEFAULT 'warning',
  description  VARCHAR(500) NOT NULL,
  status       ENUM('new','under_investigation','confirmed','dismissed','resolved') NOT NULL DEFAULT 'new',
  data_source  VARCHAR(30) NOT NULL DEFAULT 'rule_engine',
  assigned_to  INT UNSIGNED NULL,
  admin_notes  TEXT NULL,
  resolved_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_alerts_meter (meter_id),
  KEY idx_alerts_status (status),
  KEY idx_alerts_created (created_at),
  CONSTRAINT fk_al_meter FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE CASCADE,
  CONSTRAINT fk_al_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------- fault_reports
CREATE TABLE fault_reports (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id     INT UNSIGNED NOT NULL,
  meter_id    INT UNSIGNED NULL,
  category    ENUM('power_outage','meter_problem','suspected_incorrect_reading','supply_issue','other') NOT NULL DEFAULT 'other',
  description TEXT NOT NULL,
  status      ENUM('submitted','under_review','assigned','in_progress','resolved','closed') NOT NULL DEFAULT 'submitted',
  assigned_to INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fr_user (user_id),
  KEY idx_fr_status (status),
  KEY idx_fr_created (created_at),
  CONSTRAINT fk_fr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_fr_meter FOREIGN KEY (meter_id) REFERENCES meters(id) ON DELETE SET NULL,
  CONSTRAINT fk_fr_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------- support_messages
CREATE TABLE support_messages (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  report_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NOT NULL,
  message   TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sm_report (report_id, created_at),
  CONSTRAINT fk_sm_report FOREIGN KEY (report_id) REFERENCES fault_reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_sm_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- notifications
CREATE TABLE notifications (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  title      VARCHAR(150) NOT NULL,
  message    VARCHAR(1000) NOT NULL,
  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, created_at),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- audit_logs
CREATE TABLE audit_logs (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_id    INT UNSIGNED NULL,
  action      VARCHAR(80) NOT NULL,
  entity_type VARCHAR(40) NOT NULL,
  entity_id   INT UNSIGNED NULL,
  details     VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_actor (actor_id),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_created (created_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- -------------------------------------------------------------- auth_tokens
-- Bearer tokens stored hashed (SHA-256). Plaintext exists only in the
-- login response and on the client.
CREATE TABLE auth_tokens (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY idx_tokens_user (user_id),
  CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------- password_resets
CREATE TABLE password_resets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NOT NULL,
  code_hash  CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pr_user (user_id),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- settings
CREATE TABLE settings (
  `key`   VARCHAR(60) NOT NULL,
  value   VARCHAR(255) NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------- rate_limits
CREATE TABLE rate_limits (
  bucket      VARCHAR(120) NOT NULL,
  hits        INT UNSIGNED NOT NULL DEFAULT 1,
  window_start DATETIME NOT NULL,
  PRIMARY KEY (bucket)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- default settings
-- DEMO TARIFF: an assumption for the academic prototype until an
-- applicable published tariff is verified.
INSERT INTO settings (`key`, value) VALUES
  ('tariff_per_kwh_tzs', '400'),
  ('anomaly_spike_multiplier', '2.0'),
  ('anomaly_spike_min_kwh', '2.0'),
  ('anomaly_zero_days', '3'),
  ('anomaly_missing_hours', '36'),
  ('low_credit_threshold_tzs', '2000'),
  ('currency', 'TZS');
