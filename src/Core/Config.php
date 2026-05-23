<?php

namespace BBS\Core;

use Dotenv\Dotenv;

class Config
{
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2) . '/config');
        $dotenv->load();
        self::$loaded = true;

        // Force UTC for all PHP date/time operations — display conversion happens via TimeHelper
        date_default_timezone_set('UTC');
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return $_ENV[$key] ?? $default;
    }

    public static function isDebug(): bool
    {
        return self::get('APP_DEBUG', 'false') === 'true';
    }

    /**
     * Hosted-mode gate. When BBS_HOSTED=1 in the environment (typically set
     * by a docker-compose file for a managed-service deployment), the
     * customer-facing UI hides storage-management surfaces (storage
     * locations, remote SSH configs, S3 sync, server backups) because the
     * hosting platform owns all infrastructure. The platform itself
     * provisions repositories using a single managed default storage
     * location via the API. Not user-toggleable, not stored in the
     * settings table — only the env var controls this.
     */
    public static function isHosted(): bool
    {
        // Read directly from env without triggering full Dotenv load so
        // this stays cheap even when called from hot paths. docker-compose
        // and bbs-install both inject env vars at the process level, which
        // PHP picks up via $_ENV / getenv without dotenv parsing.
        $value = $_ENV['BBS_HOSTED'] ?? getenv('BBS_HOSTED');
        return $value === '1' || $value === 'true' || $value === 'yes';
    }
}
