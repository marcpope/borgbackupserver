-- The SHA-256 digest GitHub publishes for each borg release asset. The
-- server's borg update refuses a download that does not match it.
ALTER TABLE borg_version_assets ADD COLUMN sha256 CHAR(64) DEFAULT NULL AFTER file_size;
