-- File-level browsing of VM backups (hypervisors, step 4). A vm_browse job
-- asks the hypervisor agent to open a VM restore point (mount it, read its
-- partitions, volume groups and filesystems), list a directory of one of
-- its volumes, or prepare a download. The request is in restore_options,
-- the answer in task_result.
ALTER TABLE backup_jobs MODIFY COLUMN task_type ENUM('backup', 'backup_dry_run', 'prune', 'restore', 'restore_mysql', 'restore_pg', 'restore_mongo', 'restore_mssql', 'restore_vm', 'check', 'compact', 'update_borg', 'update_agent', 'plugin_test', 'plugin_post', 's3_sync', 'repo_check', 'repo_repair', 'break_lock', 's3_restore', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete', 'list_dir', 'archive_lock', 'vm_browse') NOT NULL DEFAULT 'backup';
