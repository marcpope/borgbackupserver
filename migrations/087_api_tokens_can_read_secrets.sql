ALTER TABLE api_tokens
    ADD COLUMN can_read_secrets TINYINT(1) NOT NULL DEFAULT 0 AFTER kind;
