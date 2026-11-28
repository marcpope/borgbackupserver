<?php

namespace BBS\Services\Hypervisors;

/**
 * The hypervisor connectors BBS knows. To add one: write a class extending
 * HypervisorConnector, register it here, and add the matching connector to
 * the agent's hypervisor module (CONNECTORS in agent/bbs-hypervisor.py).
 *
 * get() is for reading existing rows (a hypervisor whose connector was
 * later removed still displays); available() is for anything that creates
 * or accepts data (Add Hypervisor, inventory reports).
 *
 * ESXi and vCenter are registered as PlannedConnector: hidden from Add
 * Hypervisor, refused everywhere else. See docs/hypervisors.md, open
 * question 2.
 */
class HypervisorRegistry
{
    /** @var array<string, HypervisorConnector>|null */
    private static ?array $connectors = null;

    /** @return array<string, HypervisorConnector> slug => connector, built once per request */
    public static function all(): array
    {
        if (self::$connectors === null) {
            $list = [
                new ProxmoxConnector(),
                new PlannedConnector(
                    'vmware_esxi',
                    'VMware ESXi (standalone)',
                    'Back up the VMs of a standalone ESXi host through its API, from a proxy agent.',
                    HypervisorConnector::DEPLOY_PROXY_AGENT
                ),
                new PlannedConnector(
                    'vmware_vcenter',
                    'VMware vCenter',
                    'Back up the VMs of a vCenter-managed cluster through its API, from a proxy agent.',
                    HypervisorConnector::DEPLOY_PROXY_AGENT
                ),
            ];
            self::$connectors = [];
            foreach ($list as $c) {
                self::$connectors[$c->slug()] = $c;
            }
        }
        return self::$connectors;
    }

    /** Any registered connector, planned ones included; null for an unknown slug. */
    public static function get(?string $slug): ?HypervisorConnector
    {
        return $slug !== null ? (self::all()[$slug] ?? null) : null;
    }

    /** A connector that exists and is implemented. */
    public static function available(?string $slug): ?HypervisorConnector
    {
        $c = self::get($slug);
        return ($c && $c->isAvailable()) ? $c : null;
    }
}
