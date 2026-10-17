<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\ClientProfileService;
use BBS\Services\PermissionService;
use BBS\Services\RestoreCheckPlanService;
use BBS\Services\RestoreCheckReport;

/**
 * Restore checks on a client's Restore tab: plans, Run now, history and
 * evidence reports.
 *
 * Viewing evidence and running a check need Perform Restores on the client;
 * creating, editing, enabling and deleting plans need Manage Plans as well.
 * Every action re-checks both here, not only in the links that lead to it.
 */
class RestoreCheckController extends Controller
{
    private function service(): RestoreCheckPlanService
    {
        return new RestoreCheckPlanService($this->db);
    }

    /** Client the user can see, or a redirect away. */
    private function agentOrFail(int $id): array
    {
        $agent = $this->canAccessAgent($id)
            ? $this->db->fetchOne("SELECT id, name FROM agents WHERE id = ?", [$id])
            : null;
        if (!$agent) {
            $this->flash('danger', 'Client not found.');
            $this->redirect('/clients');
        }
        return $agent;
    }

    private function requireManage(int $agentId): void
    {
        $this->requirePermission(PermissionService::RESTORE, $agentId);
        $this->requirePermission(PermissionService::MANAGE_PLANS, $agentId);
    }

    private function planOrFail(int $agentId, int $planId): array
    {
        $plan = $this->db->fetchOne("SELECT * FROM restore_check_plans WHERE id = ? AND agent_id = ?", [$planId, $agentId]);
        if (!$plan) {
            $this->flash('danger', 'Restore check not found.');
            $this->redirect("/clients/{$agentId}?tab=restore");
        }
        return $plan;
    }

    private function back(int $agentId): void
    {
        $this->redirect("/clients/{$agentId}?tab=restore#restore-checks");
    }

    // ------------------------------------------------------------------
    // Plans
    // ------------------------------------------------------------------

    public function create(int $id): void
    {
        $this->requireAuth();
        $agent = $this->agentOrFail($id);
        $this->requireManage($id);
        $profileTz = (new ClientProfileService())->planDefaults($id)['timezone'] ?? null;
        $this->renderForm($agent, RestoreCheckPlanService::defaults($profileTz ?: ($_SESSION['timezone'] ?? 'UTC')), []);
    }

    public function store(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();
        $agent = $this->agentOrFail($id);
        $this->requireManage($id);

        [$data, $errors] = $this->service()->validateInput($id, $_POST);
        if ($errors) {
            $this->renderForm($agent, array_merge(RestoreCheckPlanService::defaults('UTC'), $data, ['paths' => $data['paths'] ?? '[]']), $errors);
            return;
        }
        // Always created disabled: the operator reviews it, then enables it
        // or runs it once.
        $data['agent_id'] = $id;
        $data['enabled'] = 0;
        $data['created_by'] = (int) $_SESSION['user_id'];
        $data['owner_user_id'] = (int) $_SESSION['user_id'];
        $planId = $this->db->insert('restore_check_plans', $data);
        $this->auditLog($id, "Restore check \"{$data['name']}\" (#{$planId}) created, disabled");
        $this->flash('success', 'Restore check created. It is disabled: review it, then enable it or use Run now.');
        $this->redirect("/clients/{$id}/restore-checks/{$planId}");
    }

    public function edit(int $id, int $planId): void
    {
        $this->requireAuth();
        $agent = $this->agentOrFail($id);
        $this->requireManage($id);
        $plan = $this->planOrFail($id, $planId);
        $this->renderForm($agent, $plan, []);
    }

