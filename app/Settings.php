<?php
final class Settings
{
    public static function all(): array
    {
        $rows = Db::pdo()->query('SELECT setting_key, setting_value FROM settings ORDER BY setting_key')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['setting_key']] = $row['setting_value'];
        }
        return $out;
    }

    public static function get(string $key, $default = null)
    {
        $stmt = Db::pdo()->prepare('SELECT setting_value FROM settings WHERE setting_key=?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : $value;
    }

    public static function getInt(string $key, int $default): int
    {
        return (int)self::get($key, $default);
    }

    public static function getFloat(string $key, float $default): float
    {
        return (float)self::get($key, $default);
    }

    public static function getBool(string $key, bool $default): bool
    {
        $value = self::get($key, $default ? '1' : '0');
        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    public static function setMany(array $values): void
    {
        $pdo = Db::pdo();
        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        foreach ($values as $key => $value) {
            $stmt->execute([(string)$key, $value === null ? null : (string)$value]);
        }
    }
}
