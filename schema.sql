-- Borg Backup Server — Database Schema
-- Run: mysql -u root -p bbs < schema.sql

-- --------------------------------------------------------
-- Users & Authentication
-- --------------------------------------------------------

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    all_clients TINYINT(1) NOT NULL DEFAULT 0,
    timezone VARCHAR(50) NOT NULL DEFAULT 'America/New_York',
    time_format VARCHAR(3) NOT NULL DEFAULT '12h',
    theme VARCHAR(10) NOT NULL DEFAULT 'dark',
    totp_secret VARCHAR(255) DEFAULT NULL,
    totp_enabled TINYINT(1) NOT NULL DEFAULT 0,
    totp_enabled_at DATETIME DEFAULT NULL,
    auth_provider ENUM('local', 'oidc') NOT NULL DEFAULT 'local',
    oidc_status ENUM('active', 'pending') NOT NULL DEFAULT 'active',
    daily_report_email TINYINT(1) NOT NULL DEFAULT 0,
    daily_report_hour TINYINT NOT NULL DEFAULT 6,
    report_frequency ENUM('daily', 'weekly') NOT NULL DEFAULT 'daily',
    report_day TINYINT NOT NULL DEFAULT 1,
    storage_alert_mode ENUM('percent','gb_free','disabled') NOT NULL DEFAULT 'percent',
    storage_alert_value INT NOT NULL DEFAULT 90,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE recovery_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code_hash VARCHAR(255) NOT NULL,
    used_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unused (user_id, used_at)
) ENGINE=InnoDB;

CREATE TABLE rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    endpoint VARCHAR(100) NOT NULL,
    attempts INT NOT NULL DEFAULT 1,
    window_start DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_endpoint (ip_address, endpoint)
);

CREATE TABLE password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Default admin user (password: admin)
INSERT INTO users (username, email, password_hash, role) VALUES
('admin', 'admin@borgbackupserver.com', '$2y$12$OMFE1ma3aKDFjEYAP24eTuIznogvlOD2k3Emh0Hmvdckirgu73U2m', 'admin');

-- --------------------------------------------------------
-- User Permissions & Client Access
-- --------------------------------------------------------

CREATE TABLE user_agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    agent_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_agent (user_id, agent_id),
    INDEX idx_agent_id (agent_id),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB;

CREATE TABLE user_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission ENUM('trigger_backup', 'manage_repos', 'manage_plans', 'restore', 'repo_maintenance') NOT NULL,
    agent_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_perm_agent (user_id, permission, agent_id),
    INDEX idx_user_id (user_id),
    INDEX idx_agent_id (agent_id)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Storage & Agents
-- --------------------------------------------------------

CREATE TABLE agents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    hostname VARCHAR(255) DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    api_key VARCHAR(64) DEFAULT NULL,
    api_key_hash CHAR(64) DEFAULT NULL,
    api_key_encrypted TEXT DEFAULT NULL,
    INDEX idx_api_key_hash (api_key_hash),
    os_info VARCHAR(255) DEFAULT NULL,
    borg_version VARCHAR(20) DEFAULT NULL,
    borg_install_method ENUM('package','binary','pip','unknown') DEFAULT 'unknown',
    borg_source ENUM('official','server','unknown') DEFAULT 'unknown',
    borg_binary_path VARCHAR(255) DEFAULT NULL,
    glibc_version VARCHAR(20) DEFAULT NULL,
    platform VARCHAR(20) DEFAULT NULL,
    architecture VARCHAR(20) DEFAULT NULL,
    agent_version VARCHAR(20) DEFAULT NULL,
    ssh_unix_user VARCHAR(100) DEFAULT NULL,
    ssh_public_key TEXT DEFAULT NULL,
    ssh_private_key_encrypted TEXT DEFAULT NULL,
    ssh_home_dir VARCHAR(255) DEFAULT NULL,
    server_host_override VARCHAR(255) DEFAULT NULL,
    ssh_port_override INT DEFAULT NULL,
    status ENUM('setup', 'online', 'offline', 'error') NOT NULL DEFAULT 'setup',
    last_heartbeat DATETIME DEFAULT NULL,
    user_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- --------------------------------------------------------
-- Repositories & Backup Plans
-- --------------------------------------------------------

