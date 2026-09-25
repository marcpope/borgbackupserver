-- How many offsite syncs and restores may run at once, across every
-- destination. Server-side jobs never counted against max_queue, so nothing
-- stopped a fleet finishing its prunes together from starting one rclone per
-- repository at the same moment. 0 = no limit (the previous behaviour).
INSERT INTO settings (`key`, `value`) VALUES ('s3_max_concurrent', '4')
    ON DUPLICATE KEY UPDATE `value` = `value`;
