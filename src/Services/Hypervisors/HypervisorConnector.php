<?php

namespace BBS\Services\Hypervisors;

/**
 * A kind of hypervisor BBS can back up virtual machines from.
 *
 * Every hypervisor-specific detail lives in a connector: how it is reached,
 * what it can do, and how its inventory maps onto the common model below.
 * The rest of the application (pages, API, tables) only deals with that
 * common model, so adding ESXi or vCenter means adding a connector here and
 * its counterpart in the agent, nothing else.
 *
 * Common inventory model, as stored in hypervisor_vms:
 *   vm:   ref (string id on the hypervisor: "100", "vm-1234"), name, host,
 *         status, cpus, memory_bytes, uptime_seconds, guest_tools, template,
 *         tags, disks[], extra{}
 *   disk: key, path, datastore, datastore_type, kind, size_bytes,
 *         default_excluded, supported, unsupported_reason
 *   disk kinds: disk, efi, tpm, cdrom, cloudinit, passthrough, unused
 */
abstract class HypervisorConnector
{
    /** The agent runs on the hypervisor itself (Proxmox node). */
    public const DEPLOY_AGENT_ON_HOST = 'agent_on_host';
    /** An agent elsewhere talks to the hypervisor's API (vCenter, ESXi). */
    public const DEPLOY_PROXY_AGENT = 'proxy_agent';

    public const DISK_KINDS = ['disk', 'efi', 'tpm', 'cdrom', 'cloudinit', 'passthrough', 'unused'];

    /** Stable identifier, also used by the agent: 'proxmox', 'vmware_vcenter'... */
    abstract public function slug(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /** Bootstrap icon name, without the "bi-" prefix. */
    public function icon(): string
    {
        return 'hdd-rack';
    }

    /** False for a connector that is planned but not implemented yet. */
    public function isAvailable(): bool
    {
        return true;
    }

    abstract public function deployment(): string;

    /**
     * What this connector supports so far. The UI only offers what is true.
     * @return array{inventory: bool, backup: bool, restore: bool, file_restore: bool}
     */
    public function capabilities(): array
    {
        return ['inventory' => false, 'backup' => false, 'restore' => false, 'file_restore' => false];
    }

    public function can(string $capability): bool
    {
        return !empty($this->capabilities()[$capability]);
    }

    /** Datastore types (as in info.datastores) a full VM restore can write disks to. */
    public function restoreDatastoreTypes(): array
    {
        return [];
    }

    /** What the hypervisor calls a VM id ("VMID" on Proxmox, "MoRef" on vSphere). */
    public function vmIdLabel(): string
    {
        return 'ID';
    }

    /** What the hypervisor calls the machine a VM runs on ("Node", "ESXi host"). */
    public function hostLabel(): string
    {
        return 'Host';
    }

    /** Steps shown on the Add Hypervisor page. Plain text, one step per item. */
    public function setupSteps(): array
    {
        return [];
    }

    /**
     * Summary lines for the hypervisor header, from its stored info.
     * @return array<string, string> label => value
     */
    public function describeInfo(array $info): array
    {
        return [];
    }

    /** Hypervisor-level info kept in hypervisors.info (version, node, datastores...). */
    abstract public function normalizeInfo(array $input): array;

    /**
     * Turn one VM of the agent's report into the common model, or null to
     * skip it. Subclasses usually map their own fields and call normalizeVm().
     */
    public function normalizeVmInput(array $vm): ?array
    {
        return $this->normalizeVm($vm);
    }

    /** Validate and clamp a VM already in the common model. */
    final protected function normalizeVm(array $vm): ?array
    {
        $ref = trim((string) ($vm['ref'] ?? ''));
        if ($ref === '' || strlen($ref) > 64) {
            return null;
        }

        $disks = [];
        foreach (array_slice((array) ($vm['disks'] ?? []), 0, 64) as $d) {
            if (is_array($d) && ($disk = $this->normalizeDisk($d)) !== null) {
                $disks[] = $disk;
            }
        }

        $extra = is_array($vm['extra'] ?? null) ? $vm['extra'] : [];
        $extraJson = json_encode($extra);

        return [
            'ref' => $ref,
            'name' => substr((string) ($vm['name'] ?? $ref), 0, 255),
            'host' => substr((string) ($vm['host'] ?? ''), 0, 100),
            'status' => substr((string) ($vm['status'] ?? 'unknown'), 0, 20),
            'cpus' => isset($vm['cpus']) ? min(65535, max(0, (int) $vm['cpus'])) : null,
            'memory_bytes' => isset($vm['memory_bytes']) ? max(0, (int) $vm['memory_bytes']) : null,
            'uptime_seconds' => isset($vm['uptime_seconds']) ? max(0, (int) $vm['uptime_seconds']) : null,
            'guest_tools' => !empty($vm['guest_tools']) ? 1 : 0,
            'is_template' => !empty($vm['template']) ? 1 : 0,
            'tags' => isset($vm['tags']) && $vm['tags'] !== '' ? substr((string) $vm['tags'], 0, 255) : null,
            'disks' => $disks,
            'extra' => ($extraJson !== false && strlen($extraJson) <= 16384) ? $extra : [],
        ];
    }

    final protected function normalizeDisk(array $d): ?array
    {
        $key = substr((string) ($d['key'] ?? ''), 0, 40);
        if ($key === '') {
            return null;
        }
        $kind = in_array($d['kind'] ?? '', self::DISK_KINDS, true) ? $d['kind'] : 'disk';
        return [
            'key' => $key,
            'path' => substr((string) ($d['path'] ?? ''), 0, 255),
            'datastore' => isset($d['datastore']) ? substr((string) $d['datastore'], 0, 100) : null,
            'datastore_type' => isset($d['datastore_type']) ? substr((string) $d['datastore_type'], 0, 30) : null,
            'kind' => $kind,
            'size_bytes' => isset($d['size_bytes']) ? max(0, (int) $d['size_bytes']) : null,
            'default_excluded' => !empty($d['default_excluded']),
            'supported' => !empty($d['supported']),
            'unsupported_reason' => isset($d['unsupported_reason']) ? substr((string) $d['unsupported_reason'], 0, 200) : null,
        ];
    }
}
