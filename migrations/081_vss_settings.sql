-- Add VSS (Volume Shadow Copy Service) options to backup plans
-- use_vss:    enable VSS snapshot wrapping on Windows agents
-- vss_strict: 1 = abort backup if VSS fails, 0 = fall back to raw paths
ALTER TABLE backup_plans
    ADD COLUMN use_vss   TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN vss_strict TINYINT(1) NOT NULL DEFAULT 1;
