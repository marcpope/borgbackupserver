<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
use BBS\Services\Hypervisors\HypervisorBackupService;
use BBS\Services\Hypervisors\HypervisorConnector;
use BBS\Services\Hypervisors\HypervisorRegistry;
use BBS\Services\PermissionService;

/**
 * Hypervisors and their virtual machines.
 *
 * A hypervisor is added here, never by an agent: Add Hypervisor creates a
 * hypervisor agent (agents.agent_type = 'hypervisor') and the hypervisor
 * record, then shows the install command (install.sh --hypervisor).
 * Everything type-specific comes from the hypervisor's connector
 * (src/Services/Hypervisors), so this controller and its views work for
 * any connector.
 *
 * Access follows the hypervisor's agent.
 */
class HypervisorController extends Controller
{
    public function index(): void
    {
        $this->requireAuth();

        [$where, $params] = $this->getAgentWhereClause('a');

        $hypervisors = $this->db->fetchAll("
            SELECT h.*, a.name as agent_name, a.status as agent_status, a.agent_version,
                   (SELECT COUNT(*) FROM hypervisor_vms v WHERE v.hypervisor_id = h.id AND v.is_template = 0) as vm_count,
                   (SELECT COUNT(*) FROM hypervisor_vms v WHERE v.hypervisor_id = h.id AND v.status = 'running') as running_count
            FROM hypervisors h
            JOIN agents a ON a.id = h.agent_id
            WHERE {$where}
            ORDER BY h.name ASC, h.id ASC
        ", $params);

        foreach ($hypervisors as &$h) {
            $h['info'] = json_decode($h['info'] ?? '', true) ?: [];
            $h['connector'] = HypervisorRegistry::get($h['type']);
        }
        unset($h);

        $this->view('hypervisors/index', [
            'pageTitle' => 'Hypervisors',
            'hypervisors' => $hypervisors,
        ]);
    }

    /** Connector catalog, with the creation form for available ones. */
    public function add(): void
    {
        $this->requireCreate();

        $this->view('hypervisors/add', [
            'pageTitle' => 'Hypervisors',
            // Planned connectors stay in the registry but are not shown
            'connectors' => array_filter(HypervisorRegistry::all(), fn($c) => $c->isAvailable()),
            'users' => $this->isAdmin() ? $this->db->fetchAll("SELECT id, username FROM users ORDER BY username") : [],
        ]);
    }

    public function store(): void
    {
        $this->requireCreate();
        $this->verifyCsrf();

        $connector = HypervisorRegistry::available($_POST['type'] ?? null);
        if (!$connector) {
            $this->flash('danger', 'Choose a supported hypervisor type.');
            $this->redirect('/hypervisors/add');
        }
        if ($connector->deployment() !== HypervisorConnector::DEPLOY_AGENT_ON_HOST) {
            // Proxy connectors need a connection form and an existing proxy
            // agent; none is implemented yet.
            $this->flash('danger', 'This hypervisor type cannot be added yet.');
            $this->redirect('/hypervisors/add');
        }

        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
        if ($name === '') {
            $this->flash('danger', 'Hypervisor name is required.');
            $this->redirect('/hypervisors/add');
        }

        $userId = $this->isAdmin()
            ? (!empty($_POST['user_id']) ? (int) $_POST['user_id'] : null)
            : (int) $_SESSION['user_id'];

        try {
            $agentId = (new \BBS\Services\AgentProvisioningService())->create(
                $name, $userId, null, $_POST['location'] ?? '', 'hypervisor'
            );
        } catch (\RuntimeException $e) {
            $this->flash('danger', 'Cannot create hypervisor — ' . $e->getMessage());
            $this->redirect('/hypervisors/add');
        }

        $id = $this->db->insert('hypervisors', [
            'name' => $name,
            'type' => $connector->slug(),
            'agent_id' => $agentId,
            'agent_role' => 'host',
        ]);

        $this->flash('success', 'Hypervisor created. Run the install command below on the hypervisor, as root.');
        $this->redirect("/hypervisors/{$id}?tab=install");
    }

    public function detail(int $id): void
    {
        $this->requireAuth();

        $hypervisor = $this->getHypervisor($id);
        if (!$hypervisor) {
            $this->flash('danger', 'Hypervisor not found.');
            $this->redirect('/hypervisors');
        }

        $tab = in_array($_GET['tab'] ?? '', ['vms', 'backups', 'install', 'settings'], true) ? $_GET['tab'] : 'vms';
        $isOwnerOrAdmin = $this->isOwnerOrAdmin($hypervisor);
        if (in_array($tab, ['install', 'settings'], true) && !$isOwnerOrAdmin) {
            $tab = 'vms';
        }

        $vms = $this->db->fetchAll(
            "SELECT * FROM hypervisor_vms WHERE hypervisor_id = ? ORDER BY is_template ASC, CAST(vm_ref AS UNSIGNED) ASC, vm_ref ASC",
            [$id]
        );
        foreach ($vms as &$vm) {
            $vm['disks'] = json_decode($vm['disks'] ?? '', true) ?: [];
        }
        unset($vm);

        $installCmd = null;
        if ($tab === 'install') {
            $installCmd = $this->installCommand($hypervisor);
        }

        $backups = $tab === 'backups' ? $this->backupsTabData($hypervisor, $vms) : [];

        $this->view('hypervisors/detail', [
            'pageTitle' => 'Hypervisors',
            'hypervisor' => $hypervisor,
            'connector' => HypervisorRegistry::get($hypervisor['type']),
            'info' => json_decode($hypervisor['info'] ?? '', true) ?: [],
            'vms' => $vms,
            'tab' => $tab,
            'isOwnerOrAdmin' => $isOwnerOrAdmin,
            'installCmd' => $installCmd,
            'backups' => $backups,
        ]);
    }

    /** Plans, repositories and recent jobs for the Backups tab. */
    private function backupsTabData(array $hypervisor, array $vms): array
    {
        $agentId = (int) $hypervisor['agent_id'];
        $plans = $this->db->fetchAll("
            SELECT bp.*, r.name as repo_name, s.frequency, s.times, s.day_of_week, s.next_run, s.enabled as schedule_enabled
            FROM backup_plans bp
            JOIN repositories r ON r.id = bp.repository_id
            LEFT JOIN schedules s ON s.backup_plan_id = bp.id
            WHERE bp.hypervisor_id = ?
            ORDER BY bp.id
        ", [(int) $hypervisor['id']]);

        $service = new HypervisorBackupService();
        foreach ($plans as &$p) {
            $p['vms'] = $service->planVms($p);
            $p['vm_rows'] = [];
            foreach ($this->db->fetchAll("SELECT * FROM backup_plan_vms WHERE backup_plan_id = ?", [$p['id']]) as $r) {
                $r['exclude_disks'] = json_decode($r['exclude_disks'] ?? '[]', true) ?: [];
                $p['vm_rows'][$r['vm_ref']] = $r;
            }
        }
        unset($p);

        $jobs = $this->db->fetchAll("
            SELECT bj.id, bj.vm_ref, bj.task_type, bj.status, bj.status_message, bj.queued_at, bj.completed_at,
                   bj.duration_seconds, SUBSTRING(bj.error_log, 1, 300) as error_log, bp.name as plan_name,
                   ar.original_size, ar.deduplicated_size, ar.archive_name
            FROM backup_jobs bj
            JOIN backup_plans bp ON bp.id = bj.backup_plan_id AND bp.hypervisor_id = ?
            LEFT JOIN archives ar ON ar.backup_job_id = bj.id
            WHERE bj.task_type IN ('backup', 'prune')
            ORDER BY bj.id DESC LIMIT 25
        ", [(int) $hypervisor['id']]);

        return [
            'plans' => $plans,
            'repositories' => $this->db->fetchAll(
                "SELECT id, name FROM repositories WHERE agent_id = ? AND read_only = 0 ORDER BY name",
                [$agentId]
            ),
            'jobs' => $jobs,
            'vmNames' => array_column($vms, 'name', 'vm_ref'),
            'canManage' => $this->hasPermission(PermissionService::MANAGE_PLANS, $agentId),
            'canTrigger' => $this->hasPermission(PermissionService::TRIGGER_BACKUP, $agentId),
        ];
    }

    /**
     * Create a VM backup plan: repository, VMs (selected, or all but the
     * unticked ones), disks per VM, schedule and retention. It runs through
     * the ordinary backup pipeline as one job per VM (HypervisorBackupService).
     */
    public function storePlan(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $hypervisor = $this->getHypervisor($id);
        if (!$hypervisor) {
            $this->flash('danger', 'Hypervisor not found.');
            $this->redirect('/hypervisors');
        }
        $agentId = (int) $hypervisor['agent_id'];
        $back = "/hypervisors/{$id}?tab=backups";
        if (!$this->hasPermission(PermissionService::MANAGE_PLANS, $agentId)) {
            $this->flash('danger', 'You do not have permission to manage backup plans.');
            $this->redirect($back);
        }
        $connector = HypervisorRegistry::get($hypervisor['type']);
        if (!$connector || !$connector->can('backup')) {
            $this->flash('danger', 'This hypervisor type cannot back up VMs yet.');
            $this->redirect($back);
        }

        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
        $repoId = (int) ($_POST['repository_id'] ?? 0);
        $repo = $this->db->fetchOne(
            "SELECT id FROM repositories WHERE id = ? AND agent_id = ? AND read_only = 0",
            [$repoId, $agentId]
        );
        if ($name === '' || !$repo) {
            $this->flash('danger', 'A plan needs a name and one of this hypervisor\'s repositories.');
            $this->redirect($back);
        }

        $selection = ($_POST['vm_selection'] ?? 'selected') === 'all' ? 'all' : 'selected';
        $inventory = $this->db->fetchAll(
            "SELECT vm_ref, disks FROM hypervisor_vms WHERE hypervisor_id = ? AND is_template = 0",
            [$id]
        );
        $picked = array_map('strval', array_keys((array) ($_POST['vms'] ?? [])));
        $postedDisks = (array) ($_POST['disks'] ?? []);

        $rows = [];
        foreach ($inventory as $vm) {
            $ref = (string) $vm['vm_ref'];
            $included = in_array($ref, $picked, true);
            // Disks that could be backed up but were unticked are excluded
            $keep = array_map('strval', (array) ($postedDisks[$ref] ?? []));
            $exclude = [];
            foreach (json_decode($vm['disks'] ?? '[]', true) ?: [] as $d) {
                if (!empty($d['supported']) && !in_array((string) $d['key'], $keep, true)) {
                    $exclude[] = (string) $d['key'];
                }
            }
            if ($selection === 'selected' && !$included) {
                continue;
            }
            $rows[] = ['vm_ref' => $ref, 'exclude_vm' => $included ? 0 : 1, 'exclude_disks' => $exclude];
        }
        if ($selection === 'selected' && empty($rows)) {
            $this->flash('danger', 'Select at least one VM.');
            $this->redirect($back);
        }

        $frequency = in_array($_POST['frequency'] ?? '', ['manual', 'hourly', 'daily', 'weekly'], true) ? $_POST['frequency'] : 'daily';
        $time = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $_POST['time'] ?? '') ? $_POST['time'] : '01:00';
        $dayOfWeek = $frequency === 'weekly' ? (string) max(0, min(6, (int) ($_POST['day_of_week'] ?? 0))) : null;
        $keep = fn($k, $d) => max(0, min(1000, (int) ($_POST[$k] ?? $d)));

        $pdo = $this->db->getPdo();
        $pdo->beginTransaction();
        try {
            $planId = $this->db->insert('backup_plans', [
                'agent_id' => $agentId,
                'repository_id' => $repoId,
                'hypervisor_id' => $id,
                'vm_selection' => $selection,
                'name' => $name,
                'directories' => '',
                'prune_minutes' => 0,
                'prune_hours' => 0,
                'prune_days' => $keep('prune_days', 7),
                'prune_weeks' => $keep('prune_weeks', 4),
                'prune_months' => $keep('prune_months', 6),
                'prune_years' => 0,
            ]);
            foreach ($rows as $r) {
                $this->db->insert('backup_plan_vms', [
                    'backup_plan_id' => $planId,
                    'vm_ref' => $r['vm_ref'],
                    'exclude_vm' => $r['exclude_vm'],
                    'exclude_disks' => json_encode($r['exclude_disks']),
                ]);
            }

            $tz = (new \BBS\Services\ClientProfileService())->planTimezoneFor($agentId, $_SESSION['timezone'] ?? null);
            $schedule = [
                'frequency' => $frequency,
                'times' => in_array($frequency, ['daily', 'weekly'], true) ? $time : null,
                'day_of_week' => $dayOfWeek,
                'day_of_month' => null,
                'timezone' => $tz,
            ];
            $this->db->insert('schedules', $schedule + [
                'backup_plan_id' => $planId,
                'enabled' => $frequency === 'manual' ? 0 : 1,
                'next_run' => $frequency === 'manual' ? null : (new \BBS\Services\SchedulerService())->calculateNextRun($schedule),
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('VM backup plan creation failed: ' . $e->getMessage());
            $this->flash('danger', 'The plan could not be saved.');
            $this->redirect($back);
        }

        $this->flash('success', "Backup plan \"{$name}\" created.");
        $this->redirect($back);
    }

    /** Ask the agent for a fresh inventory at its next assignment poll (30 s). */
    public function refresh(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $hypervisor = $this->getHypervisor($id);
        if (!$hypervisor) {
            $this->flash('danger', 'Hypervisor not found.');
            $this->redirect('/hypervisors');
        }

        $this->db->update('hypervisors', ['refresh_requested' => 1], 'id = ?', [$id]);
        $this->flash('success', 'Inventory refresh requested. The list updates within about 30 seconds.');
        $this->redirect('/hypervisors/' . $id);
    }

    /** Name and inventory interval. */
    public function updateSettings(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $hypervisor = $this->getHypervisor($id);
        if (!$hypervisor || !$this->isOwnerOrAdmin($hypervisor)) {
            $this->flash('danger', 'Hypervisor not found.');
            $this->redirect('/hypervisors');
        }

        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 100);
        if ($name === '') {
            $this->flash('danger', 'Hypervisor name is required.');
            $this->redirect("/hypervisors/{$id}?tab=settings");
        }
        $interval = (int) ($_POST['inventory_interval_minutes'] ?? 5);
        if ($interval < 1 || $interval > 60) {
            $this->flash('danger', 'The inventory interval must be between 1 and 60 minutes.');
            $this->redirect("/hypervisors/{$id}?tab=settings");
        }

        $this->db->update('hypervisors', [
            'name' => $name,
            'inventory_interval_minutes' => $interval,
        ], 'id = ?', [$id]);

        $this->flash('success', 'Settings saved.');
        $this->redirect("/hypervisors/{$id}?tab=settings");
    }

    private function isOwnerOrAdmin(array $hypervisor): bool
    {
        return $this->isAdmin() || (int) ($hypervisor['agent_user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0);
    }

    private function requireCreate(): void
    {
        $this->requireAuth();
        if (!(new PermissionService())->canCreateClients((int) $_SESSION['user_id'])) {
            http_response_code(403);
            echo 'Access denied';
            exit;
        }
    }

    private function getHypervisor(int $id): ?array
    {
        $h = $this->db->fetchOne("
            SELECT h.*, a.name as agent_name, a.status as agent_status, a.agent_version,
                   a.last_heartbeat, a.user_id as agent_user_id, a.api_key_encrypted,
                   a.server_host_override
            FROM hypervisors h
            JOIN agents a ON a.id = h.agent_id
            WHERE h.id = ?
        ", [$id]);
        if (!$h || !$this->canAccessAgent((int) $h['agent_id'])) {
            return null;
        }
        return $h;
    }

    /** A client's install command (see clients/detail install tab) plus --hypervisor. */
    private function installCommand(array $hypervisor): ?string
    {
        try {
            $apiKey = \BBS\Services\Encryption::decrypt($hypervisor['api_key_encrypted'] ?? '');
        } catch (\Throwable $e) {
            return null;
        }
        if (!$apiKey) {
            return null;
        }

        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $appUrl = rtrim(\BBS\Core\Config::get('APP_URL', 'https://' . ($serverHost['value'] ?? 'YOUR_SERVER_HOST')), '/');
        // Per-agent server host override (#367): keep APP_URL's scheme; the
        // override may carry its own port, otherwise reuse APP_URL's.
        if (!empty($hypervisor['server_host_override'])) {
            $parts = parse_url($appUrl);
            $override = $hypervisor['server_host_override'];
            $hasPort = (bool) preg_match('/^([^:\[\]]+|\[[0-9a-fA-F:]+\]):\d+$/', $override);
            $port = (!$hasPort && !empty($parts['port'])) ? ':' . $parts['port'] : '';
            $appUrl = ($parts['scheme'] ?? 'https') . '://' . $override . $port;
        }

        // Run as root, without sudo: Proxmox VE does not ship sudo and its
        // shell is root already.
        return 'curl -s ' . $appUrl . '/get-agent | bash -s -- --server ' . $appUrl
            . ' --key ' . $apiKey . ' --hypervisor';
    }
}
