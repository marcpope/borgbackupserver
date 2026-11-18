-- VM backup plans (hypervisors, step 2).
--
-- A plan with hypervisor_id set backs up virtual machines instead of
-- directories. It runs as one ordinary backup job per VM (backup_jobs.vm_ref),
-- each producing its own archive named plan<id>-vm<ref>-<date>, so retention
-- is applied per VM (prune --glob-archives plan<id>-vm<ref>-*).
--
--   vm_selection 'selected' — the VMs listed in backup_plan_vms
--   vm_selection 'all'      — every VM of the hypervisor (templates aside)
--                             except rows with exclude_vm = 1, so VMs created
--                             later are backed up too
-- exclude_disks lists disk keys (scsi1, virtio2...) left out of that VM.
ALTER TABLE backup_plans
    ADD COLUMN hypervisor_id INT DEFAULT NULL AFTER repository_id,
    ADD COLUMN vm_selection ENUM('selected', 'all') DEFAULT NULL AFTER hypervisor_id,
    ADD CONSTRAINT fk_backup_plans_hypervisor FOREIGN KEY (hypervisor_id) REFERENCES hypervisors(id) ON DELETE CASCADE;

CREATE TABLE backup_plan_vms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    backup_plan_id INT NOT NULL,
    vm_ref VARCHAR(64) NOT NULL,
    exclude_vm TINYINT(1) NOT NULL DEFAULT 0,
    exclude_disks JSON DEFAULT NULL,
    UNIQUE KEY uniq_plan_vm (backup_plan_id, vm_ref),
    FOREIGN KEY (backup_plan_id) REFERENCES backup_plans(id) ON DELETE CASCADE
);

ALTER TABLE backup_jobs ADD COLUMN vm_ref VARCHAR(64) DEFAULT NULL AFTER backup_plan_id;

ALTER TABLE archives ADD COLUMN vm_ref VARCHAR(64) DEFAULT NULL AFTER backup_job_id;
