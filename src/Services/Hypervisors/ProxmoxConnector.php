<?php

namespace BBS\Services\Hypervisors;

/**
 * Proxmox VE, single node. The BBS hypervisor agent runs on the node itself
 * and reports the node's VMs (see ProxmoxConnector in agent/bbs-hypervisor.py).
 */
class ProxmoxConnector extends HypervisorConnector
{
    public function slug(): string
    {
        return 'proxmox';
    }

    public function label(): string
    {
        return 'Proxmox VE (single node)';
    }

    public function description(): string
    {
        return 'Back up the QEMU virtual machines of a Proxmox VE node. The BBS agent runs on the node, '
            . 'snapshots the VM disks and streams them into the repository. Supported storage: LVM-thin.';
    }

    public function icon(): string
    {
        return 'hdd-rack';
    }

    public function deployment(): string
    {
        return self::DEPLOY_AGENT_ON_HOST;
    }

    public function capabilities(): array
    {
        return ['inventory' => true, 'backup' => false, 'restore' => false, 'file_restore' => false];
    }

    public function vmIdLabel(): string
    {
        return 'VMID';
    }

    public function hostLabel(): string
    {
        return 'Node';
    }

    public function setupSteps(): array
    {
        return [
            'Create the hypervisor below.',
            'Run the install command it shows on the Proxmox node, as root. It installs the BBS agent with its hypervisor module.',
            'The node comes online within a minute; its virtual machines are listed and refreshed every 5 minutes.',
        ];
    }

    public function describeInfo(array $info): array
    {
        $lines = [];
        if (!empty($info['version'])) $lines['Proxmox VE'] = $info['version'];
        if (!empty($info['node'])) $lines['Node'] = $info['node'];
        if (!empty($info['datastores'])) {
            $lines['Storage'] = implode(', ', array_map(
                fn($s) => $s['id'] . ' (' . $s['type'] . ')',
                $info['datastores']
            ));
        }
        return $lines;
    }

    public function normalizeInfo(array $input): array
    {
        $datastores = [];
        foreach (array_slice((array) ($input['datastores'] ?? []), 0, 100) as $s) {
            if (is_array($s) && isset($s['id'], $s['type'])) {
                $datastores[] = ['id' => substr((string) $s['id'], 0, 100), 'type' => substr((string) $s['type'], 0, 30)];
            }
        }
        return [
            'node' => substr((string) ($input['node'] ?? ''), 0, 100),
            'version' => substr((string) ($input['version'] ?? ''), 0, 40),
            'datastores' => $datastores,
        ];
    }
}
