-- Full VM restore (hypervisors, step 3). A restore_vm job restores one VM
-- archive (restore_archive_id, vm_ref) as a new VM on the hypervisor; its
-- target (VMID, storage, name, start) is in restore_options.
ALTER TABLE backup_jobs MODIFY COLUMN task_type ENUM('backup', 'backup_dry_run', 'prune', 'restore', 'restore_mysql', 'restore_pg', 'restore_mongo', 'restore_mssql', 'restore_vm', 'check', 'compact', 'update_borg', 'update_agent', 'plugin_test', 'plugin_post', 's3_sync', 'repo_check', 'repo_repair', 'break_lock', 's3_restore', 'catalog_sync', 'catalog_rebuild', 'catalog_rebuild_full', 'archive_delete', 'list_dir', 'archive_lock') NOT NULL DEFAULT 'backup';

ALTER TABLE backup_jobs ADD COLUMN restore_options JSON DEFAULT NULL AFTER restore_databases;
