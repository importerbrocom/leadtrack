<?php

namespace App\Core;

/**
 * Dot-notation access to config/config.php.
 */
final class Config
{
    private static ?array $data = null;

    public static function load(?string $file = null): void
    {
        $file ??= dirname(__DIR__, 2) . '/config/config.php';

        if (!is_file($file)) {
            $example = dirname(__DIR__, 2) . '/config/config.example.php';
            throw new \RuntimeException(
                'config/config.php not found. Copy ' . basename($example) . ' to config.php and set your DB credentials.'
            );
        }

        self::$data = require $file;
    }

    public static function set(array $data): void
    {
        self::$data = $data;
    }

    /** @return mixed */
    public static function get(string $key, $default = null)
    {
        if (self::$data === null) {
            self::load();
        }

        $value = self::$data;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
