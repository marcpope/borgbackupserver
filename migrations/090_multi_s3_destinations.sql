-- Allow a repository to replicate to multiple S3 destinations (#263).
-- The old unique key allowed exactly one repository_s3_configs row per
-- repo; the new one allows one row per (repo, destination config) pair.
--
-- The new key is added BEFORE the old is dropped: the repository_id
-- foreign key needs an index starting with repository_id, and MySQL
-- refuses to drop the only one (errno 1553).
ALTER TABLE repository_s3_configs ADD UNIQUE KEY unique_repo_s3_dest (repository_id, plugin_config_id);
ALTER TABLE repository_s3_configs DROP INDEX unique_repo_s3;