CREATE TABLE storage_locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(100) NOT NULL,
    path VARCHAR(500) NOT NULL,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE remote_ssh_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    provider VARCHAR(50) DEFAULT NULL,
    remote_host VARCHAR(255) NOT NULL,
    remote_port INT NOT NULL DEFAULT 22,
    remote_user VARCHAR(100) NOT NULL,
    remote_base_path VARCHAR(500) NOT NULL DEFAULT './',
    ssh_private_key_encrypted TEXT NOT NULL,
    borg_remote_path VARCHAR(255) DEFAULT NULL,
    append_repo_name TINYINT(1) NOT NULL DEFAULT 1,
    disk_total_bytes BIGINT DEFAULT NULL,
    disk_used_bytes BIGINT DEFAULT NULL,
    disk_free_bytes BIGINT DEFAULT NULL,
    disk_checked_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE repositories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    storage_type VARCHAR(20) NOT NULL DEFAULT 'local',
    storage_location_id INT DEFAULT NULL,
    remote_ssh_config_id INT DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    path VARCHAR(500) NOT NULL,
    encryption VARCHAR(50) NOT NULL DEFAULT 'repokey-blake2',
    passphrase_encrypted TEXT DEFAULT NULL,
    size_bytes BIGINT NOT NULL DEFAULT 0,
    archive_count INT NOT NULL DEFAULT 0,
    borg_version_created VARCHAR(20) DEFAULT NULL,
    borg_version_last VARCHAR(20) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    FOREIGN KEY (storage_location_id) REFERENCES storage_locations(id)
);

CREATE TABLE backup_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    repository_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    directories TEXT NOT NULL,
    excludes TEXT DEFAULT NULL,
    advanced_options TEXT DEFAULT NULL,
    prune_minutes INT NOT NULL DEFAULT 0,
    prune_hours INT NOT NULL DEFAULT 0,
    prune_days INT NOT NULL DEFAULT 7,
    prune_weeks INT NOT NULL DEFAULT 4,
    prune_months INT NOT NULL DEFAULT 6,
    prune_years INT NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE CASCADE
);

CREATE TABLE schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_plan_id INT NOT NULL,
    frequency VARCHAR(30) NOT NULL DEFAULT 'daily',
    times VARCHAR(255) DEFAULT NULL,
    day_of_week TINYINT DEFAULT NULL,
    day_of_month VARCHAR(20) DEFAULT NULL,
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    next_run DATETIME DEFAULT NULL,
    last_run DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (backup_plan_id) REFERENCES backup_plans(id) ON DELETE CASCADE
);

-- --------------------------------------------------------
-- Jobs & Archives
-- --------------------------------------------------------

CREATE TABLE backup_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_plan_id INT DEFAULT NULL,
    agent_id INT NOT NULL,
    repository_id INT DEFAULT NULL,
    source_repository_id INT DEFAULT NULL,
    task_type ENUM('backup', 'prune', 'restore', 'restore_mysql', 'restore_pg', 'restore_mongo', 'check', 'compact', 'update_borg', 'update_agent', 'plugin_test', 's3_sync', 'repo_check', 'repo_repair', 'break_lock', 's3_restore', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete') NOT NULL DEFAULT 'backup',
    plugin_config_id INT DEFAULT NULL,
    status ENUM('queued', 'sent', 'running', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
    files_total INT DEFAULT NULL,
    files_processed INT DEFAULT NULL,
    bytes_total BIGINT DEFAULT NULL,
    bytes_processed BIGINT DEFAULT NULL,
    duration_seconds INT DEFAULT NULL,
    error_log TEXT DEFAULT NULL,
    had_warnings TINYINT(1) NOT NULL DEFAULT 0,
    status_message VARCHAR(255) DEFAULT NULL,
    restore_archive_id INT DEFAULT NULL,
    restore_paths JSON DEFAULT NULL,
    restore_destination VARCHAR(512) DEFAULT NULL,
    restore_databases JSON DEFAULT NULL,
    queued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    last_progress_at DATETIME DEFAULT NULL,
    FOREIGN KEY (backup_plan_id) REFERENCES backup_plans(id) ON DELETE SET NULL,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
);

CREATE TABLE archives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    repository_id INT NOT NULL,
    backup_job_id INT DEFAULT NULL,
    archive_name VARCHAR(255) NOT NULL,
    file_count INT NOT NULL DEFAULT 0,
    original_size BIGINT NOT NULL DEFAULT 0,
    deduplicated_size BIGINT NOT NULL DEFAULT 0,
    databases_backed_up JSON DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE CASCADE,
    FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id) ON DELETE SET NULL
);

-- --------------------------------------------------------
-- File Catalog (ClickHouse)
-- --------------------------------------------------------
-- Catalog data (file_catalog, catalog_dirs) is stored in ClickHouse.
-- See schema-clickhouse.sql for the ClickHouse table definitions.

-- --------------------------------------------------------
-- Logging & Settings
-- --------------------------------------------------------

