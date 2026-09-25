-- Lets a non-admin user add clients. Each client they add is assigned to
-- them with full permissions on it (#481).
ALTER TABLE users ADD COLUMN can_create_clients TINYINT(1) NOT NULL DEFAULT 0 AFTER all_clients;
