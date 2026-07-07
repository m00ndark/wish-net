<?php

namespace WishNet;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Crypto.php';

// Reservations are matched to wishes by DECRYPTED reservation key (random IVs make the ciphertext
// unmatchable in SQL), so these helpers decrypt the reservations table and match in PHP. Fine at
// family scale. See PLAN.md section 6.
final class Reservations
{
    // Returns [wish_id => [reservedByUserId, ...]] for the given [wish_id => decryptedKey] map.
    public static function byWish(array $keyByWishId): array
    {
        if ($keyByWishId === [])
        {
            return [];
        }
        $wishByKey = [];
        foreach ($keyByWishId as $wishId => $key)
        {
            $wishByKey[$key] = $wishId;
        }
        $result = [];
        foreach (Database::query('SELECT `key`, reserved_by_user_id FROM reservations')->fetchAll() as $reservation)
        {
            $key = Crypto::decrypt($reservation->key);
            if (isset($wishByKey[$key]))
            {
                $result[$wishByKey[$key]][] = (int) Crypto::decrypt($reservation->reserved_by_user_id);
            }
        }
        return $result;
    }

    // Delete every reservation whose decrypted key is in the given set of decrypted keys.
    public static function deleteByKeys(array $decryptedKeys): void
    {
        if ($decryptedKeys === [])
        {
            return;
        }
        $wanted = array_flip($decryptedKeys);
        $ids = [];
        foreach (Database::query('SELECT reservation_id, `key` FROM reservations')->fetchAll() as $reservation)
        {
            if (isset($wanted[Crypto::decrypt($reservation->key)]))
            {
                $ids[] = (int) $reservation->reservation_id;
            }
        }
        if ($ids === [])
        {
            return;
        }
        $in = implode(', ', $ids);
        Database::query("DELETE FROM reservations WHERE reservation_id IN ($in)");
    }
}