CREATE TABLE server_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT DEFAULT NULL,
    backup_job_id INT DEFAULT NULL,
    level ENUM('info', 'warning', 'error') NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE SET NULL,
    FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id) ON DELETE SET NULL,
    INDEX idx_level (level),
    INDEX idx_created (created_at)
);

CREATE TABLE settings (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT DEFAULT NULL
);

INSERT INTO settings (`key`, `value`) VALUES
    ('max_queue', '4'),
    ('server_host', ''),
    ('agent_poll_interval', '30'),
    ('smtp_host', ''),
    ('smtp_port', '587'),
    ('smtp_user', ''),
    ('smtp_pass', ''),
    ('smtp_from', ''),
    ('notification_retention_days', '30'),
    ('storage_alert_threshold', '90'),
    ('email_on_backup_failed', '1'),
    ('email_on_agent_offline', '1'),
    ('email_on_storage_low', '1'),
    ('email_on_missed_schedule', '0'),
    ('force_2fa', '0'),
    ('target_borg_version', ''),
    ('last_borg_version_check', ''),
    ('fallback_to_pip', '1'),
    ('s3_endpoint', ''),
    ('s3_region', ''),
    ('s3_bucket', ''),
    ('s3_access_key', ''),
    ('s3_secret_key', ''),
    ('s3_path_prefix', ''),
    ('s3_sync_server_backups', '0'),
    ('ssh_port', '22'),
    ('apprise_urls', ''),
    ('apprise_on_backup_failed', '1'),
    ('apprise_on_agent_offline', '1'),
    ('apprise_on_storage_low', '1'),
    ('apprise_on_missed_schedule', '0'),
    ('self_backup_enabled', '1'),
    ('self_backup_retention', '7'),
    ('self_backup_catalogs', '0');

-- --------------------------------------------------------
-- Notifications
-- --------------------------------------------------------

CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(50) NOT NULL,
    agent_id INT DEFAULT NULL,
    reference_id INT DEFAULT NULL,
    user_id INT DEFAULT NULL,
    severity VARCHAR(20) NOT NULL DEFAULT 'warning',
    message TEXT NOT NULL,
    occurrence_count INT NOT NULL DEFAULT 1,
    first_occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_occurred_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME DEFAULT NULL,
    resolved_at DATETIME DEFAULT NULL,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_unresolved (resolved_at, read_at),
    INDEX idx_user_unresolved (user_id, resolved_at, read_at)
);

CREATE TABLE notification_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    service_type VARCHAR(50) NOT NULL,
    apprise_url TEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    events JSON NOT NULL,
    last_used_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_enabled (enabled)
) ENGINE=InnoDB;

CREATE TABLE daily_reports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_date DATE NOT NULL,
    data JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- --------------------------------------------------------
-- Plugins
-- --------------------------------------------------------

CREATE TABLE plugins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    plugin_type ENUM('pre_backup', 'post_backup') NOT NULL DEFAULT 'pre_backup',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE agent_plugins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    plugin_id INT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    FOREIGN KEY (plugin_id) REFERENCES plugins(id) ON DELETE CASCADE,
    UNIQUE KEY unique_agent_plugin (agent_id, plugin_id)
) ENGINE=InnoDB;

CREATE TABLE plugin_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    plugin_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    config JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    FOREIGN KEY (plugin_id) REFERENCES plugins(id) ON DELETE CASCADE,
    UNIQUE KEY unique_agent_config_name (agent_id, plugin_id, name)
) ENGINE=InnoDB;

CREATE TABLE backup_plan_plugins (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_plan_id INT NOT NULL,
    plugin_id INT NOT NULL,
    plugin_config_id INT DEFAULT NULL,
    config JSON NOT NULL,
    execution_order INT NOT NULL DEFAULT 0,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (backup_plan_id) REFERENCES backup_plans(id) ON DELETE CASCADE,
    FOREIGN KEY (plugin_id) REFERENCES plugins(id) ON DELETE CASCADE,
    FOREIGN KEY (plugin_config_id) REFERENCES plugin_configs(id) ON DELETE SET NULL,
    UNIQUE KEY unique_plan_plugin (backup_plan_id, plugin_id)
) ENGINE=InnoDB;

-- Repository-level S3 sync configuration (decoupled from backup plans)
CREATE TABLE repository_s3_configs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    repository_id INT NOT NULL,
    plugin_config_id INT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_sync_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE CASCADE,
    FOREIGN KEY (plugin_config_id) REFERENCES plugin_configs(id) ON DELETE RESTRICT,
    UNIQUE KEY unique_repo_s3 (repository_id)
) ENGINE=InnoDB;

