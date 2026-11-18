-- Patterns-file content for backup plans and templates. Unlike Excludes
-- (one bare pattern per line, sent as --exclude=), a patterns file supports
-- borg's full pattern language: R roots, P prefixes, + includes, - excludes
-- and ! no-recurse excludes. The server passes the content to the agent,
-- which materializes it to a temp file and runs borg with --patterns-from.
-- Requires agent >= 2.102.0; the queue refuses to send patterns to older
-- agents rather than run a backup that silently ignores them.
ALTER TABLE backup_plans ADD COLUMN patterns TEXT DEFAULT NULL AFTER excludes;
ALTER TABLE backup_templates ADD COLUMN patterns TEXT DEFAULT NULL AFTER excludes;
