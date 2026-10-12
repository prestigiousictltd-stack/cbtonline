<?php
declare(strict_types=1);

namespace App\Core;

final class Settings
{
    private static array $cache = [];
    private static bool  $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) return;
        try {
            $rows = Database::selectAll('SELECT setting_key, setting_value, value_type FROM system_settings');
            foreach ($rows as $row) {
                self::$cache[$row['setting_key']] = self::cast($row['setting_value'], $row['value_type']);
            }
        } catch (\Throwable $e) {
            Logger::warning('Settings::load failed', ['error' => $e->getMessage()]);
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$cache[$key] ?? $default;
    }

    /** @return array<string, mixed> Every setting, keyed by setting_key. */
    public static function all(): array
    {
        if (!self::$loaded) {
            self::load();
        }
        return self::$cache;
    }

    /** @return array<string, mixed> Only settings whose key starts with $prefix. */
    public static function group(string $prefix): array
    {
        $out = [];
        $prefix = rtrim($prefix, '.') . '.';
        foreach (self::all() as $k => $v) {
            if (str_starts_with($k, $prefix)) {
                $out[substr($k, strlen($prefix))] = $v;
            }
        }
        return $out;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::$cache);
    }

    public static function set(string $key, mixed $value, ?int $superAdminId = null): void
    {
        $isJson = is_array($value);
        $stored = $isJson ? json_encode($value) : (string) $value;
        $type   = $isJson ? 'json' : 'string';

        Database::execute(
            'INSERT INTO system_settings (setting_key, setting_value, value_type, updated_by_type, updated_by_id)
             VALUES (:k, :v, :t, "super_admin", :uid)
             ON DUPLICATE KEY UPDATE
                setting_value    = VALUES(setting_value),
                value_type       = VALUES(value_type),
                updated_by_type  = "super_admin",
                updated_by_id    = VALUES(updated_by_id)',
            [':k' => $key, ':v' => $stored, ':t' => $type, ':uid' => $superAdminId]
        );

        self::$cache[$key] = $value;
    }

    public static function refresh(): void
    {
        self::$loaded = false;
        self::$cache  = [];
        self::load();
    }

    private static function cast(?string $value, string $type): mixed
    {
        if ($value === null) return null;
        return match ($type) {
            'integer' => (int) $value,
            'decimal' => (string) $value,   // keep money as string
            'boolean' => (bool) (int) $value,
            'json'    => json_decode($value, true),
            default   => $value,
        };
    }
}