-- Track backup jobs that completed with warnings (#203).
-- borg returns exit code 1 for warnings (e.g. a configured source path
-- doesn't exist) but still creates an archive. Without surfacing this
-- the user sees a green "completed" job and may not notice that nothing
-- was actually backed up. The agent now reports had_warnings=1 in this
-- case; the server uses the flag to fire a backup_warning notification
-- and to decorate the UI.

ALTER TABLE backup_jobs
    ADD COLUMN had_warnings TINYINT(1) NOT NULL DEFAULT 0;
