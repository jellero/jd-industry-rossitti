<?php
final class ApiResponse
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok($data = null): void
    {
        self::json(['ok' => true, 'data' => $data]);
    }

    public static function error(string $message, int $status = 400, $details = null): void
    {
        self::json(['ok' => false, 'error' => $message, 'details' => $details], $status);
    }
}
