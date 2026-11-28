-- File-level browse sessions of VM backups. While a session is open the
-- hypervisor agent keeps the restore point mounted (borg mount), which holds
-- a lock on the repository; the agent reports when sessions open and close
-- so the UI can show how long a repository has been locked, and by what.
CREATE TABLE vm_browse_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    agent_id INT NOT NULL,
    archive_id INT NOT NULL,
    repository_id INT DEFAULT NULL,
    opened_at DATETIME NOT NULL,
    last_used_at DATETIME NOT NULL,
    closed_at DATETIME DEFAULT NULL,
    close_reason VARCHAR(200) DEFAULT NULL,
    KEY idx_vm_browse_open (agent_id, closed_at),
    FOREIGN KEY (agent_id) REFERENCES agents(id) ON DELETE CASCADE
);
