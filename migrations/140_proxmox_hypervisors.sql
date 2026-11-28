-- Hypervisors (step 1: inventory).
--
-- Hypervisor agents are a separate agent type: created from Add Hypervisor,
-- installed with `install.sh --hypervisor` (which adds the hypervisor
-- module), never listed under Clients. A client agent cannot report for a
-- hypervisor.
ALTER TABLE agents ADD COLUMN agent_type ENUM('client', 'hypervisor') NOT NULL DEFAULT 'client' AFTER name;

-- `type` is the slug of a connector in src/Services/Hypervisors (proxmox
-- today; ESXi/vCenter later).
--   agent_role 'host'  — the agent runs on the hypervisor itself (Proxmox node)
--   agent_role 'proxy' — the agent runs elsewhere and talks to the
--                        hypervisor's API with the settings in config_encrypted
CREATE TABLE hypervisors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    type VARCHAR(30) NOT NULL,
    agent_id INT DEFAULT NULL,
    agent_role ENUM('host', 'proxy') NOT NULL DEFAULT 'host',
    config_encrypted TEXT DEFAULT NULL,
    info JSON DEFAULT NULL,
    inventory_at DATETIME DEFAULT NULL,
    refresh_requested TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_hypervisor_agent (agent_id),
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
);

CREATE TABLE hypervisor_vms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    hypervisor_id INT NOT NULL,
    vm_ref VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT '',
    host VARCHAR(100) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'unknown',
    cpus SMALLINT UNSIGNED DEFAULT NULL,
    memory_bytes BIGINT UNSIGNED DEFAULT NULL,
    uptime_seconds BIGINT UNSIGNED DEFAULT NULL,
    guest_tools TINYINT(1) NOT NULL DEFAULT 0,
    is_template TINYINT(1) NOT NULL DEFAULT 0,
    tags VARCHAR(255) DEFAULT NULL,
    disks JSON DEFAULT NULL,
    extra JSON DEFAULT NULL,
    last_seen DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_hypervisor_vm (hypervisor_id, vm_ref),
    FOREIGN KEY (hypervisor_id) REFERENCES hypervisors(id) ON DELETE CASCADE
);
