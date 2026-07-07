-- Client owners never actually received access — agents.user_id was a
-- label the permission system ignored (#337). Backfill visibility for
-- every existing owner assignment. Permissions are NOT backfilled: any
-- install that used owners also assigned permissions manually, and
-- re-adding all five would silently expand deliberately limited users.
-- Newly assigned owners get all permissions by default going forward.
INSERT IGNORE INTO user_agents (user_id, agent_id)
SELECT user_id, id FROM agents WHERE user_id IS NOT NULL;
