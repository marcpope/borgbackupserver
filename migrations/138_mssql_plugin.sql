-- Migration 138: Microsoft SQL Server Backup/Restore plugin
--
-- Native BACKUP DATABASE ... COPY_ONLY dumps (.bak) taken by the client agent
-- before each backup and stored in the borg archive; restored with
-- RESTORE DATABASE from the Restore tab.

INSERT IGNORE INTO plugins (slug, name, description, plugin_type) VALUES
('mssql_dump', 'Microsoft SQL Server Backup/Restore', 'Backs up Microsoft SQL Server databases (native COPY_ONLY .bak, no impact on existing backup chains) before each backup, storing them in the repository for one-click restore back to the server.', 'pre_backup');

ALTER TABLE backup_jobs MODIFY COLUMN task_type ENUM(
    'backup', 'backup_dry_run', 'prune', 'restore', 'restore_mysql', 'restore_pg', 'restore_mongo', 'restore_mssql',
    'check', 'compact', 'update_borg', 'update_agent', 'plugin_test', 'plugin_post', 's3_sync',
    'repo_check', 'repo_repair', 'break_lock', 's3_restore', 'catalog_sync', 'catalog_rebuild',
    'catalog_rebuild_full', 'archive_delete', 'list_dir', 'archive_lock'
) NOT NULL DEFAULT 'backup';
