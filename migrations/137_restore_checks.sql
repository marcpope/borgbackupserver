-- Migration 137: scheduled restore checks
--
-- A restore check extracts part or all of an archive into private staging on
-- the BBS server, validates what came out, and deletes it again. It proves the
-- archive can be read back; it is not a restore over the original client.
--
-- Plans live in their own table rather than `schedules`, which is one row per
-- backup plan. Runs keep their own evidence with snapshots of every name they
-- refer to, so the history outlives the plan, the archive and the job row that
-- the daily job cleanup deletes after 30 days.
--
-- Every statement is safe to replay: a fresh install imports schema.sql, which
-- already has all of this, and then runs every migration.

ALTER TABLE backup_jobs MODIFY COLUMN task_type ENUM(
    'backup', 'backup_dry_run', 'prune', 'restore', 'restore_mysql', 'restore_pg', 'restore_mongo',
    'check', 'compact', 'update_borg', 'update_agent', 'plugin_test', 'plugin_post', 's3_sync',
    'repo_check', 'repo_repair', 'break_lock', 's3_restore', 'catalog_sync', 'catalog_rebuild',
    'catalog_rebuild_full', 'archive_delete', 'list_dir', 'archive_lock', 'restore_check'
) NOT NULL DEFAULT 'backup';

