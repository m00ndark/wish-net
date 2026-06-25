<?php

namespace WishNet;

// Password hashing with transparent migration off the legacy salted-SHA1 scheme.
// Legacy format (from the old common.php generateHash): salt(16 chars) + sha1(salt + plain),
// i.e. a 56-char hex string. Modern hashes use password_hash() and start with "$".
final class Passwords
{
    private const LEGACY_SALT_LENGTH = 16;

    public static function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    // Verify a plaintext password against a stored hash, supporting both the modern and legacy formats.
    public static function verify(string $plain, string $stored): bool
    {
        if (self::isLegacy($stored))
        {
            $expected = self::legacyHash($plain, substr($stored, 0, self::LEGACY_SALT_LENGTH));
            return hash_equals($stored, $expected);
        }
        return password_verify($plain, $stored);
    }

    // True when a successful verify should be followed by a re-hash to the modern format
    // (the stored hash is legacy, or uses outdated modern parameters).
    public static function needsUpgrade(string $stored): bool
    {
        return self::isLegacy($stored) || password_needs_rehash($stored, PASSWORD_DEFAULT);
    }

    private static function isLegacy(string $stored): bool
    {
        // Modern hashes always start with "$" ("$2y$" bcrypt, "$argon2..."); legacy never does.
        return $stored === '' || $stored[0] !== '$';
    }

    private static function legacyHash(string $plain, string $salt): string
    {
        $salt = substr($salt, 0, self::LEGACY_SALT_LENGTH);
        return $salt . sha1($salt . $plain);
    }
}
