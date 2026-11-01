-- Per-storage job limits (#154). A spinning disk can run its jobs one at a
-- time while an SSD beside it runs several. NULL means no limit of its own;
-- the global Max Concurrent Jobs setting still applies and caps these.
ALTER TABLE storage_locations ADD COLUMN max_concurrent TINYINT UNSIGNED DEFAULT NULL AFTER capacity_bytes;
ALTER TABLE remote_ssh_configs ADD COLUMN max_concurrent TINYINT UNSIGNED DEFAULT NULL AFTER append_repo_name;
