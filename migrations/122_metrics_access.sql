-- Read-only access to the monitoring endpoints.
--
-- Until now, scraping BBS meant handing an ADMIN API token to the monitoring
-- system: /api/v1/metrics and /api/v1/health both call requireApiToken(),
-- which demands the admin role. A token that may delete a client has no
-- business sitting in a Prometheus configuration.
--
-- Two settings replace that: which addresses may read the monitoring
-- endpoints, and whether each of those ranges must also present a token.
-- Off by default — an install that never opens it keeps today's behaviour.
INSERT INTO settings (`key`, `value`) VALUES
    ('metrics_enabled', '0'),
    -- Loopback only, token required: the safe pair to start from. Anything
    -- wider is the operator's decision, made on the settings page where the
    -- consequences are spelled out.
    ('metrics_acl', '[{"cidr":"127.0.0.1\/32","token":true,"note":"localhost"},{"cidr":"::1\/128","token":true,"note":"localhost"}]'),
    -- Whose X-Forwarded-For we believe. Empty means: nobody, trust the socket.
    ('metrics_trusted_proxies', '[]'),
    ('metrics_cache_seconds', '30'),
    ('metrics_rate_per_minute', '12')
ON DUPLICATE KEY UPDATE `value` = `value`;
