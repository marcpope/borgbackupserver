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
}
