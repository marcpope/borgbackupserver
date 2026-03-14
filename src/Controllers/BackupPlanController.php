<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\PermissionService;
use BBS\Services\PluginManager;

class BackupPlanController extends Controller
{
    public function store(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $agentId = (int) ($_POST['agent_id'] ?? 0);
        $repositoryId = (int) ($_POST['repository_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $directories = trim($_POST['directories'] ?? '');
        $excludes = trim($_POST['excludes'] ?? '');
        $advancedOptions = trim($_POST['advanced_options'] ?? '');
        $frequency = $_POST['frequency'] ?? 'daily';
        $times = trim($_POST['times'] ?? '');
        $dayOfWeek = !empty($_POST['day_of_week']) ? (int) $_POST['day_of_week'] : null;
        $dayOfMonth = !empty($_POST['day_of_month']) ? $_POST['day_of_month'] : null;

        $pruneMinutes = (int) ($_POST['prune_minutes'] ?? 0);
        $pruneHours = (int) ($_POST['prune_hours'] ?? 0);
        $pruneDays = (int) ($_POST['prune_days'] ?? 7);
        $pruneWeeks = (int) ($_POST['prune_weeks'] ?? 4);
        $pruneMonths = (int) ($_POST['prune_months'] ?? 6);
        $pruneYears = (int) ($_POST['prune_years'] ?? 0);

        if (empty($name) || empty($agentId) || empty($repositoryId) || empty($directories)) {
            $this->flash('danger', 'Name, repository, and directories are required.');
            $this->redirect("/clients/{$agentId}?tab=schedules");
        }

        // Verify agent access and manage_plans permission
        $agent = $this->db->fetchOne("SELECT * FROM agents WHERE id = ?", [$agentId]);
        if (!$agent || !$this->canAccessAgent($agentId)) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/clients');
        }
        $this->requirePermission(PermissionService::MANAGE_PLANS, $agentId);

        // Verify repository belongs to agent
        $repo = $this->db->fetchOne("SELECT * FROM repositories WHERE id = ? AND agent_id = ?", [$repositoryId, $agentId]);
        if (!$repo) {
            $this->flash('danger', 'Repository not found for this client.');
            $this->redirect("/clients/{$agentId}?tab=schedules");
        }

        // Create backup plan
        $planId = $this->db->insert('backup_plans', [
            'agent_id' => $agentId,
            'repository_id' => $repositoryId,
            'name' => $name,
            'directories' => $directories,
            'excludes' => $excludes ?: null,
            'advanced_options' => $advancedOptions ?: null,
            'prune_minutes' => $pruneMinutes,
            'prune_hours' => $pruneHours,
            'prune_days' => $pruneDays,
            'prune_weeks' => $pruneWeeks,
            'prune_months' => $pruneMonths,
            'prune_years' => $pruneYears,
        ]);

        // Create associated schedule
        $nextRun = $this->calculateNextRun($frequency, $times, $dayOfWeek, $dayOfMonth);

        $this->db->insert('schedules', [
            'backup_plan_id' => $planId,
            'frequency' => $frequency,
            'times' => $times ?: null,
            'day_of_week' => $dayOfWeek,
            'day_of_month' => $dayOfMonth,
            'timezone' => $_SESSION['timezone'] ?? 'America/New_York',
            'enabled' => $frequency === 'manual' ? 0 : 1,
            'next_run' => $nextRun,
        ]);

        // Save plugin configurations
        $this->savePluginConfigs($planId);

        $this->flash('success', "Backup plan \"{$name}\" created with schedule.");
        $this->redirect("/clients/{$agentId}?tab=schedules");
    }

    public function update(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $plan = $this->getPlan($id);
        if (!$plan) {
            $this->flash('danger', 'Backup plan not found.');
            $this->redirect('/clients');
        }

        // Require manage_plans permission
        $this->requirePermission(PermissionService::MANAGE_PLANS, $plan['agent_id']);

        $data = [];
        if (isset($_POST['name'])) $data['name'] = trim($_POST['name']);
        if (isset($_POST['directories'])) $data['directories'] = trim($_POST['directories']);
        if (isset($_POST['excludes'])) $data['excludes'] = trim($_POST['excludes']) ?: null;
        if (isset($_POST['advanced_options'])) $data['advanced_options'] = trim($_POST['advanced_options']) ?: null;
        if (isset($_POST['repository_id'])) $data['repository_id'] = (int) $_POST['repository_id'];
        if (isset($_POST['prune_minutes'])) $data['prune_minutes'] = (int) $_POST['prune_minutes'];
        if (isset($_POST['prune_hours'])) $data['prune_hours'] = (int) $_POST['prune_hours'];
        if (isset($_POST['prune_days'])) $data['prune_days'] = (int) $_POST['prune_days'];
        if (isset($_POST['prune_weeks'])) $data['prune_weeks'] = (int) $_POST['prune_weeks'];
        if (isset($_POST['prune_months'])) $data['prune_months'] = (int) $_POST['prune_months'];
        if (isset($_POST['prune_years'])) $data['prune_years'] = (int) $_POST['prune_years'];

        if (!empty($data)) {
            $this->db->update('backup_plans', $data, 'id = ?', [$id]);
        }

        // Update schedule if frequency was submitted
        if (isset($_POST['frequency'])) {
            $frequency = $_POST['frequency'];
            $times = trim($_POST['times'] ?? '');
            $dayOfWeek = isset($_POST['day_of_week']) && $_POST['day_of_week'] !== '' ? (int) $_POST['day_of_week'] : null;
            $dayOfMonth = isset($_POST['day_of_month']) && $_POST['day_of_month'] !== '' ? $_POST['day_of_month'] : null;
            $nextRun = $this->calculateNextRun($frequency, $times, $dayOfWeek, $dayOfMonth);

            $this->db->update('schedules', [
                'frequency' => $frequency,
                'times' => $times ?: null,
                'day_of_week' => $dayOfWeek,
                'day_of_month' => $dayOfMonth,
                'timezone' => $_SESSION['timezone'] ?? 'America/New_York',
                'enabled' => $frequency === 'manual' ? 0 : 1,
                'next_run' => $nextRun,
            ], 'backup_plan_id = ?', [$id]);
        }

        // Update plugin configurations
        $this->savePluginConfigs($id);

        $this->flash('success', 'Backup plan updated.');
        $this->redirect("/clients/{$plan['agent_id']}?tab=schedules");
    }

    public function delete(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $plan = $this->getPlan($id);
        if (!$plan) {
            $this->flash('danger', 'Backup plan not found.');
            $this->redirect('/clients');
        }

        // Require manage_plans permission
        $this->requirePermission(PermissionService::MANAGE_PLANS, $plan['agent_id']);

        $agentId = $plan['agent_id'];
        $this->db->delete('backup_plans', 'id = ?', [$id]);
        $this->flash('success', "Backup plan \"{$plan['name']}\" deleted.");
        $this->redirect("/clients/{$agentId}?tab=schedules");
    }

    public function trigger(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $plan = $this->getPlan($id);
        if (!$plan) {
            $this->flash('danger', 'Backup plan not found.');
            $this->redirect('/clients');
        }

        // Require trigger_backup permission
        $this->requirePermission(PermissionService::TRIGGER_BACKUP, $plan['agent_id']);

        // Check if this plan already has an active job (no point running the same plan twice)
        $planBusy = $this->db->fetchOne("
            SELECT id FROM backup_jobs
            WHERE backup_plan_id = ?
              AND status IN ('queued', 'sent', 'running')
        ", [$id]);

        if ($planBusy) {
            $this->flash('warning', 'A backup for this plan is already queued or running.');
            $this->redirect("/clients/{$plan['agent_id']}?tab=backups");
            return;
        }

        // Create a queued backup job
        $jobId = $this->db->insert('backup_jobs', [
            'backup_plan_id' => $id,
            'agent_id' => $plan['agent_id'],
            'repository_id' => $plan['repository_id'],
            'task_type' => 'backup',
            'status' => 'queued',
        ]);

        $this->db->insert('server_log', [
            'agent_id' => $plan['agent_id'],
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "Manual backup triggered for plan \"{$plan['name']}\" (job #{$jobId})",
        ]);

        $this->redirect("/queue/{$jobId}");
    }

    public function duplicate(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $plan = $this->getPlan($id);
        if (!$plan) {
            $this->flash('danger', 'Backup plan not found.');
            $this->redirect('/clients');
        }

        $this->requirePermission(PermissionService::MANAGE_PLANS, $plan['agent_id']);

        // Duplicate the plan
        $newPlanId = $this->db->insert('backup_plans', [
            'agent_id' => $plan['agent_id'],
            'repository_id' => $plan['repository_id'],
            'name' => $plan['name'] . ' (Copy)',
            'directories' => $plan['directories'],
            'excludes' => $plan['excludes'],
            'advanced_options' => $plan['advanced_options'],
            'prune_minutes' => $plan['prune_minutes'],
            'prune_hours' => $plan['prune_hours'],
            'prune_days' => $plan['prune_days'],
            'prune_weeks' => $plan['prune_weeks'],
            'prune_months' => $plan['prune_months'],
            'prune_years' => $plan['prune_years'],
        ]);

        // Duplicate the schedule (paused)
        $schedule = $this->db->fetchOne("SELECT * FROM schedules WHERE backup_plan_id = ?", [$id]);
        if ($schedule) {
            $this->db->insert('schedules', [
                'backup_plan_id' => $newPlanId,
                'frequency' => $schedule['frequency'],
                'times' => $schedule['times'],
                'day_of_week' => $schedule['day_of_week'],
                'day_of_month' => $schedule['day_of_month'],
                'timezone' => $schedule['timezone'],
                'enabled' => 0,
                'next_run' => null,
            ]);
        }

        // Duplicate plugin configs
        $plugins = $this->db->fetchAll("SELECT * FROM backup_plan_plugins WHERE backup_plan_id = ?", [$id]);
        foreach ($plugins as $plugin) {
            $this->db->insert('backup_plan_plugins', [
                'backup_plan_id' => $newPlanId,
                'plugin_id' => $plugin['plugin_id'],
                'plugin_config_id' => $plugin['plugin_config_id'],
                'config' => $plugin['config'],
                'execution_order' => $plugin['execution_order'],
                'enabled' => $plugin['enabled'],
            ]);
        }

        $this->flash('success', "Backup plan duplicated as \"{$plan['name']} (Copy)\" (paused).");
        $this->redirect("/clients/{$plan['agent_id']}?tab=schedules");
    }

    private function getPlan(int $id): ?array
    {
        $plan = $this->db->fetchOne("
            SELECT bp.*, a.id as agent_id
            FROM backup_plans bp
            JOIN agents a ON a.id = bp.agent_id
            WHERE bp.id = ?
        ", [$id]);

        if (!$plan) return null;
        if (!$this->canAccessAgent($plan['agent_id'])) return null;

        return $plan;
    }

    private function savePluginConfigs(int $planId): void
    {
        $pluginConfigs = $_POST['plugin_config'] ?? [];

        // Clear existing
        $this->db->query("DELETE FROM backup_plan_plugins WHERE backup_plan_id = ?", [$planId]);

        $order = 0;
        foreach ($pluginConfigs as $pluginId => $configId) {
            if (empty($configId)) continue;
            $this->db->insert('backup_plan_plugins', [
                'backup_plan_id' => $planId,
                'plugin_id' => (int) $pluginId,
                'plugin_config_id' => (int) $configId,
                'config' => '{}',
                'execution_order' => $order++,
                'enabled' => 1,
            ]);
        }
    }

    private function calculateNextRun(string $frequency, string $times, ?int $dayOfWeek, ?int $dayOfMonth): ?string
    {
        if ($frequency === 'manual') {
            return null;
        }

        $userTz = new \DateTimeZone($_SESSION['timezone'] ?? 'America/New_York');
        $utcTz = new \DateTimeZone('UTC');
        $now = new \DateTime('now', $utcTz);

        // For interval-based frequencies, next run is now + interval (timezone irrelevant)
        $intervals = [
            '10min' => 'PT10M',
            '15min' => 'PT15M',
            '30min' => 'PT30M',
            'hourly' => 'PT1H',
        ];

        if (isset($intervals[$frequency])) {
            $now->add(new \DateInterval($intervals[$frequency]));
            return $now->format('Y-m-d H:i:s');
        }

        // For daily/weekly/monthly, compute in user's timezone then convert to UTC
        $nowLocal = clone $now;
        $nowLocal->setTimezone($userTz);

        $timeList = array_filter(array_map('trim', explode(',', $times)));
        $firstTime = !empty($timeList) ? $timeList[0] : '01:00';

        if ($frequency === 'daily' && !empty($timeList)) {
            $today = new \DateTime('today', $userTz);
            foreach ($timeList as $time) {
                $candidate = clone $today;
                $parts = explode(':', $time);
                $candidate->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0));
                if ($candidate > $nowLocal) {
                    $candidate->setTimezone($utcTz);
                    return $candidate->format('Y-m-d H:i:s');
                }
            }
            // All times today have passed — use first time tomorrow
            $tomorrow = new \DateTime('tomorrow', $userTz);
            $parts = explode(':', $timeList[0]);
            $tomorrow->setTime((int)($parts[0] ?? 0), (int)($parts[1] ?? 0));
            $tomorrow->setTimezone($utcTz);
            return $tomorrow->format('Y-m-d H:i:s');
        }

        if ($frequency === 'weekly' && $dayOfWeek !== null) {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $next = new \DateTime("next {$days[$dayOfWeek]} {$firstTime}", $userTz);
            $next->setTimezone($utcTz);
            return $next->format('Y-m-d H:i:s');
        }

        if ($frequency === 'monthly' && $dayOfMonth !== null) {
            $timeParts = array_map('intval', explode(':', $firstTime));

            // Parse day_of_month: could be "1", "1,15", or "last"
            if ($dayOfMonth === 'last') {
                $next = new \DateTime('now', $userTz);
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
                $candidate = new \DateTime('now', $userTz);
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

        // Fallback: 1 hour from now
        $now->add(new \DateInterval('PT1H'));
        return $now->format('Y-m-d H:i:s');
    }
}