    public function update(int $id, int $planId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();
        $agent = $this->agentOrFail($id);
        $this->requireManage($id);
        $plan = $this->planOrFail($id, $planId);

        [$data, $errors] = $this->service()->validateInput($id, $_POST);
        if ($errors) {
            $this->renderForm($agent, array_merge($plan, $data), $errors);
            return;
        }
        // Runs already queued keep the settings they were queued with; the
        // revision says which settings a run used.
        $changed = false;
        foreach (RestoreCheckPlanService::REVISION_FIELDS as $f) {
            // MySQL reformats JSON columns, so compare paths by value.
            $old = $f === 'paths' ? json_decode((string) ($plan[$f] ?? 'null'), true) : (string) ($plan[$f] ?? '');
            $new = $f === 'paths' ? json_decode((string) ($data[$f] ?? 'null'), true) : (string) ($data[$f] ?? '');
            if ($f === 'paths' ? $old != $new : $old !== $new) {
                $changed = true;
                break;
            }
        }
        if ($changed) {
            $data['config_revision'] = (int) $plan['config_revision'] + 1;
        }
        if ((int) $plan['enabled'] === 1) {
            $next = \BBS\Services\RestoreCheckSchedule::nextOccurrence(
                array_merge($plan, $data),
                new \DateTimeImmutable($this->db->now(), new \DateTimeZone('UTC'))
            );
            $data['next_due_at'] = $next?->format('Y-m-d H:i:s');
        }
        $this->db->update('restore_check_plans', $data, 'id = ?', [$planId]);
        $this->auditLog($id, "Restore check \"{$data['name']}\" (#{$planId}) edited" . ($changed ? ', revision ' . $data['config_revision'] : ''));
        $this->flash('success', 'Restore check saved.');
        $this->redirect("/clients/{$id}/restore-checks/{$planId}");
    }

    public function enable(int $id, int $planId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();
        $this->agentOrFail($id);
        $this->requireManage($id);
        $plan = $this->planOrFail($id, $planId);

        $next = $this->service()->enable($plan, (int) $_SESSION['user_id']);
        if ($next === null) {
            $this->flash('danger', 'This schedule never fires; check its days and times.');
        } else {
            $this->auditLog($id, "Restore check \"{$plan['name']}\" (#{$planId}) enabled; scheduled runs act for " . ($_SESSION['username'] ?? 'user #' . $_SESSION['user_id']));
            $this->flash('success', 'Restore check enabled. Scheduled runs act with your restore permission.');
        }
        $this->redirect("/clients/{$id}/restore-checks/{$planId}");
    }

    public function disable(int $id, int $planId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();
        $this->agentOrFail($id);
        $this->requireManage($id);
        $plan = $this->planOrFail($id, $planId);
        // Pausing stops new runs only. A queued or running one is left alone;
        // cancel it from the queue if it should not happen.
        $this->db->update('restore_check_plans', ['enabled' => 0, 'next_due_at' => null], 'id = ?', [$planId]);
        (new \BBS\Services\NotificationService())->resolve('restore_check_overdue', $id, $planId);
        $this->auditLog($id, "Restore check \"{$plan['name']}\" (#{$planId}) disabled");
        $this->flash('success', 'Restore check disabled. Runs already queued are not affected; cancel them from the queue if needed.');
        $this->redirect("/clients/{$id}/restore-checks/{$planId}");
    }

