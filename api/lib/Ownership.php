<?php

namespace WishNet;

require_once __DIR__ . '/Database.php';

// Ownership checks. A list's owner OR its shared-with user may act on it (matches the legacy
// userOwnsWishList/userOwnsWish). Super-user is NOT exempt from these — super only affects visibility.
final class Ownership
{
    public static function ownsList(int $userId, int $listId): bool
    {
        $row = Database::query('SELECT user_id, shared_with_user_id FROM wishlists WHERE wishlist_id = :id', [':id' => $listId])->fetch();
        return $row !== false && ((int) $row->user_id === $userId || (int) $row->shared_with_user_id === $userId);
    }
}
