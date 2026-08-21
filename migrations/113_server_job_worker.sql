-- Identify the process running a server-side job (#438).
--
-- Since server-side jobs moved into their own process, a worker that dies —
-- container restart, OOM kill, the machine rebooting mid-prune — leaves its job
-- in 'running' forever. That holds the repository, so every backup to it queues
-- behind a job nothing is working on, and it occupies a queue slot.
--
-- Recording which process owns a job makes "the worker is gone" a fact the
-- scheduler can check, rather than a timeout that has to guess how long a
-- legitimate prune or 300 GB sync should take.

ALTER TABLE backup_jobs
    ADD COLUMN worker_pid INT DEFAULT NULL AFTER last_progress_at,
    ADD COLUMN worker_host VARCHAR(64) DEFAULT NULL AFTER worker_pid;
