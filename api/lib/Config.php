<?php

namespace WishNet;

require_once __DIR__ . '/Http.php';

// Loads the server-only config.php once and exposes individual settings.
final class Config
{
    private static ?array $values = null;

    public static function all(): array
    {
        if (self::$values === null)
        {
            $path = __DIR__ . '/../config.php';
            if (!file_exists($path))
            {
                Http::error(500, 'Server is not configured (missing config.php).');
            }
            self::$values = require $path;
        }
        return self::$values;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }
}