INSERT INTO plugins (slug, name, description, plugin_type) VALUES
('mysql_dump', 'MySQL Backup/Restore', 'Dumps MySQL databases before each backup, storing them in the repository for easy one-click restore back to the server.', 'pre_backup'),
('pg_dump', 'PostgreSQL Backup/Restore', 'Dumps PostgreSQL databases before each backup, storing them in the repository for easy one-click restore back to the server.', 'pre_backup'),
('mongo_dump', 'MongoDB Backup/Restore', 'Dumps MongoDB databases using mongodump before backup. Supports per-database dumps with optional gzip compression and automatic cleanup. Restore via mongorestore.', 'pre_backup'),
('interworx', 'InterWorx Backup', 'Runs InterWorx control panel backups before each borg archive. Supports full, partial (web/mail/db), and structure-only backup modes for all domains.', 'pre_backup'),
('shell_hook', 'Shell Script Hook', 'Runs custom shell scripts on the client before and/or after backup. Useful for application quiescing, cache clearing, notifications, or custom integrations.', 'pre_backup'),
('s3_sync', 'S3 Offsite Sync', 'Automatic sync of repositories to any S3-compatible storage after backup and prune operations. Stores a manifest for fast restore without long borg operations.', 'post_backup');

-- --------------------------------------------------------
-- API Tokens (admin API authentication)
-- --------------------------------------------------------

CREATE TABLE api_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    token_hash VARCHAR(64) NOT NULL UNIQUE,
    user_id INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Backup Templates
-- --------------------------------------------------------

CREATE TABLE backup_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT '',
    directories TEXT NOT NULL,
    excludes TEXT DEFAULT NULL,
    advanced_options TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO backup_templates (name, description, directories, excludes) VALUES
('Web Server', 'Apache/Nginx web server with virtual hosts', '/var/www\n/etc/nginx\n/etc/apache2\n/etc/httpd\n/etc/letsencrypt\n/home', '*.tmp\n*.log\n*.cache\n/home/*/tmp\n/home/*/.cache'),
('Database Server (MySQL)', 'MySQL/MariaDB database server', '/var/lib/mysql\n/etc/mysql\n/etc/my.cnf.d\n/root', '*.tmp\n*.pid\n*.sock'),
('Database Server (PostgreSQL)', 'PostgreSQL database server', '/var/lib/postgresql\n/etc/postgresql\n/root', '*.tmp\n*.pid\n*.sock'),
('Mail Server', 'Email server with mailboxes', '/var/mail\n/var/vmail\n/etc/postfix\n/etc/dovecot\n/etc/opendkim', '*.tmp\n*.log'),
('Interworx Server', 'Interworx hosting control panel', '/chroot/home\n/var\n/etc\n/usr/local\n/root', '*.tmp\n*.log\n*.cache'),
('File Server', 'General purpose file/NAS server', '/home\n/srv\n/opt\n/var/shared', '*.tmp\n*.cache\nThumbs.db\n.DS_Store'),
('Docker Host', 'Docker/container host', '/opt\n/srv\n/home\n/etc\n/var/lib/docker/volumes', '*.tmp\n*.log\n/var/lib/docker/overlay2'),
('Minimal (System Config)', 'Essential system configuration only', '/etc\n/root\n/home\n/var/spool/cron', '*.tmp\n*.log\n*.cache');

-- --------------------------------------------------------
-- Borg Version Management
-- --------------------------------------------------------

CREATE TABLE borg_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(20) NOT NULL UNIQUE,
    release_tag VARCHAR(30) NOT NULL,
    release_date DATE NOT NULL,
    is_prerelease TINYINT(1) NOT NULL DEFAULT 0,
    release_notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_version (version)
) ENGINE=InnoDB;

CREATE TABLE borg_version_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    borg_version_id INT NOT NULL,
    platform VARCHAR(20) NOT NULL,
    architecture VARCHAR(20) NOT NULL,
    glibc_version VARCHAR(20) DEFAULT NULL,
    asset_name VARCHAR(100) NOT NULL,
    download_url VARCHAR(500) NOT NULL,
    file_size BIGINT DEFAULT NULL,
    FOREIGN KEY (borg_version_id) REFERENCES borg_versions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_asset (borg_version_id, platform, architecture, glibc_version)
) ENGINE=InnoDB;

-- --------------------------------------------------------
-- Foreign Keys (added after all tables created)
-- --------------------------------------------------------

ALTER TABLE user_agents ADD FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE;
ALTER TABLE user_permissions ADD FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE;
