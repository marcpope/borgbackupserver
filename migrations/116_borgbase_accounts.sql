-- BorgBase gives every repository its own SSH user, so a BorgBase-only
-- install ended up with one storage location per repo and no view of the
-- account they all belong to. An account row holds the API token, the SSH
-- key BBS registered on BorgBase, and the plan's limits; locations hang
-- off it so the Storage page can show one card per account and the
-- account page can create, import and delete repos through the API.
CREATE TABLE IF NOT EXISTS borgbase_accounts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    api_token_encrypted TEXT DEFAULT NULL,
    -- Set once a write call has succeeded; cleared when one is refused.
    can_write TINYINT(1) DEFAULT NULL,
    ssh_private_key_encrypted TEXT DEFAULT NULL,
    ssh_public_key TEXT DEFAULT NULL,
    borgbase_ssh_key_id VARCHAR(32) DEFAULT NULL,
    plan_name VARCHAR(100) DEFAULT NULL,
    plan_max_repos INT DEFAULT NULL,
    plan_max_size_gb INT DEFAULT NULL,
    plan_included_gb INT DEFAULT NULL,
    -- Sum of every repo's currentUsage on the account, decimal bytes.
    usage_bytes BIGINT DEFAULT NULL,
    remote_repo_count INT DEFAULT NULL,
    checked_at DATETIME DEFAULT NULL,
    check_error VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

ALTER TABLE remote_ssh_configs
    ADD COLUMN borgbase_account_id INT DEFAULT NULL,
    ADD CONSTRAINT fk_remote_ssh_borgbase_account
        FOREIGN KEY (borgbase_account_id) REFERENCES borgbase_accounts(id) ON DELETE SET NULL;
