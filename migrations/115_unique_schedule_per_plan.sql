-- A plan has one schedule, but nothing enforced it (#457): a duplicate row
-- from an old bug showed the plan twice on the Schedules page. The
-- scheduler runs one job regardless, so removing the extras loses nothing:
-- keep the oldest row per plan, then make the duplication impossible.
DELETE s2 FROM schedules s2
    JOIN schedules s1 ON s1.backup_plan_id = s2.backup_plan_id AND s1.id < s2.id;
ALTER TABLE schedules ADD UNIQUE KEY uniq_schedule_plan (backup_plan_id);
