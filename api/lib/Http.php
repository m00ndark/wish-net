<?php

namespace WishNet;

// Request parsing and JSON response helpers shared by every endpoint.
final class Http
{
    public static function method(): string
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }

    // Resource path the .htaccess captured into ?path= (e.g. "lists/12"), without surrounding slashes.
    public static function path(): string
    {
        return trim($_GET['path'] ?? '', '/');
    }

    // Decoded JSON request body as an associative array (empty array when there is no body).
    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false)
        {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(int $status, string $message): never
    {
        self::json(['error' => $message], $status);
    }

    public static function noContent(): never
    {
        http_response_code(204);
        exit;
    }
}
