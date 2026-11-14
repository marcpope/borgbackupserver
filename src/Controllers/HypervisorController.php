<?php

namespace BBS\Controllers;

use BBS\Core\Controller;
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
            'connectors' => HypervisorRegistry::all(),
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

        $tab = in_array($_GET['tab'] ?? '', ['vms', 'install'], true) ? $_GET['tab'] : 'vms';
        $isOwnerOrAdmin = $this->isAdmin() || (int) ($hypervisor['agent_user_id'] ?? 0) === (int) ($_SESSION['user_id'] ?? 0);
        if ($tab === 'install' && !$isOwnerOrAdmin) {
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

        $this->view('hypervisors/detail', [
            'pageTitle' => 'Hypervisors',
            'hypervisor' => $hypervisor,
            'connector' => HypervisorRegistry::get($hypervisor['type']),
            'info' => json_decode($hypervisor['info'] ?? '', true) ?: [],
            'vms' => $vms,
            'tab' => $tab,
            'isOwnerOrAdmin' => $isOwnerOrAdmin,
            'installCmd' => $installCmd,
        ]);
    }

    /** Ask the agent for a fresh inventory at its next task poll. */
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
        $this->flash('success', 'Inventory refresh requested. The list updates at the agent\'s next poll.');
        $this->redirect('/hypervisors/' . $id);
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
