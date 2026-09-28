USE commesse_lite;

CREATE TABLE IF NOT EXISTS machine_runtime (
  machine_id INT UNSIGNED NOT NULL,
  online TINYINT(1) NOT NULL DEFAULT 0,
  machine_state VARCHAR(40) DEFAULT NULL,
  working TINYINT(1) DEFAULT NULL,
  alarms TINYINT(1) DEFAULT NULL,
  warnings TINYINT(1) DEFAULT NULL,
  track_speed DECIMAL(12,3) DEFAULT NULL,
  empty_machine TINYINT(1) DEFAULT NULL,
  pieces_in_machine INT DEFAULT NULL,
  axes_zero TINYINT(1) DEFAULT NULL,
  current_order VARCHAR(180) DEFAULT NULL,
  order_status VARCHAR(40) DEFAULT NULL,
  last_order_closed VARCHAR(180) DEFAULT NULL,
  execution_list_status VARCHAR(40) DEFAULT NULL,
  user_name VARCHAR(120) DEFAULT NULL,
  active_alarms_json LONGTEXT DEFAULT NULL,
  info_json LONGTEXT DEFAULT NULL,
  raw_status_json LONGTEXT DEFAULT NULL,
  last_checked_at DATETIME DEFAULT NULL,
  last_success_at DATETIME DEFAULT NULL,
  last_error TEXT DEFAULT NULL,
  PRIMARY KEY (machine_id),
  KEY idx_runtime_checked (last_checked_at),
  CONSTRAINT fk_runtime_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduler_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  finished_at DATETIME DEFAULT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  machines_total INT NOT NULL DEFAULT 0,
  machines_ok INT NOT NULL DEFAULT 0,
  triggered_by ENUM('cron','manual') NOT NULL DEFAULT 'cron',
  details_json LONGTEXT DEFAULT NULL,
  error_message TEXT DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_scheduler_started (started_at),
  KEY idx_scheduler_success (success, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
  ('scheduler.enabled', '1'),
  ('scheduler.interval_minutes', '5'),
  ('scheduler.production_lookback_minutes', '15'),
  ('scheduler.alarm_lookback_minutes', '60'),
  ('scheduler.page_limit', '100'),
  ('dashboard.refresh_seconds', '10'),
  ('cost.machine_hour', '0'),
  ('cost.edge_meter', '0'),
  ('cost.fixed_job', '0'),
  ('cost.overhead_percent', '0')
ON DUPLICATE KEY UPDATE setting_value=setting_value;