    public function delete(int $id, int $planId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();
        $this->agentOrFail($id);
        $this->requireManage($id);
        $plan = $this->planOrFail($id, $planId);

        $running = $this->db->fetchOne("
            SELECT r.id FROM restore_check_runs r JOIN backup_jobs bj ON bj.id = r.backup_job_id
            WHERE r.plan_id = ? AND r.result IS NULL AND bj.status IN ('sent', 'running')
        ", [$planId]);
        if ($running) {
            $this->flash('danger', 'A run of this check is in progress. Cancel it from the queue, wait for its cleanup, then delete the check.');
            $this->redirect("/clients/{$id}/restore-checks/{$planId}");
        }
        // Queued runs that have not started are cancelled; reconciliation
        // closes their runs. History is kept: runs lose the plan link only.
        $this->db->query("
            UPDATE backup_jobs SET status = 'cancelled', completed_at = NOW(), error_log = 'Restore check deleted'
            WHERE status = 'queued' AND id IN (SELECT backup_job_id FROM restore_check_runs WHERE plan_id = ? AND result IS NULL)
        ", [$planId]);
        $notifier = new \BBS\Services\NotificationService();
        foreach (['restore_check_overdue', 'restore_check_failed', 'restore_check_warning'] as $type) {
            $notifier->resolve($type, $id, $planId);
        }
        $this->db->delete('restore_check_plans', 'id = ?', [$planId]);
        $this->auditLog($id, "Restore check \"{$plan['name']}\" (#{$planId}) deleted; its run history is kept");
        $this->flash('success', 'Restore check deleted. Its run history and reports are kept.');
        $this->back($id);
    }

    /** Run once now. Does not enable the schedule. */
    public function runNow(int $id, int $planId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();
        $this->agentOrFail($id);
        $this->requirePermission(PermissionService::RESTORE, $id);
        $plan = $this->planOrFail($id, $planId);

        $key = (string) ($_POST['idempotency_key'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/', $key)) {
            $key = bin2hex(random_bytes(16));
        }
        $result = $this->service()->enqueue($planId, 'manual', (int) $_SESSION['user_id'], 'u' . (int) $_SESSION['user_id'] . '-' . $key);
        if (isset($result['error'])) {
            $this->flash($result['error'] === 'already_active' ? 'warning' : 'danger', $result['message']);
            if (!empty($result['run_id'])) {
                $this->redirect("/clients/{$id}/restore-checks/runs/{$result['run_id']}");
            }
            $this->redirect("/clients/{$id}/restore-checks/{$planId}");
        }
        if ($result['created']) {
            $this->flash('success', "Restore check \"{$plan['name']}\" queued. It runs on the BBS server; the client does not need to be online.");
        }
        $this->redirect("/clients/{$id}/restore-checks/runs/{$result['run_id']}");
    }

    /** Plan page: settings summary and run history. */
    public function show(int $id, int $planId): void
    {
        $this->requireAuth();
        $agent = $this->agentOrFail($id);
        $this->requirePermission(PermissionService::RESTORE, $id);
        $plan = $this->planOrFail($id, $planId);
        $summary = array_values(array_filter(
            $this->service()->summaryForAgent($id),
            fn($p) => (int) $p['id'] === $planId
        ))[0] ?? $plan;
        $runs = $this->db->fetchAll(
            "SELECT * FROM restore_check_runs WHERE plan_id = ? ORDER BY id DESC LIMIT 200",
            [$planId]
        );
        $this->view('restore-checks/show', [
            'pageTitle' => 'Restore check',
            'agent' => $agent,
            'plan' => $summary,
            'runs' => $runs,
            'canManage' => $this->hasPermission(PermissionService::MANAGE_PLANS, $id),
            'runNowKey' => bin2hex(random_bytes(16)),
        ]);
    }

    // ------------------------------------------------------------------
    // Runs and reports
    // ------------------------------------------------------------------

    private function runOrFail(int $agentId, int $runId): array
    {
        $this->agentOrFail($agentId);
        $this->requirePermission(PermissionService::RESTORE, $agentId);
        $run = $this->db->fetchOne("SELECT * FROM restore_check_runs WHERE id = ? AND agent_id = ?", [$runId, $agentId]);
        if (!$run) {
            $this->flash('danger', 'Restore check run not found.');
            $this->redirect("/clients/{$agentId}?tab=restore");
        }
        return $run;
    }

    public function run(int $id, int $runId): void
    {
        $this->requireAuth();
        $run = $this->runOrFail($id, $runId);
        $this->renderRun($run, "/clients/{$id}/restore-checks/runs/{$runId}");
    }

    public function reportJson(int $id, int $runId): void
    {
        $this->requireAuth();
        $this->sendJson($this->runOrFail($id, $runId));
    }

    public function reportPrint(int $id, int $runId): void
    {
        $this->requireAuth();
        $this->sendPrint($this->runOrFail($id, $runId));
    }

    // Evidence whose client was deleted stays readable by admins only.

    public function adminIndex(): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $runs = $this->db->fetchAll("
            SELECT r.*, (a.id IS NULL) AS client_deleted
            FROM restore_check_runs r LEFT JOIN agents a ON a.id = r.agent_id
            WHERE r.result IS NOT NULL
            ORDER BY r.id DESC LIMIT 500
        ");
        $this->view('restore-checks/admin', ['pageTitle' => 'Restore check evidence', 'runs' => $runs]);
    }

    public function adminRun(int $runId): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->renderRun($this->adminRunOrFail($runId), "/restore-checks/runs/{$runId}");
    }

    public function adminReportJson(int $runId): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->sendJson($this->adminRunOrFail($runId));
    }

    public function adminReportPrint(int $runId): void
    {
        $this->requireAuth();
        $this->requireAdmin();
        $this->sendPrint($this->adminRunOrFail($runId));
    }

    private function adminRunOrFail(int $runId): array
    {
        $run = $this->db->fetchOne("SELECT * FROM restore_check_runs WHERE id = ?", [$runId]);
        if (!$run) {
            $this->flash('danger', 'Restore check run not found.');
            $this->redirect('/restore-checks');
        }
        return $run;
    }

    private function renderRun(array $run, string $base): void
    {
        $job = $run['backup_job_id']
            ? $this->db->fetchOne("SELECT id, status, status_message, files_total, files_processed FROM backup_jobs WHERE id = ?", [$run['backup_job_id']])
            : null;
        $report = $run['report_json'] ? json_decode($run['report_json'], true) : RestoreCheckReport::build($run);
        $this->view('restore-checks/run', [
            'pageTitle' => 'Restore check run #' . $run['id'],
            'run' => $run,
            'job' => $job,
            'report' => $report,
            'base' => $base,
            'canCancel' => $job && in_array($job['status'], ['queued', 'sent', 'running'], true),
        ]);
    }

    /** The stored report, byte for byte, so its digest can be checked. */
    private function sendJson(array $run): void
    {
        if ($run['report_json'] === null) {
            $this->json(['error' => 'This run has not finished; its report does not exist yet.'], 409);
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="restore-check-run-' . (int) $run['id'] . '.json"');
        header('X-Content-Type-Options: nosniff');
        header('X-Report-SHA256: ' . $run['report_digest']);
        header('Cache-Control: no-store');
        echo $run['report_json'];
        exit;
    }

    private function sendPrint(array $run): void
    {
        if ($run['report_json'] === null) {
            $this->flash('warning', 'This run has not finished; its report does not exist yet.');
            $this->redirect('/queue');
        }
        $report = json_decode($run['report_json'], true);
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        $digest = $run['report_digest'];
        require dirname(__DIR__) . '/Views/restore-checks/print.php';
        exit;
    }

    // ------------------------------------------------------------------

    private function renderForm(array $agent, array $plan, array $errors): void
    {
        $agentId = (int) $agent['id'];
        $repositories = $this->db->fetchAll(
            "SELECT id, name, storage_type FROM repositories WHERE agent_id = ? AND read_only = 0 ORDER BY name",
            [$agentId]
        );
        $backupPlans = $this->db->fetchAll(
            "SELECT id, name, repository_id FROM backup_plans WHERE agent_id = ? ORDER BY name",
            [$agentId]
        );
        $archives = $this->db->fetchAll("
            SELECT a.id, a.archive_name, a.repository_id, a.created_at, bj.backup_plan_id
            FROM archives a JOIN repositories r ON r.id = a.repository_id
            LEFT JOIN backup_jobs bj ON bj.id = a.backup_job_id
            WHERE r.agent_id = ? ORDER BY a.created_at DESC LIMIT 300
        ", [$agentId]);
        $clickhouseAvailable = false;
        try {
            $clickhouseAvailable = \BBS\Core\ClickHouse::getInstance()->isAvailable();
        } catch (\Throwable) {
        }
        $this->view('restore-checks/form', [
            'pageTitle' => empty($plan['id']) ? 'New restore check' : 'Edit restore check',
            'agent' => $agent,
            'plan' => $plan,
            'errors' => $errors,
            'repositories' => $repositories,
            'backupPlans' => $backupPlans,
            'archives' => $archives,
            'clickhouseAvailable' => $clickhouseAvailable,
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    private function auditLog(int $agentId, string $message): void
    {
        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'level' => 'info',
            'message' => $message . ' by ' . ($_SESSION['username'] ?? 'user #' . ($_SESSION['user_id'] ?? '?')),
        ]);
    }
}
