<?php

namespace BBS\Controllers;

use BBS\Core\Config;
use BBS\Core\Controller;
use BBS\Services\AgentProvisioningService;
use BBS\Services\ClientProfileService;
use BBS\Services\Encryption;
use BBS\Services\Hypervisors\HypervisorBackupService;
use BBS\Services\Hypervisors\HypervisorConnector;
use BBS\Services\Hypervisors\HypervisorRegistry;
use BBS\Services\PermissionService;
use BBS\Services\SchedulerService;

/**
 * Hypervisors and their virtual machines: the Hypervisors section of the UI.
 *
 * A hypervisor is added here, never by an agent: Add Hypervisor creates a
 * hypervisor agent (agents.agent_type = 'hypervisor') and the hypervisor
 * record, then shows the install command (install.sh --hypervisor).
 * Everything type-specific comes from the hypervisor's connector
 * (src/Services/Hypervisors), so this controller and its views work for
 * any connector.
 *
 * Sections, in the order of the page tabs:
 *   - list and creation         index, add, store
 *   - detail page               detail, refresh, updateSettings (VMs,
 *                               Settings and Install agent tabs)
 *   - Backups tab               backupsTabData, storePlan
 *   - Restore tab               restoreTabData, restore
 *   - file-level browsing       browse page and its JSON endpoints
 *   - access helpers            getHypervisor, isOwnerOrAdmin, requireCreate
 *
 * Access follows the hypervisor's agent: getHypervisor() returns null for a
 * hypervisor whose agent the user cannot access, and each action then
 * checks the same agent permissions as for a client (manage plans, trigger
 * backup, restore). Install and Settings are for the owner or an admin.
 *
 * This controller only reads and writes the database. Work on the node is
 * queued as backup_jobs rows (backup, restore_vm, vm_browse) that the agent
 * picks up; nothing here runs a command. Note that json() and redirect()
 * end the request (exit), so a check that fails stops the action there.
 *
 * See docs/hypervisors.md#architecture
 */
class HypervisorController extends Controller
{
    // ------------------------------------------------------------------
    // List and creation
    // ------------------------------------------------------------------

    /** GET /hypervisors - the hypervisors the user can access, with VM counts. */
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

        foreach ($hypervisors as &$hypervisor) {
            $hypervisor['info'] = json_decode($hypervisor['info'] ?? '', true) ?: [];
            $hypervisor['connector'] = HypervisorRegistry::get($hypervisor['type']);
        }
        unset($hypervisor);

