<?php

namespace BBS\Services;

use BBS\Core\Database;
use BBS\Services\NotificationService;

class SchedulerService
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Check all enabled schedules and create queued jobs for any that are due.
     * Should be called periodically (e.g., every minute via cron).
     */
    public function run(): array
    {
        // Skip if maintenance mode is active
        $maintenance = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'maintenance_mode'");
        if (($maintenance['value'] ?? '0') === '1') {
            return [];
        }

        $now = date('Y-m-d H:i:s');

        // An enabled schedule with no next_run can never be selected below, and
        // nothing else fills it in, so it is silently dead: shown as active,
        // never queued, and only a manual run works. Give it one before the
        // due-query runs (#420).
        //
        // Deliberately the *next* occurrence rather than "now" — a schedule
        // that has been dead for a week should resume tonight, not fire a
        // week's worth of catch-up backups the moment it is repaired.
        $orphans = $this->db->fetchAll("
            SELECT s.* FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            WHERE s.enabled = 1 AND bp.enabled = 1 AND s.next_run IS NULL
        ");
        foreach ($orphans as $orphan) {
            $repaired = $this->calculateNextRun($orphan);
            if ($repaired === null) {
                continue;
            }
            $this->db->update('schedules', ['next_run' => $repaired], 'id = ?', [$orphan['id']]);
            $this->db->insert('server_log', [
                'level' => 'warning',
                'message' => "Schedule #{$orphan['id']} had no next run time and would never have fired; set to {$repaired}",
            ]);
        }

        // Find schedules that are due
        $notificationService = new NotificationService();

        $dueSchedules = $this->db->fetchAll("
            SELECT s.*, bp.agent_id, bp.repository_id, bp.name as plan_name
            FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            JOIN agents a ON a.id = bp.agent_id
            WHERE s.enabled = 1
              AND s.next_run IS NOT NULL
              AND s.next_run <= ?
              AND bp.enabled = 1
              AND (a.status IN ('online', 'setup')
                   OR (a.status = 'offline' AND a.wol_enabled = 1))
        ", [$now]);

        $created = [];

        foreach ($dueSchedules as $schedule) {
            // Check if there's already a pending/running job for this plan
            $existing = $this->db->fetchOne("
                SELECT id FROM backup_jobs
                WHERE backup_plan_id = ?
                  AND status IN ('queued', 'sent', 'running')
            ", [$schedule['backup_plan_id']]);

            if ($existing) {
                // Skip — already has a job in progress for this plan.
                // Still advance next_run so the dashboard doesn't show "Overdue"
                $nextRun = $this->calculateNextRun($schedule);
                $this->db->update('schedules', [
                    'next_run' => $nextRun,
                ], 'id = ?', [$schedule['id']]);
                continue;
            }
            // Note: different plans on the same repo are allowed to queue.
            // The QueueManager enforces repo-level locking at promotion time
            // and will hold them until the repo is free.

            // Create queued job
            $jobId = $this->db->insert('backup_jobs', [
                'backup_plan_id' => $schedule['backup_plan_id'],
                'agent_id' => $schedule['agent_id'],
                'repository_id' => $schedule['repository_id'],
                'task_type' => 'backup',
                'status' => 'queued',
            ]);

            // Log it
            $this->db->insert('server_log', [
                'agent_id' => $schedule['agent_id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "Scheduled backup queued for plan \"{$schedule['plan_name']}\"",
            ]);

            // Calculate and set next_run
            $nextRun = $this->calculateNextRun($schedule);
            $this->db->update('schedules', [
                'last_run' => $now,
                'next_run' => $nextRun,
            ], 'id = ?', [$schedule['id']]);

            // Resolve any missed_schedule notification for this plan
            $notificationService->resolve('missed_schedule', $schedule['agent_id'], $schedule['backup_plan_id']);

            $created[] = [
                'job_id' => $jobId,
                'plan' => $schedule['plan_name'],
                'agent_id' => $schedule['agent_id'],
            ];
        }

        // Check for overdue schedules where agent is offline (missed_schedule)
        $overdueSchedules = $this->db->fetchAll("
            SELECT s.*, bp.agent_id, bp.name as plan_name, a.name as agent_name
            FROM schedules s
            JOIN backup_plans bp ON bp.id = s.backup_plan_id
            JOIN agents a ON a.id = bp.agent_id
            WHERE s.enabled = 1
              AND s.next_run IS NOT NULL
              AND s.next_run <= ?
              AND bp.enabled = 1
              AND a.status = 'offline'
              AND a.wol_enabled = 0
        ", [$now]);

        foreach ($overdueSchedules as $sched) {
            // Skip if we already have an unresolved missed_schedule notification
            // for this (agent, plan). Without this, calling notify() every minute
            // increments occurrence_count on the existing row (and resets read_at
            // to NULL), so an agent that's been offline for days can accumulate
            // thousands of occurrences and refuse to stay "read". The agent_offline
            // notification already covers the broader condition — one
            // missed_schedule per plan per offline period is enough.
            $existing = $this->db->fetchOne(
                "SELECT id FROM notifications
                 WHERE type = 'missed_schedule'
                   AND agent_id = ? AND reference_id = ? AND resolved_at IS NULL
                 LIMIT 1",
                [$sched['agent_id'], $sched['backup_plan_id']]
            );
            if ($existing) continue;

            $notificationService->notify(
                'missed_schedule',
                $sched['agent_id'],
                $sched['backup_plan_id'],
                "Missed schedule for plan \"{$sched['plan_name']}\" — client \"{$sched['agent_name']}\" is offline"
            );
        }

        return $created;
    }

    /**
     * The next time a schedule should fire, in UTC.
     *
     * Public because anything that rewrites a schedule's frequency, times or
     * timezone has to recompute this at the same moment — leaving it stale is
     * merely wrong, but leaving it NULL stops the schedule forever (#420).
     */
    public function calculateNextRun(array $schedule): ?string
    {
        $scheduleTz = new \DateTimeZone($schedule['timezone'] ?? 'UTC');
        $utcTz = new \DateTimeZone('UTC');
        $now = new \DateTime('now', $utcTz);

        $intervals = [
            '10min' => 'PT10M',
            '15min' => 'PT15M',
            '30min' => 'PT30M',
            'hourly' => 'PT1H',
        ];

        // Interval-based: timezone doesn't matter, just add interval to UTC now
        if (isset($intervals[$schedule['frequency']])) {
            $now->add(new \DateInterval($intervals[$schedule['frequency']]));
            return $now->format('Y-m-d H:i:s');
        }

        $timeList = array_filter(array_map('trim', explode(',', $schedule['times'] ?? '')));

        // For time-of-day schedules, compute in the schedule's local timezone then convert to UTC
        $nowLocal = clone $now;
        $nowLocal->setTimezone($scheduleTz);

        if ($schedule['frequency'] === 'daily' && !empty($timeList)) {
            $today = new \DateTime('today', $scheduleTz);
            foreach ($timeList as $time) {
                $candidate = clone $today;
                $parts = explode(':', $time);
                $candidate->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0));
                if ($candidate > $nowLocal) {
                    $candidate->setTimezone($utcTz);
                    return $candidate->format('Y-m-d H:i:s');
                }
            }
            $tomorrow = new \DateTime('tomorrow', $scheduleTz);
            $parts = explode(':', $timeList[0]);
            $tomorrow->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0));
            $tomorrow->setTimezone($utcTz);
            return $tomorrow->format('Y-m-d H:i:s');
        }

        if ($schedule['frequency'] === 'weekly') {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $dayName = $days[$schedule['day_of_week'] ?? 1] ?? 'Monday';
            $firstTime = $timeList[0] ?? '01:00';
            $next = new \DateTime("next {$dayName} {$firstTime}", $scheduleTz);
            $next->setTimezone($utcTz);
            return $next->format('Y-m-d H:i:s');
        }

        if ($schedule['frequency'] === 'monthly') {
            $dayOfMonth = $schedule['day_of_month'] ?? '1';
            $firstTime = $timeList[0] ?? '01:00';
            $timeParts = array_map('intval', explode(':', $firstTime));

            if ($dayOfMonth === 'last') {
                $next = new \DateTime('now', $scheduleTz);
                $next->modify('last day of this month');
                $next->setTime(...$timeParts);
                if ($next <= $nowLocal) {
                    $next->modify('last day of next month');
                    $next->setTime(...$timeParts);
                }
                $next->setTimezone($utcTz);
                return $next->format('Y-m-d H:i:s');
            }

            $days = array_map('intval', explode(',', (string) $dayOfMonth));
            $best = null;
            foreach ($days as $dom) {
                $dom = min($dom, 28);
                $candidate = new \DateTime('now', $scheduleTz);
                $candidate->setDate((int)$candidate->format('Y'), (int)$candidate->format('m'), $dom);
                $candidate->setTime(...$timeParts);
                if ($candidate <= $nowLocal) {
                    $candidate->modify('+1 month');
                    $candidate->setDate((int)$candidate->format('Y'), (int)$candidate->format('m'), $dom);
                    $candidate->setTime(...$timeParts);
                }
                if ($best === null || $candidate < $best) {
                    $best = $candidate;
                }
            }
            if ($best) {
                $best->setTimezone($utcTz);
                return $best->format('Y-m-d H:i:s');
            }
        }

        return null;
    }
}
