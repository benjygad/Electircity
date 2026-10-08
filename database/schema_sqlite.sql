-- =====================================================================
-- SEMS schema — SQLite variant (portability/tests only).
-- The primary target is MySQL/MariaDB (see schema_mysql.sql).
-- Applied automatically by backend/scripts/install.php when DB_DRIVER=sqlite.
-- =====================================================================
PRAGMA foreign_keys = ON;

CREATE TABLE users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  full_name     TEXT NOT NULL,
  email         TEXT NOT NULL UNIQUE,
  phone         TEXT,
  password_hash TEXT NOT NULL,
  role          TEXT NOT NULL DEFAULT 'consumer' CHECK (role IN ('consumer','admin','technician','support')),
  status        TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended')),
  created_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at    TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE TABLE meters (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  meter_number       TEXT NOT NULL UNIQUE,
  meter_type         TEXT NOT NULL DEFAULT 'single_phase_prepaid',
  service_location   TEXT NOT NULL,
  integration_status TEXT NOT NULL DEFAULT 'simulated'
    CHECK (integration_status IN ('connected','simulated','offline','integration_unavailable')),
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE TABLE user_meters (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  meter_id     INTEGER NOT NULL REFERENCES meters(id) ON DELETE CASCADE,
  relationship TEXT NOT NULL DEFAULT 'owner' CHECK (relationship IN ('owner','tenant')),
  created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  UNIQUE (user_id, meter_id)
);
CREATE INDEX idx_um_meter ON user_meters(meter_id);

CREATE TABLE token_transactions (
  id               INTEGER PRIMARY KEY AUTOINCREMENT,
  meter_id         INTEGER NOT NULL REFERENCES meters(id) ON DELETE RESTRICT,
  amount           REAL NOT NULL CHECK (amount >= 0),
  token_reference  TEXT,
  transaction_type TEXT NOT NULL DEFAULT 'purchase' CHECK (transaction_type IN ('purchase','adjustment')),
  status           TEXT NOT NULL DEFAULT 'recorded' CHECK (status IN ('recorded','pending','failed')),
  source           TEXT NOT NULL DEFAULT 'simulated' CHECK (source IN ('simulated','manual','imported','hardware')),
  created_at       TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_tt_meter_time ON token_transactions(meter_id, created_at);

CREATE TABLE meter_readings (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  meter_id       INTEGER NOT NULL REFERENCES meters(id) ON DELETE RESTRICT,
  reading_time   TEXT NOT NULL,
  cumulative_kwh REAL NOT NULL,
  voltage        REAL,
  current_amps   REAL,
  source         TEXT NOT NULL DEFAULT 'simulated' CHECK (source IN ('simulated','manual','imported','hardware')),
  created_at     TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  UNIQUE (meter_id, reading_time)
);
CREATE INDEX idx_mr_time ON meter_readings(reading_time);

CREATE TABLE consumption_records (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  meter_id     INTEGER NOT NULL REFERENCES meters(id) ON DELETE RESTRICT,
  period_start TEXT NOT NULL,
  period_end   TEXT NOT NULL,
  energy_kwh   REAL NOT NULL CHECK (energy_kwh >= 0),
  source       TEXT NOT NULL DEFAULT 'derived'
    CHECK (source IN ('simulated','manual','imported','hardware','derived')),
  created_at   TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  UNIQUE (meter_id, period_start)
);
CREATE INDEX idx_cr_period ON consumption_records(period_start, period_end);

CREATE TABLE alerts (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  meter_id    INTEGER NOT NULL REFERENCES meters(id) ON DELETE CASCADE,
  alert_type  TEXT NOT NULL,
  severity    TEXT NOT NULL DEFAULT 'warning' CHECK (severity IN ('info','warning','critical')),
  description TEXT NOT NULL,
  status      TEXT NOT NULL DEFAULT 'new'
    CHECK (status IN ('new','under_investigation','confirmed','dismissed','resolved')),
  data_source TEXT NOT NULL DEFAULT 'rule_engine',
  assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
  admin_notes TEXT,
  resolved_at TEXT,
  created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_alerts_meter ON alerts(meter_id);
CREATE INDEX idx_alerts_status ON alerts(status);

CREATE TABLE fault_reports (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  meter_id    INTEGER REFERENCES meters(id) ON DELETE SET NULL,
  category    TEXT NOT NULL DEFAULT 'other'
    CHECK (category IN ('power_outage','meter_problem','suspected_incorrect_reading','supply_issue','other')),
  description TEXT NOT NULL,
  status      TEXT NOT NULL DEFAULT 'submitted'
    CHECK (status IN ('submitted','under_review','assigned','in_progress','resolved','closed')),
  assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now')),
  updated_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_fr_user ON fault_reports(user_id);
CREATE INDEX idx_fr_status ON fault_reports(status);

CREATE TABLE support_messages (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  report_id  INTEGER NOT NULL REFERENCES fault_reports(id) ON DELETE CASCADE,
  sender_id  INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  message    TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_sm_report ON support_messages(report_id, created_at);

CREATE TABLE notifications (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  title      TEXT NOT NULL,
  message    TEXT NOT NULL,
  read_at    TEXT,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_notif_user ON notifications(user_id, created_at);

CREATE TABLE audit_logs (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
  action      TEXT NOT NULL,
  entity_type TEXT NOT NULL,
  entity_id   INTEGER,
  details     TEXT,
  created_at  TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_audit_entity ON audit_logs(entity_type, entity_id);

CREATE TABLE auth_tokens (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL UNIQUE,
  expires_at TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);
CREATE INDEX idx_tokens_user ON auth_tokens(user_id);

CREATE TABLE password_resets (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  code_hash  TEXT NOT NULL,
  expires_at TEXT NOT NULL,
  used_at    TEXT,
  created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%S','now'))
);

CREATE TABLE settings (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

CREATE TABLE rate_limits (
  bucket       TEXT PRIMARY KEY,
  hits         INTEGER NOT NULL DEFAULT 1,
  window_start TEXT NOT NULL
);

INSERT INTO settings (key, value) VALUES
  ('tariff_per_kwh_tzs', '400'),
  ('anomaly_spike_multiplier', '2.0'),
  ('anomaly_spike_min_kwh', '2.0'),
  ('anomaly_zero_days', '3'),
  ('anomaly_missing_hours', '36'),
  ('low_credit_threshold_tzs', '2000'),
  ('currency', 'TZS');
