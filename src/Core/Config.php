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
     * Hosted-mode flag — driven entirely by the HOSTED env var on the
     * container. NOT persisted to the DB on purpose: the same image
     * running anywhere else (debug, recovery, dev) should NOT silently
     * inherit hosted behavior from a stale DB row.
     *
     * Accepts: 1, true, yes (case-insensitive).
     */
    /**
     * A hostname, an IPv4 address or an IPv6 address (bracketed when a port
     * follows), with an optional :port when $allowPort.
     */
    public static function isValidHost(string $host, bool $allowPort = true): bool
    {
        // The host goes into .env (APP_URL), repository URLs and agent
        // configs, so nothing but a hostname or address may get through.
        $port = '(?::(?:[1-9][0-9]{0,3}|[1-5][0-9]{4}|6[0-4][0-9]{3}|65[0-4][0-9]{2}|655[0-2][0-9]|6553[0-5]))';
        $name = '[A-Za-z0-9](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9])?)*';
        $suffix = $allowPort ? $port . '?' : '';
        if (strlen($host) <= 253 + 6 && preg_match('/^' . $name . $suffix . '$/D', $host)) {
            return true;
        }
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\]' . $suffix . '$/D', $host, $m)) {
            return (bool) filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
        }
        return (bool) filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    }

    public static function isHosted(): bool
    {
        $value = $_ENV['HOSTED'] ?? getenv('HOSTED') ?: '';
        return in_array(strtolower((string) $value), ['1', 'true', 'yes'], true);
    }
}
