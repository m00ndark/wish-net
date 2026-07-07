<?php

use WishNet\Http;
use WishNet\Auth;
use WishNet\Database;
use WishNet\Crypto;
use WishNet\Ownership;
use WishNet\Reservations;

require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';
require_once __DIR__ . '/../lib/Ownership.php';
require_once __DIR__ . '/../lib/Reservations.php';

function endpoint_wishes(array $segments): void
{
    $session = Auth::requireSession();
    $userId = (int) $session->user_id;
    $isSuper = (int) $session->is_super === 1;
    $method = Http::method();
    $id = isset($segments[0]) ? (int) $segments[0] : null;
    $sub = $segments[1] ?? null;

    if ($id === null)
    {
        if ($method === 'POST') { wishes_add($userId); }
        else { Http::error(405, 'Method not allowed.'); }
    }
    elseif ($sub === 'reserve')
    {
        if ($method === 'POST') { wishes_reserve($userId, $id); }
        else { Http::error(405, 'Method not allowed.'); }
    }
    elseif ($sub === null)
    {
        if ($method === 'PUT') { wishes_update($userId, $isSuper, $id); }
        elseif ($method === 'DELETE') { wishes_delete($userId, $isSuper, $id); }
        else { Http::error(405, 'Method not allowed.'); }
    }
    else
    {
        Http::error(404, 'Unknown wishes route.');
    }
}

function wishes_add(int $userId): void
{
    $body = Http::body();
    $listId = (int) ($body['listId'] ?? 0);
    if (!Ownership::ownsList($userId, $listId))
    {
        Http::error(403, 'You cannot add a wish to that list.');
    }
    [$categoryId, $description, $link, $count] = wishes_readBody($body);

    // Each wish gets a unique random reservation key (encrypted). Reservations are tied to a wish
    // only through this key, so it must not collide with any existing wish's key.
    $key = wishes_generateUniqueKey();
    Database::query(
        'INSERT INTO wishes (wishlist_id, category_id, short_description, link, max_reservation_count, reservation_key)'
            . ' VALUES (:list, :cat, :desc, :link, :max, :rkey)',
        [
            ':list' => $listId,
            ':cat' => $categoryId,
            ':desc' => $description,
            ':link' => $link,
            ':max' => $count,
            ':rkey' => Crypto::encrypt((string) $key),
        ]
    );
    Http::json(wishes_fetchDto((int) Database::connection()->lastInsertId()), 201);
}

function wishes_update(int $userId, bool $isSuper, int $id): void
{
    $ctx = wishes_listContext($id);
    if ($ctx === null)
    {
        Http::error(404, 'Wish not found.');
    }
    if (!wishes_owns($ctx, $userId))
    {
        Http::error(403, 'You cannot edit that wish.');
    }
    // A locked list is frozen: existing wishes can't be changed (only added). Super may override.
    if ((int) $ctx->is_locked_for_edit === 1 && !$isSuper)
    {
        Http::error(409, 'The list is locked; existing wishes cannot be edited.');
    }
    [$categoryId, $description, $link, $count] = wishes_readBody(Http::body());
    Database::query(
        'UPDATE wishes SET category_id = :cat, short_description = :desc, link = :link, max_reservation_count = :max WHERE wish_id = :id',
        [':cat' => $categoryId, ':desc' => $description, ':link' => $link, ':max' => $count, ':id' => $id]
    );
    // Editing a wish invalidates any reservations against it (legacy behavior).
    wishes_clearReservations($id);
    Http::json(wishes_fetchDto($id));
}

function wishes_delete(int $userId, bool $isSuper, int $id): void
{
    $ctx = wishes_listContext($id);
    if ($ctx === null)
    {
        Http::error(404, 'Wish not found.');
    }
    if (!wishes_owns($ctx, $userId))
    {
        Http::error(403, 'You cannot delete that wish.');
    }
    if ((int) $ctx->is_locked_for_edit === 1 && !$isSuper)
    {
        Http::error(409, 'The list is locked; existing wishes cannot be deleted.');
    }
    wishes_clearReservations($id);
    Database::query('DELETE FROM wishes WHERE wish_id = :id', [':id' => $id]);
    Http::noContent();
}

