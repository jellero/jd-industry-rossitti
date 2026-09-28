CREATE DATABASE IF NOT EXISTS commesse_lite CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE commesse_lite;

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS clients (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(40) DEFAULT NULL,
  company_name VARCHAR(180) NOT NULL,
  vat_number VARCHAR(40) DEFAULT NULL,
  tax_code VARCHAR(40) DEFAULT NULL,
  email VARCHAR(180) DEFAULT NULL,
  phone VARCHAR(80) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  city VARCHAR(120) DEFAULT NULL,
  province VARCHAR(20) DEFAULT NULL,
  postal_code VARCHAR(20) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clients_code (code),
  KEY idx_clients_name (company_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_types (
  id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code VARCHAR(50) NOT NULL,
  name VARCHAR(120) NOT NULL,
  source_type ENUM('MAESTRO_REST','SMB_FOLDER') NOT NULL,
  description VARCHAR(255) DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_job_types_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS machines (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  kind ENUM('maestro_rest','folder') NOT NULL,
  api_version ENUM('v1','v2') DEFAULT NULL,
  host VARCHAR(120) DEFAULT NULL,
  port INT UNSIGNED DEFAULT NULL,
  base_path VARCHAR(80) DEFAULT NULL,
  upload_path VARCHAR(255) DEFAULT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_machines_kind (kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS jobs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id INT UNSIGNED NOT NULL,
  job_type_id TINYINT UNSIGNED NOT NULL,
  machine_id INT UNSIGNED DEFAULT NULL,
  job_code VARCHAR(80) NOT NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT DEFAULT NULL,
  status ENUM('bozza','aperta','in_lavorazione','chiusa','archiviata') NOT NULL DEFAULT 'bozza',
  start_date DATE DEFAULT NULL,
  due_date DATE DEFAULT NULL,
  closed_at DATETIME DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_jobs_code (job_code),
  KEY idx_jobs_client (client_id),
  KEY idx_jobs_type (job_type_id),
  KEY idx_jobs_machine (machine_id),
  KEY idx_jobs_status (status),
  CONSTRAINT fk_jobs_client FOREIGN KEY (client_id) REFERENCES clients(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_jobs_type FOREIGN KEY (job_type_id) REFERENCES job_types(id) ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_jobs_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maestro_order_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id INT UNSIGNED NOT NULL,
  machine_id INT UNSIGNED NOT NULL,
  action ENUM('open','activate','close','status','production_import','alarm_import') NOT NULL,
  request_value VARCHAR(180) DEFAULT NULL,
  http_code INT DEFAULT NULL,
  success TINYINT(1) NOT NULL DEFAULT 0,
  response_body LONGTEXT DEFAULT NULL,
  error_message TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_moe_job (job_id),
  KEY idx_moe_machine (machine_id),
  CONSTRAINT fk_moe_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_moe_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS production_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  machine_id INT UNSIGNED NOT NULL,
  job_id INT UNSIGNED DEFAULT NULL,
  remote_order_name VARCHAR(180) DEFAULT NULL,
  barcode VARCHAR(180) DEFAULT NULL,
  program_name VARCHAR(180) DEFAULT NULL,
  length_mm DECIMAL(12,3) DEFAULT NULL,
  width_mm DECIMAL(12,3) DEFAULT NULL,
  thickness_mm DECIMAL(12,3) DEFAULT NULL,
  passage INT DEFAULT NULL,
  edge_name_lh VARCHAR(180) DEFAULT NULL,
  edge_consumption_lh DECIMAL(12,3) DEFAULT NULL,
  datetime_start DATETIME DEFAULT NULL,
  datetime_end DATETIME DEFAULT NULL,
  track_speed DECIMAL(12,3) DEFAULT NULL,
  raw_json LONGTEXT DEFAULT NULL,
  source_hash CHAR(64) NOT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_production_hash (source_hash),
  KEY idx_prod_machine (machine_id),
  KEY idx_prod_job (job_id),
  KEY idx_prod_order (remote_order_name),
  KEY idx_prod_dates (datetime_start, datetime_end),
  CONSTRAINT fk_prod_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_prod_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS alarm_records (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  machine_id INT UNSIGNED NOT NULL,
  source VARCHAR(80) DEFAULT NULL,
  code VARCHAR(80) DEFAULT NULL,
  message TEXT DEFAULT NULL,
  other_info TEXT DEFAULT NULL,
  type VARCHAR(50) DEFAULT NULL,
  severity INT DEFAULT NULL,
  date_from DATETIME DEFAULT NULL,
  date_to DATETIME DEFAULT NULL,
  user_name VARCHAR(120) DEFAULT NULL,
  raw_json LONGTEXT DEFAULT NULL,
  source_hash CHAR(64) NOT NULL,
  imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_alarm_hash (source_hash),
  KEY idx_alarm_machine (machine_id),
  KEY idx_alarm_dates (date_from, date_to),
  CONSTRAINT fk_alarm_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS job_files (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  machine_id INT UNSIGNED NOT NULL,
  job_id INT UNSIGNED DEFAULT NULL,
  relative_path VARCHAR(600) NOT NULL,
  file_name VARCHAR(255) NOT NULL,
  extension VARCHAR(40) DEFAULT NULL,
  size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
  modified_at DATETIME DEFAULT NULL,
  file_hash CHAR(40) DEFAULT NULL,
  detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  assigned_at DATETIME DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_job_file_path (machine_id, relative_path),
  KEY idx_job_files_machine (machine_id),
  KEY idx_job_files_job (job_id),
  KEY idx_job_files_assigned (job_id, detected_at),
  CONSTRAINT fk_job_files_machine FOREIGN KEY (machine_id) REFERENCES machines(id) ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_job_files_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  setting_key VARCHAR(80) NOT NULL,
  setting_value TEXT DEFAULT NULL,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO job_types (id, code, name, source_type, description, active) VALUES
  (1, 'MAESTRO_ACTIVE', 'Lavoro macchina Maestro Active', 'MAESTRO_REST', 'Commessa gestita tramite servizi REST Maestro Active'),
  (2, 'MISURE_SMB', 'Lavoro misure da cartella SMB', 'SMB_FOLDER', 'File caricati dalla macchina misure in cartella locale')
ON DUPLICATE KEY UPDATE name=VALUES(name), source_type=VALUES(source_type), description=VALUES(description), active=VALUES(active);

INSERT INTO machines (id, name, kind, api_version, host, port, base_path, upload_path, active, notes) VALUES
  (1, 'Bordatrice', 'maestro_rest', 'v1', '192.168.1.150', 81, '/api/v1/', NULL, 1, 'Maestro Active bordatrice'),
  (2, 'Macchina misure SMB', 'folder', NULL, NULL, NULL, NULL, 'uploads/misurazioni', 1, 'Cartella dentro public/ dove la macchina carica i file via SMB.')
ON DUPLICATE KEY UPDATE name=VALUES(name), kind=VALUES(kind), api_version=VALUES(api_version), active=VALUES(active);

SET FOREIGN_KEY_CHECKS=1;

-- Estensioni produzione 2026-09-28
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
