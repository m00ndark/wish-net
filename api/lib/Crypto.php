<?php

namespace WishNet;

require_once __DIR__ . '/Config.php';

// AES encryption for reservation keys and reserved-by-user ids — UNCHANGED from the legacy app
// (random IV per value, prepended to the ciphertext, base64-encoded) so existing rows still
// decrypt. Cipher and key come from config.php and must not change. See PLAN.md section 6.
final class Crypto
{
    public static function encrypt(string $plain): string
    {
        [$cipher, $key] = self::params();
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = openssl_random_pseudo_bytes($ivLength);
        $encrypted = openssl_encrypt($plain, $cipher, $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    public static function decrypt(string $encoded): string
    {
        [$cipher, $key] = self::params();
        $decoded = base64_decode($encoded);
        $ivLength = openssl_cipher_iv_length($cipher);
        $iv = substr($decoded, 0, $ivLength);
        return openssl_decrypt(substr($decoded, $ivLength), $cipher, $key, 0, $iv);
    }

    private static function params(): array
    {
        $config = Config::get('encryption');
        return [$config['cipher'], $config['key']];
    }
}