CREATE TABLE IF NOT EXISTS restore_check_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    repository_id INT DEFAULT NULL,
    -- 'plan' tests archives made by one backup plan; 'repository' tests the
    -- newest archive any backup plan wrote to the repository.
    scope ENUM('plan', 'repository') NOT NULL DEFAULT 'plan',
    backup_plan_id INT DEFAULT NULL,
    name VARCHAR(100) NOT NULL,
    mode ENUM('paths', 'full') NOT NULL DEFAULT 'paths',
    -- [{"path": "etc/hostname", "sha256": null}, ...] — archive-relative, literal
    paths JSON DEFAULT NULL,
    browse_archive_name VARCHAR(255) DEFAULT NULL,
    allow_warning_archives TINYINT(1) NOT NULL DEFAULT 0,
    max_archive_age_hours INT NOT NULL DEFAULT 48,
    frequency ENUM('daily', 'weekly', 'monthly') NOT NULL DEFAULT 'weekly',
    times VARCHAR(255) NOT NULL DEFAULT '03:00',
    day_of_week VARCHAR(20) DEFAULT '1',
    day_of_month VARCHAR(50) DEFAULT '1',
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    enabled_at DATETIME DEFAULT NULL,
    next_due_at DATETIME DEFAULT NULL,
    queue_deadline_minutes INT NOT NULL DEFAULT 1440,
    run_timeout_minutes INT NOT NULL DEFAULT 120,
    max_bytes BIGINT NOT NULL DEFAULT 10737418240,
    max_files INT NOT NULL DEFAULT 100000,
    min_free_bytes BIGINT NOT NULL DEFAULT 5368709120,
    overdue_hours INT NOT NULL DEFAULT 192,
    notify_success TINYINT(1) NOT NULL DEFAULT 0,
    -- Whose authority scheduled runs execute under. Revoking that user's
    -- access disables the plan until someone with access enables it again.
    owner_user_id INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    config_revision INT NOT NULL DEFAULT 1,
    disabled_reason VARCHAR(255) DEFAULT NULL,
    last_attempt_at DATETIME DEFAULT NULL,
    last_strict_success_at DATETIME DEFAULT NULL,
    last_result VARCHAR(32) DEFAULT NULL,
    last_run_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rcp_due (enabled, next_due_at),
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE SET NULL,
    FOREIGN KEY (backup_plan_id) REFERENCES backup_plans(id) ON DELETE SET NULL,
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS restore_check_runs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    -- Names the staging directory; random so it cannot be predicted.
    run_token CHAR(32) NOT NULL,
    plan_id INT DEFAULT NULL,
    backup_job_id INT DEFAULT NULL,
    -- Plain columns, not foreign keys: evidence outlives the client, repository
    -- and archive. A run whose client is gone is visible to admins only.
    agent_id INT DEFAULT NULL,
    repository_id INT DEFAULT NULL,
    trigger_type ENUM('scheduled', 'manual') NOT NULL,
    requested_by INT DEFAULT NULL,
    requested_by_name VARCHAR(100) DEFAULT NULL,
    scheduled_for DATETIME DEFAULT NULL,
    idempotency_key VARCHAR(64) DEFAULT NULL,
    missed_occurrences INT NOT NULL DEFAULT 0,
    config_revision INT NOT NULL,
    config_snapshot JSON NOT NULL,
    client_name VARCHAR(255) DEFAULT NULL,
    repository_name VARCHAR(255) DEFAULT NULL,
    plan_name VARCHAR(100) DEFAULT NULL,
    source_plan_name VARCHAR(255) DEFAULT NULL,
    archive_db_id INT DEFAULT NULL,
    archive_name VARCHAR(255) DEFAULT NULL,
    borg_archive_id CHAR(64) DEFAULT NULL,
    archive_time DATETIME DEFAULT NULL,
    archive_age_seconds INT DEFAULT NULL,
    source_job_id INT DEFAULT NULL,
    source_had_warnings TINYINT(1) DEFAULT NULL,
    phase VARCHAR(32) NOT NULL DEFAULT 'queued',
    -- NULL until finished; then passed | warning | failed | cancelled | skipped
    result VARCHAR(16) DEFAULT NULL,
    reason_code VARCHAR(64) DEFAULT NULL,
    reason_detail VARCHAR(500) DEFAULT NULL,
    borg_exit_code INT DEFAULT NULL,
    -- Outcome of each stage, kept apart so a failed cleanup never hides
    -- (or is hidden by) a good validation: ok | warning | failed | skipped
    extraction_status VARCHAR(16) DEFAULT NULL,
    validation_status VARCHAR(16) DEFAULT NULL,
    queued_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    started_at DATETIME DEFAULT NULL,
    finished_at DATETIME DEFAULT NULL,
    extraction_seconds DECIMAL(12,3) DEFAULT NULL,
    validation_seconds DECIMAL(12,3) DEFAULT NULL,
    total_seconds DECIMAL(12,3) DEFAULT NULL,
    expected_files BIGINT DEFAULT NULL,
    expected_bytes BIGINT DEFAULT NULL,
    extracted_files BIGINT DEFAULT NULL,
    extracted_bytes BIGINT DEFAULT NULL,
    probe_summary JSON DEFAULT NULL,
    warnings JSON DEFAULT NULL,
    limitations JSON DEFAULT NULL,
    bbs_version VARCHAR(32) DEFAULT NULL,
    borg_version VARCHAR(32) DEFAULT NULL,
    staging_created TINYINT(1) NOT NULL DEFAULT 0,
    cleanup_status ENUM('not_needed', 'pending', 'succeeded', 'failed') NOT NULL DEFAULT 'not_needed',
    cleanup_detail VARCHAR(500) DEFAULT NULL,
    cleanup_attempts INT NOT NULL DEFAULT 0,
    diagnostic TEXT DEFAULT NULL,
    report_schema_version INT DEFAULT NULL,
    report_json MEDIUMTEXT DEFAULT NULL,
    report_digest CHAR(64) DEFAULT NULL,
    UNIQUE KEY uniq_rcr_token (run_token),
    UNIQUE KEY uniq_rcr_occurrence (plan_id, scheduled_for),
    UNIQUE KEY uniq_rcr_idempotency (idempotency_key),
    INDEX idx_rcr_job (backup_job_id),
    INDEX idx_rcr_plan (plan_id, id),
    INDEX idx_rcr_agent (agent_id, id),
    INDEX idx_rcr_unfinished (result, phase),
    FOREIGN KEY (plan_id) REFERENCES restore_check_plans(id) ON DELETE SET NULL,
    FOREIGN KEY (backup_job_id) REFERENCES backup_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT IGNORE INTO settings (`key`, `value`) VALUES
    ('restore_check_evidence_days', '365'),
    ('restore_check_max_concurrent', '1'),
    ('email_on_restore_check_failed', '1'),
    ('email_on_restore_check_warning', '0'),
    ('email_on_restore_check_overdue', '1'),
    ('email_on_restore_check_cleanup_failed', '1');
