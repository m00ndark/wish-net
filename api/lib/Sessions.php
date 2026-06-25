<?php

namespace WishNet;

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Database.php';

// Opaque bearer-token sessions. The raw token is returned to the client once; only its SHA-256
// hash is stored. All expiry math is done DB-side (NOW()) so it's independent of PHP/DB timezone.
final class Sessions
{
    public static function create(int $userId, bool $isSuper): string
    {
        $token = bin2hex(random_bytes(32));
        $ttl = (int) (Config::get('session_ttl') ?? 2592000);
        Database::query(
            'INSERT INTO sessions (token_hash, user_id, is_super, created, expires)'
                . ' VALUES (:hash, :userId, :isSuper, NOW(), DATE_ADD(NOW(), INTERVAL ' . $ttl . ' SECOND))',
            [':hash' => self::hashToken($token), ':userId' => $userId, ':isSuper' => $isSuper ? 1 : 0]
        );
        return $token;
    }

    public static function resolve(string $token): ?object
    {
        $row = Database::query(
            'SELECT user_id, is_super FROM sessions WHERE token_hash = :hash AND expires > NOW()',
            [':hash' => self::hashToken($token)]
        )->fetch();
        return $row === false ? null : $row;
    }

    public static function delete(string $token): void
    {
        Database::query('DELETE FROM sessions WHERE token_hash = :hash', [':hash' => self::hashToken($token)]);
    }

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