function wishes_reserve(int $userId, int $id): void
{
    $ctx = wishes_listContext($id);
    if ($ctx === null)
    {
        Http::error(404, 'Wish not found.');
    }
    $isChild = (int) $ctx->is_child_list === 1;
    // Can't reserve your own wish — unless it's a child list (you buy gifts for the child).
    if (wishes_owns($ctx, $userId) && !$isChild)
    {
        Http::error(403, 'You cannot reserve your own wish.');
    }
    // Reservations are only possible while the list is locked.
    if ((int) $ctx->is_locked_for_edit !== 1)
    {
        Http::error(409, 'The list is not locked for reservations.');
    }

    $count = (int) (Http::body()['count'] ?? 0);
    if ($count < 1)
    {
        Http::error(400, 'count must be at least 1.');
    }

    $key = Crypto::decrypt($ctx->reservation_key);
    $max = (int) $ctx->max_reservation_count;
    $current = Reservations::byWish([$id => $key])[$id] ?? [];

    if ($max === -1)
    {
        // Unlimited quantity, but once per person.
        if (in_array($userId, $current, true))
        {
            Http::error(409, 'You have already reserved this wish.');
        }
        if ($count !== 1)
        {
            Http::error(400, 'This wish can only be reserved once.');
        }
    }
    elseif (count($current) + $count > $max)
    {
        Http::error(409, 'Not enough remaining reservations.');
    }

    for ($i = 0; $i < $count; $i++)
    {
        Database::query('INSERT INTO reservations (`key`, reserved_by_user_id) VALUES (:key, :uid)',
            [':key' => Crypto::encrypt($key), ':uid' => Crypto::encrypt((string) $userId)]);
    }
    Http::noContent();
}

// --- helpers -------------------------------------------------------------------------------

// Reads + validates the add/edit body. Returns [categoryId, description, link, maxReservationCount].
function wishes_readBody(array $body): array
{
    $categoryId = (int) ($body['categoryId'] ?? 0);
    if ($categoryId <= 0)
    {
        Http::error(400, 'categoryId is required.');
    }
    $description = trim((string) ($body['description'] ?? ''));
    if ($description === '')
    {
        Http::error(400, 'description is required.');
    }
    $link = trim((string) ($body['link'] ?? ''));
    $count = array_key_exists('maxReservationCount', $body) ? (int) $body['maxReservationCount'] : -1;
    return [$categoryId, $description, $link, $count];
}

// The wish's list context: owner, share, lock/child flags, the wish's max + reservation key.
function wishes_listContext(int $wishId): ?object
{
    $row = Database::query(
        'SELECT wl.wishlist_id, wl.user_id, wl.shared_with_user_id, wl.is_locked_for_edit, wl.is_child_list,'
            . ' w.max_reservation_count, w.reservation_key'
            . ' FROM wishes w INNER JOIN wishlists wl ON w.wishlist_id = wl.wishlist_id WHERE w.wish_id = :id',
        [':id' => $wishId]
    )->fetch();
    return $row === false ? null : $row;
}

function wishes_owns(object $ctx, int $userId): bool
{
    return (int) $ctx->user_id === $userId || (int) $ctx->shared_with_user_id === $userId;
}

function wishes_generateUniqueKey(): int
{
    $existing = [];
    foreach (Database::query('SELECT reservation_key FROM wishes WHERE reservation_key IS NOT NULL')->fetchAll() as $wish)
    {
        $existing[Crypto::decrypt($wish->reservation_key)] = true;
    }
    do
    {
        $key = random_int(1, PHP_INT_MAX);
    }
    while (isset($existing[(string) $key]));
    return $key;
}

function wishes_clearReservations(int $wishId): void
{
    $row = Database::query('SELECT reservation_key FROM wishes WHERE wish_id = :id', [':id' => $wishId])->fetch();
    if ($row !== false && $row->reservation_key !== null)
    {
        Reservations::deleteByKeys([Crypto::decrypt($row->reservation_key)]);
    }
}

function wishes_fetchDto(int $id): ?array
{
    $wish = Database::query(
        'SELECT wish_id, wishlist_id, category_id, short_description, link, max_reservation_count FROM wishes WHERE wish_id = :id',
        [':id' => $id]
    )->fetch();
    if ($wish === false)
    {
        return null;
    }
    return [
        'id' => (int) $wish->wish_id,
        'listId' => (int) $wish->wishlist_id,
        'categoryId' => (int) $wish->category_id,
        'description' => $wish->short_description,
        'link' => $wish->link,
        'maxReservationCount' => (int) $wish->max_reservation_count,
    ];
}
