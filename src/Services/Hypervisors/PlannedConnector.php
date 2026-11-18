<?php

namespace BBS\Services\Hypervisors;

/**
 * A hypervisor we intend to support, shown on the Add Hypervisor page as
 * "planned". It never accepts an inventory. Replace it with a real
 * connector class when the work starts.
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
