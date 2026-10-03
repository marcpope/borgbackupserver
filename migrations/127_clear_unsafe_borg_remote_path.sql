-- Remote Borg Path must be a plain command name or path (GHSA-4jqx-9f92-rc8p).
-- A value that is not, such as one starting with "-", could be read as an ssh
-- option. Clear any such value so the host falls back to the default borg.
UPDATE remote_ssh_configs
SET borg_remote_path = NULL
WHERE borg_remote_path IS NOT NULL
  AND borg_remote_path <> ''
  AND borg_remote_path NOT REGEXP '^[A-Za-z0-9_./~][A-Za-z0-9_./~+-]*$';
