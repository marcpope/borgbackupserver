-- How often a hypervisor agent reports the VM inventory, in minutes (1-60).
ALTER TABLE hypervisors ADD COLUMN inventory_interval_minutes TINYINT UNSIGNED NOT NULL DEFAULT 5 AFTER inventory_at;