        $this->view('hypervisors/index', [
            'pageTitle' => 'Hypervisors',
            'hypervisors' => $hypervisors,
        ]);
    }

    /** GET /hypervisors/add - connector catalog, with the creation form for available ones. */
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

    /**
     * POST /hypervisors/add - create the hypervisor agent (same provisioning
     * as Add Client: API key, storage dir, SSH user) and the hypervisor row,
     * then open the Install agent tab. Same permission as adding a client,
     * and the same owner rule: a non-admin always owns what they add.
     */
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

        $provisioning = new AgentProvisioningService();
        try {
            $agentId = $provisioning->create(
                $name, $userId, null, $_POST['location'] ?? '', 'hypervisor'
            );
        } catch (\RuntimeException $e) {
            $this->flash('danger', 'Cannot create hypervisor — ' . $e->getMessage());
            $this->redirect('/hypervisors/add');
        }

        // agent_role 'host': the agent runs on the hypervisor itself. 'proxy'
        // is reserved for API-based connectors (docs/hypervisors.md, open
        // question 1).
        try {
            $id = $this->db->insert('hypervisors', [
                'name' => $name,
                'type' => $connector->slug(),
                'agent_id' => $agentId,
                'agent_role' => 'host',
            ]);
        } catch (\Throwable $e) {
            // Without its hypervisor row the new agent would be listed
            // nowhere: remove it, with its Unix user and storage directory
            error_log('Hypervisor creation failed: ' . $e->getMessage());
            $provisioning->discard($agentId);
            $this->flash('danger', 'Cannot create hypervisor — it could not be saved. Nothing was created; try again.');
            $this->redirect('/hypervisors/add');
        }

        $this->flash('success', 'Hypervisor created. Run the install command below on the hypervisor, as root.');
        $this->redirect("/hypervisors/{$id}?tab=install");
    }

    // ------------------------------------------------------------------
    // Detail page
    // ------------------------------------------------------------------

    /**
     * GET /hypervisors/{id}?tab= - the detail page. Each tab's data is only
     * loaded when that tab is shown; Install and Settings fall back to the
     * VMs tab for a user who is not the owner or an admin.
     */
    public function detail(int $id): void
    {
        $this->requireAuth();

        $hypervisor = $this->getHypervisor($id);
        if (!$hypervisor) {
            $this->flash('danger', 'Hypervisor not found.');
            $this->redirect('/hypervisors');
        }

        $tab = in_array($_GET['tab'] ?? '', ['vms', 'backups', 'restore', 'install', 'settings'], true) ? $_GET['tab'] : 'vms';
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
        $restore = $tab === 'restore' ? $this->restoreTabData($hypervisor, $vms) : [];

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
            'restore' => $restore,
        ]);
    }

    /**
     * POST /hypervisors/{id}/refresh - ask the agent for a fresh inventory.
     * The flag is returned by the next assignments poll (every 30 s) and
     * cleared when the inventory arrives.
     */
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

    /** POST /hypervisors/{id}/settings - name and inventory interval (1-60 minutes). Owner or admin. */
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

    /**
     * The install command of the Install agent tab: a client's install
     * command (see the clients/detail install tab) plus --hypervisor.
     * Null when the API key cannot be decrypted.
     */
    private function installCommand(array $hypervisor): ?string
    {
        try {
            $apiKey = Encryption::decrypt($hypervisor['api_key_encrypted'] ?? '');
        } catch (\Throwable $e) {
            return null;
        }
        if (!$apiKey) {
            return null;
        }

        $serverHost = $this->db->fetchOne("SELECT `value` FROM settings WHERE `key` = 'server_host'");
        $appUrl = rtrim(Config::get('APP_URL', 'https://' . ($serverHost['value'] ?? 'YOUR_SERVER_HOST')), '/');
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

    // ------------------------------------------------------------------
    // Backups tab
    // ------------------------------------------------------------------

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
        foreach ($plans as &$plan) {
            // The VMs the plan would back up now, and its per-VM rows
            // (excluded VM, excluded disks) for the plan card
            $plan['vms'] = $service->planVms($plan);
            $plan['vm_rows'] = [];
            foreach ($this->db->fetchAll("SELECT * FROM backup_plan_vms WHERE backup_plan_id = ?", [$plan['id']]) as $row) {
                $row['exclude_disks'] = json_decode($row['exclude_disks'] ?? '[]', true) ?: [];
                $plan['vm_rows'][$row['vm_ref']] = $row;
            }
        }
        unset($plan);

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
            // Read-only repositories cannot receive backups
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
     * POST /hypervisors/{id}/plans - create a VM backup plan: repository,
     * VMs (selected, or all but the unticked ones), disks per VM, schedule
     * and retention. It runs through the ordinary backup pipeline as one job
     * per VM (HypervisorBackupService).
     *
     * The plan is an ordinary backup_plans row (hypervisor_id set, no
     * directories) plus one backup_plan_vms row per VM, and an ordinary
     * schedules row, all in one transaction. Run now and delete reuse
     * BackupPlanController (/plans/{id}/trigger, /plans/{id}/delete); a VM
     * plan has no edit form yet (delete and create it again).
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
        // SAFETY: the repository must belong to this hypervisor's agent
        $repo = $this->db->fetchOne(
            "SELECT id FROM repositories WHERE id = ? AND agent_id = ? AND read_only = 0",
            [$repoId, $agentId]
        );
        if ($name === '' || !$repo) {
            $this->flash('danger', 'A plan needs a name and one of this hypervisor\'s repositories.');
            $this->redirect($back);
        }

        // VMs and disk keys are taken from the stored inventory, never from
        // the form: a posted VM or disk the hypervisor does not have is ignored.
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
            $keptDisks = array_map('strval', (array) ($postedDisks[$ref] ?? []));
            $exclude = [];
            foreach (json_decode($vm['disks'] ?? '[]', true) ?: [] as $disk) {
                if (!empty($disk['supported']) && !in_array((string) $disk['key'], $keptDisks, true)) {
                    $exclude[] = (string) $disk['key'];
                }
            }
            // 'selected' stores the ticked VMs only; 'all' stores every VM,
            // with exclude_vm set on the unticked ones
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
        // Retention: number of archives to keep, per VM (the prune is scoped
        // to each VM's archive prefix), 0 to 1000
        $retention = fn($k, $d) => max(0, min(1000, (int) ($_POST[$k] ?? $d)));

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
                'prune_days' => $retention('prune_days', 7),
                'prune_weeks' => $retention('prune_weeks', 4),
                'prune_months' => $retention('prune_months', 6),
                'prune_years' => 0,
            ]);
            foreach ($rows as $row) {
                $this->db->insert('backup_plan_vms', [
                    'backup_plan_id' => $planId,
                    'vm_ref' => $row['vm_ref'],
                    'exclude_vm' => $row['exclude_vm'],
                    'exclude_disks' => json_encode($row['exclude_disks']),
                ]);
            }

            // Same timezone rule and next-run calculation as a file backup plan
            $tz = (new ClientProfileService())->planTimezoneFor($agentId, $_SESSION['timezone'] ?? null);
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
                'next_run' => $frequency === 'manual' ? null : (new SchedulerService())->calculateNextRun($schedule),
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

    // ------------------------------------------------------------------
    // Restore tab
    // ------------------------------------------------------------------

    /**
     * Restore points per VM: the VM archives in this hypervisor agent's
     * repositories (archives.vm_ref), newest first, and recent VM restores.
     * Also the storages a restore may target and the open browse sessions.
     */
    private function restoreTabData(array $hypervisor, array $vms): array
    {
        $agentId = (int) $hypervisor['agent_id'];
        $archives = $this->db->fetchAll("
            SELECT ar.id, ar.vm_ref, ar.archive_name, ar.original_size, ar.deduplicated_size, ar.created_at,
                   r.name as repo_name
            FROM archives ar
            JOIN repositories r ON r.id = ar.repository_id
            WHERE r.agent_id = ? AND ar.vm_ref IS NOT NULL
            ORDER BY CAST(ar.vm_ref AS UNSIGNED), ar.vm_ref, ar.id DESC
        ", [$agentId]);
        $byVm = [];
        foreach ($archives as $archive) {
            $byVm[$archive['vm_ref']][] = $archive;
        }

        $info = json_decode($hypervisor['info'] ?? '', true) ?: [];
        $connector = HypervisorRegistry::get($hypervisor['type']);
        $restoreTypes = $connector ? $connector->restoreDatastoreTypes() : [];
        $storages = array_values(array_filter(
            (array) ($info['datastores'] ?? []),
            fn($s) => in_array($s['type'] ?? '', $restoreTypes, true)
        ));

        return [
            'byVm' => $byVm,
            'vmNames' => array_column($vms, 'name', 'vm_ref'),
            'storages' => $storages,
            'jobs' => $this->db->fetchAll("
                SELECT bj.id, bj.vm_ref, bj.status, bj.status_message, bj.restore_options, bj.completed_at,
                       bj.duration_seconds, SUBSTRING(bj.error_log, 1, 300) as error_log, SUBSTRING(bj.task_result, 1, 300) as output
                FROM backup_jobs bj
                WHERE bj.agent_id = ? AND bj.task_type = 'restore_vm'
                ORDER BY bj.id DESC LIMIT 15
            ", [$agentId]),
            'canRestore' => $this->hasPermission(PermissionService::RESTORE, $agentId),
            'canBrowse' => $connector && $connector->can('file_restore')
                && $this->hasPermission(PermissionService::RESTORE, $agentId),
            'browseSessions' => (new HypervisorBackupService())->openBrowseSessions($agentId),
        ];
    }

    /**
     * POST /hypervisors/{id}/restore - queue a full VM restore: the archive
     * is restored as a NEW VM (new VMID, new disks, new MAC addresses,
     * network unplugged by default), so the original VM is never touched.
     *
     * SAFETY: every option is checked here and again by the agent
     * (ProxmoxVmRestore), which also refuses a VMID that already exists.
     * The archive must be a VM archive in this agent's repositories and the
     * storage one of the node's storages of a type the connector can
     * restore to. See docs/hypervisors.md#d6-restore-as-a-new-vm-only
     */
    public function restore(int $id): void
    {
        $this->requireAuth();
        $this->verifyCsrf();

        $hypervisor = $this->getHypervisor($id);
        if (!$hypervisor) {
            $this->flash('danger', 'Hypervisor not found.');
            $this->redirect('/hypervisors');
        }
        $agentId = (int) $hypervisor['agent_id'];
        $back = "/hypervisors/{$id}?tab=restore";
        if (!$this->hasPermission(PermissionService::RESTORE, $agentId)) {
            $this->flash('danger', 'You do not have permission to restore.');
            $this->redirect($back);
        }
        $connector = HypervisorRegistry::get($hypervisor['type']);
        if (!$connector || !$connector->can('restore')) {
            $this->flash('danger', 'This hypervisor type cannot restore VMs yet.');
            $this->redirect($back);
        }

        $archive = $this->db->fetchOne("
            SELECT ar.id, ar.vm_ref, ar.archive_name, ar.repository_id
            FROM archives ar JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ? AND r.agent_id = ? AND ar.vm_ref IS NOT NULL
        ", [(int) ($_POST['archive_id'] ?? 0), $agentId]);
        if (!$archive) {
            $this->flash('danger', 'Restore point not found.');
            $this->redirect($back);
        }

        // Proxmox VMIDs start at 100; empty means the next free one (the
        // agent asks the node)
        $vmidRaw = trim($_POST['target_vmid'] ?? '');
        if ($vmidRaw !== '' && (!ctype_digit($vmidRaw) || (int) $vmidRaw < 100 || (int) $vmidRaw > 999999999)) {
            $this->flash('danger', 'The new VMID must be a number from 100 upwards, or left empty for the next free one.');
            $this->redirect($back);
        }
        $storage = trim($_POST['storage'] ?? '');
        $info = json_decode($hypervisor['info'] ?? '', true) ?: [];
        $allowed = array_filter(
            (array) ($info['datastores'] ?? []),
            fn($s) => in_array($s['type'] ?? '', $connector->restoreDatastoreTypes(), true)
        );
        if (!in_array($storage, array_column($allowed, 'id'), true)) {
            $this->flash('danger', 'Choose a storage this hypervisor can restore VM disks to.');
            $this->redirect($back);
        }
        // A DNS label, as Proxmox requires for a VM name; empty means the
        // agent names it "<original name>-restored"
        $name = trim($_POST['name'] ?? '');
        if ($name !== '' && !preg_match('/^[A-Za-z0-9]([A-Za-z0-9.-]{0,61}[A-Za-z0-9])?$/', $name)) {
            $this->flash('danger', 'The VM name may contain letters, digits, dots and dashes only.');
            $this->redirect($back);
        }

        // Read back by QueueManager, which builds the restore_vm task
        $options = [
            'vmid' => $vmidRaw === '' ? null : (int) $vmidRaw,
            'storage' => $storage,
            'name' => $name === '' ? null : $name,
            'network_down' => empty($_POST['network_up']),
            'start' => !empty($_POST['start']),
        ];
        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => $agentId,
            'repository_id' => (int) $archive['repository_id'],
            'vm_ref' => $archive['vm_ref'],
            'task_type' => 'restore_vm',
            'status' => 'queued',
            'restore_archive_id' => (int) $archive['id'],
            'restore_options' => json_encode($options),
        ]);
        $this->db->insert('server_log', [
            'agent_id' => $agentId,
            'backup_job_id' => $jobId,
            'level' => 'info',
            'message' => "VM restore queued: VM {$archive['vm_ref']} from \"{$archive['archive_name']}\" as "
                . ($options['vmid'] ? "VMID {$options['vmid']}" : 'the next free VMID') . " on {$storage}",
        ]);

        $this->flash('success', 'VM restore queued.');
        $this->redirect($back);
    }

    // ------------------------------------------------------------------
    // File-level browsing
    //
    // The browse page talks to the agent through vm_browse jobs: the page
    // posts a request (open, list, download, close), gets a job id back and
    // polls browse-task until the agent has answered. The agent keeps the
    // restore point mounted between requests (one session at a time) and
    // reports the session to AgentApiController::hypervisorBrowseSession(),
    // which is what browse-session reads. Files never pass through the
    // server unasked: only a download uploads a tar.gz, which the browser
    // then fetches from browse-download.
    // See docs/hypervisors.md#d7-file-level-browsing-through-an-isolation-vm
    // ------------------------------------------------------------------

    /**
     * The VM restore point a browse request is about, checked: the
     * hypervisor is visible to the user, the user may restore, the connector
     * can browse files, and the archive is a VM archive of that hypervisor.
     * Returns [hypervisor, archive] or sends the error (JSON or redirect).
     */
    private function browseContext(int $id, int $archiveId, bool $asJson): array
    {
        // Both json() and redirect() exit, so $fail() never returns
        $fail = function (string $msg, int $code) use ($asJson, $id) {
            if ($asJson) {
                $this->json(['error' => $msg], $code);
            }
            $this->flash('danger', $msg);
            $this->redirect($id ? "/hypervisors/{$id}?tab=restore" : '/hypervisors');
        };
        $hypervisor = $this->getHypervisor($id);
        if (!$hypervisor) {
            $fail('Hypervisor not found.', 404);
        }
        $agentId = (int) $hypervisor['agent_id'];
        if (!$this->hasPermission(PermissionService::RESTORE, $agentId)) {
            $fail('You do not have permission to restore.', 403);
        }
        $connector = HypervisorRegistry::get($hypervisor['type']);
        if (!$connector || !$connector->can('file_restore')) {
            $fail('This hypervisor type cannot browse files in VM backups yet.', 400);
        }
        $archive = $this->db->fetchOne("
            SELECT ar.id, ar.vm_ref, ar.archive_name, ar.repository_id, ar.created_at, ar.original_size,
                   r.name as repo_name
            FROM archives ar JOIN repositories r ON r.id = ar.repository_id
            WHERE ar.id = ? AND r.agent_id = ? AND ar.vm_ref IS NOT NULL
        ", [$archiveId, $agentId]);
        if (!$archive) {
            $fail('Restore point not found.', 404);
        }
        return [$hypervisor, $archive];
    }

    /** GET /hypervisors/{id}/browse/{archiveId} - file-level browser of a VM restore point. */
    public function browse(int $id, int $archiveId): void
    {
        $this->requireAuth();
        [$hypervisor, $archive] = $this->browseContext($id, $archiveId, false);
        $vm = $this->db->fetchOne(
            "SELECT name FROM hypervisor_vms WHERE hypervisor_id = ? AND vm_ref = ?",
            [$id, $archive['vm_ref']]
        );
        $this->view('hypervisors/browse', [
            'pageTitle' => 'Hypervisors',
            'hypervisor' => $hypervisor,
            'archive' => $archive,
            'vmName' => $vm['name'] ?? null,
        ]);
    }

    /**
     * POST /hypervisors/{id}/browse/{archiveId} - queue a vm_browse task.
     * JSON body: {action: open|list|download, volume, path, paths[]}.
     * Answers {task_id}; poll /hypervisors/{id}/browse-task/{task_id}.
     *
     * SAFETY: only the shape is checked here (volume id characters, path
     * length, no NUL byte, at most 1000 items). Paths are resolved inside
     * the agent's helper VM, which refuses any path that leaves the guest
     * volume (".." or a symlink pointing out).
     */
    public function browseRequest(int $id, int $archiveId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();
        [$hypervisor, $archive] = $this->browseContext($id, $archiveId, true);
        if (($hypervisor['agent_status'] ?? 'offline') !== 'online') {
            $this->json(['error' => 'The hypervisor agent is offline.'], 409);
        }

        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = (string) ($input['action'] ?? '');
        if (!in_array($action, ['open', 'list', 'download'], true)) {
            $this->json(['error' => 'Unknown action.'], 400);
        }
        $request = ['action' => $action];
        if ($action !== 'open') {
            $volume = (string) ($input['volume'] ?? '');
            if (!preg_match('/^[A-Za-z0-9_.+-]{1,200}$/', $volume)) {
                $this->json(['error' => 'Invalid volume.'], 400);
            }
            $request['volume'] = $volume;
        }
        $validPath = fn($p) => is_string($p) && strlen($p) <= 4096 && !str_contains($p, "\0");
        if ($action === 'list') {
            $path = $input['path'] ?? '';
            if (!$validPath($path)) {
                $this->json(['error' => 'Invalid path.'], 400);
            }
            $request['path'] = $path;
        }
        if ($action === 'download') {
            $paths = $input['paths'] ?? [];
            if (!is_array($paths) || !$paths || count($paths) > 1000 || count(array_filter($paths, $validPath)) !== count($paths)) {
                $this->json(['error' => 'Select between 1 and 1000 files or folders.'], 400);
            }
            $request['paths'] = array_values($paths);
            // Housekeeping: drop downloads older than BROWSE_DOWNLOAD_HOURS
            HypervisorBackupService::cleanBrowseDownloads();
        }

        $jobId = $this->db->insert('backup_jobs', [
            'agent_id' => (int) $hypervisor['agent_id'],
            'repository_id' => (int) $archive['repository_id'],
            'vm_ref' => $archive['vm_ref'],
            'task_type' => 'vm_browse',
            'status' => 'queued',
            'restore_archive_id' => (int) $archive['id'],
            'restore_options' => json_encode($request),
        ]);
        // Downloads are logged (data leaves the backup); open and list are
        // too frequent to log
        if ($action === 'download') {
            $this->db->insert('server_log', [
                'agent_id' => (int) $hypervisor['agent_id'],
                'backup_job_id' => $jobId,
                'level' => 'info',
                'message' => "VM file download requested: VM {$archive['vm_ref']} from \"{$archive['archive_name']}\", "
                    . count($request['paths']) . " item(s) of volume {$request['volume']}",
            ]);
        }
        $this->json(['status' => 'pending', 'task_id' => $jobId]);
    }

    /**
     * GET /hypervisors/{id}/browse-task/{jobId} - state and answer of a
     * vm_browse task: pending, failed with the agent's error, or completed
     * with the agent's JSON answer (task_result) and, for a download, the
     * URL of the tar.gz.
     */
    public function browseTask(int $id, int $jobId): void
    {
        $this->requireAuth();
        $hypervisor = $this->getHypervisor($id);
        if (!$hypervisor || !$this->hasPermission(PermissionService::RESTORE, (int) $hypervisor['agent_id'])) {
            $this->json(['error' => 'Access denied'], 403);
        }
        $job = $this->db->fetchOne(
            "SELECT id, status, status_message, error_log, task_result, restore_options
             FROM backup_jobs WHERE id = ? AND agent_id = ? AND task_type = 'vm_browse'",
            [$jobId, (int) $hypervisor['agent_id']]
        );
        if (!$job) {
            $this->json(['error' => 'Task not found'], 404);
        }
        if ($job['status'] === 'failed') {
            $this->json(['status' => 'failed', 'error' => $job['error_log'] ?: 'The agent could not do it.']);
        }
        if ($job['status'] !== 'completed') {
            $this->json(['status' => 'pending', 'state' => $job['status'], 'message' => $job['status_message']]);
        }
        $out = ['status' => 'completed', 'result' => json_decode($job['task_result'] ?? '', true)];
        $options = json_decode($job['restore_options'] ?? '', true) ?: [];
        if (($options['action'] ?? '') === 'download') {
            $out['download_url'] = "/hypervisors/{$id}/browse-download/{$jobId}";
        }
        $this->json($out);
    }

    /**
     * GET /hypervisors/{id}/browse-download/{jobId} - the tar.gz a download
     * task prepared, streamed to the browser. The file name is built from
     * the VM id, volume and archive date, cut to safe characters.
     */
    public function browseDownload(int $id, int $jobId): void
    {
        $this->requireAuth();
        $hypervisor = $this->getHypervisor($id);
        if (!$hypervisor || !$this->hasPermission(PermissionService::RESTORE, (int) $hypervisor['agent_id'])) {
            $this->flash('danger', 'Access denied.');
            $this->redirect('/hypervisors');
        }
        $job = $this->db->fetchOne(
            "SELECT bj.id, bj.status, bj.vm_ref, bj.restore_options, ar.created_at
             FROM backup_jobs bj LEFT JOIN archives ar ON ar.id = bj.restore_archive_id
             WHERE bj.id = ? AND bj.agent_id = ? AND bj.task_type = 'vm_browse'",
            [$jobId, (int) $hypervisor['agent_id']]
        );
        $options = json_decode($job['restore_options'] ?? '', true) ?: [];
        $path = HypervisorBackupService::browseDownloadPath($jobId);
        if (!$job || $job['status'] !== 'completed' || ($options['action'] ?? '') !== 'download' || !is_file($path)) {
            $this->flash('danger', 'This download is not available (it is kept for '
                . HypervisorBackupService::BROWSE_DOWNLOAD_HOURS . ' hours).');
            $this->redirect("/hypervisors/{$id}?tab=restore");
        }
        $name = sprintf('vm%s-%s-%s.tar.gz',
            preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $job['vm_ref']),
            preg_replace('/[^A-Za-z0-9_.+-]/', '_', (string) ($options['volume'] ?? 'files')),
            $job['created_at'] ? date('Ymd-His', strtotime($job['created_at'])) : $jobId);
        // Stream the file as is: no time limit, no output buffering (it
        // may be several GB)
        set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/gzip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    /**
     * GET /hypervisors/{id}/browse-session/{archiveId} - is a browse session
     * of this restore point open on the agent, and since when (the lock it
     * holds on the repository). Polled by the browse page for its lock bar.
     */
    public function browseSession(int $id, int $archiveId): void
    {
        $this->requireAuth();
        $hypervisor = $this->getHypervisor($id);
        if (!$hypervisor || !$this->hasPermission(PermissionService::RESTORE, (int) $hypervisor['agent_id'])) {
            $this->json(['error' => 'Access denied'], 403);
        }
        $rows = (new HypervisorBackupService())->openBrowseSessions((int) $hypervisor['agent_id'], $archiveId);
        if (!$rows) {
            $this->json(['open' => false]);
        }
        $session = $rows[0];
        $this->json([
            'open' => true,
            'repository' => $session['repo_name'],
            'locked_seconds' => (int) $session['locked_seconds'],
            'idle_seconds' => (int) $session['idle_seconds'],
            'closes_in_seconds' => (int) $session['closes_in_seconds'],
        ]);
    }

    /**
     * POST /hypervisors/{id}/browse-close/{archiveId} - close the browse
     * session of a restore point now (unmounts it, frees the repository).
     * Answers JSON for fetch() callers, or redirects back for a form post.
     */
    public function browseClose(int $id, int $archiveId): void
    {
        $this->requireAuth();
        $this->verifyCsrf();
        $asJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
        $hypervisor = $this->getHypervisor($id);
        if (!$hypervisor || !$this->hasPermission(PermissionService::RESTORE, (int) $hypervisor['agent_id'])) {
            $asJson ? $this->json(['error' => 'Access denied'], 403) : $this->redirect('/hypervisors');
        }
        $archive = $this->db->fetchOne(
            "SELECT ar.id, ar.vm_ref, ar.repository_id FROM archives ar JOIN repositories r ON r.id = ar.repository_id
             WHERE ar.id = ? AND r.agent_id = ?",
            [$archiveId, (int) $hypervisor['agent_id']]
        );
        if ($archive) {
            // The agent closes the session and reports it closed
            $this->db->insert('backup_jobs', [
                'agent_id' => (int) $hypervisor['agent_id'],
                'repository_id' => (int) $archive['repository_id'],
                'vm_ref' => $archive['vm_ref'],
                'task_type' => 'vm_browse',
                'status' => 'queued',
                'restore_archive_id' => (int) $archive['id'],
                'restore_options' => json_encode(['action' => 'close']),
            ]);
        } else {
            // The restore point is gone (pruned): forget its session row
            $this->db->query(
                "UPDATE vm_browse_sessions SET closed_at = NOW(), close_reason = 'restore point deleted'
                 WHERE agent_id = ? AND archive_id = ? AND closed_at IS NULL",
                [(int) $hypervisor['agent_id'], $archiveId]
            );
        }
        if ($asJson) {
            $this->json(['status' => 'ok']);
        }
        $this->flash('success', 'The browse session is being closed; the repository is released in a few seconds.');
        $this->redirect("/hypervisors/{$id}?tab=restore");
    }

    // ------------------------------------------------------------------
    // Access helpers
    // ------------------------------------------------------------------

    /**
     * The hypervisor with its agent's status, owner and install details, or
     * null when it does not exist or the user cannot access its agent.
     */
    private function getHypervisor(int $id): ?array
    {
        $hypervisor = $this->db->fetchOne("
            SELECT h.*, a.name as agent_name, a.status as agent_status, a.agent_version,
                   a.last_heartbeat, a.user_id as agent_user_id, a.api_key_encrypted,
                   a.server_host_override
            FROM hypervisors h
            JOIN agents a ON a.id = h.agent_id
            WHERE h.id = ?
        ", [$id]);
        if (!$hypervisor || !$this->canAccessAgent((int) $hypervisor['agent_id'])) {
            return null;
        }
        return $hypervisor;
    }

    /** Admin, or the user who owns the hypervisor's agent. */
    private function isOwnerOrAdmin(array $hypervisor): bool
    {
        return $this->isAdmin() || (int) ($hypervisor['agent_user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0);
    }

    /** Adding a hypervisor needs the same permission as adding a client. */
    private function requireCreate(): void
    {
        $this->requireAuth();
        if (!(new PermissionService())->canCreateClients((int) $_SESSION['user_id'])) {
            http_response_code(403);
            echo 'Access denied';
            exit;
        }
    }
}
