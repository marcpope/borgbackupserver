<?php

namespace BBS\Services\Hypervisors;

/**
 * A hypervisor we intend to support but have not implemented. It is not
 * shown on Add Hypervisor (HypervisorController::add() lists available
 * connectors only), cannot be created, and never accepts an inventory
 * (isAvailable() false, normalizeVmInput() null). Replace it with a real
 * connector class when the work starts.
 *
 * Whether to keep these placeholders at all is open question 2 in
 * docs/hypervisors.md#questions-for-the-maintainer.
 */
class PlannedConnector extends HypervisorConnector
{
    public function __construct(
        private string $slug,
        private string $label,
        private string $description,
        private string $deployment,
    ) {
    }

    public function slug(): string { return $this->slug; }
    public function label(): string { return $this->label; }
    public function description(): string { return $this->description; }
    public function deployment(): string { return $this->deployment; }
    public function isAvailable(): bool { return false; }
    public function icon(): string { return 'cloud'; }

    public function normalizeInfo(array $input): array
    {
        return [];
    }

    public function normalizeVmInput(array $vm): ?array
    {
        return null;
    }
}
