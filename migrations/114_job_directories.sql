-- What a backup job actually ran with, frozen when the task is handed to
-- the agent (#452). Jobs from before this column stay NULL and fall back
-- to the plan's current directories on display.
ALTER TABLE backup_jobs ADD COLUMN directories TEXT DEFAULT NULL AFTER plugin_config_id;
