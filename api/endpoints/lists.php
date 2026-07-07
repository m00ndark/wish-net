<?php

use WishNet\Http;
use WishNet\Auth;
use WishNet\Database;
use WishNet\Crypto;

require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Crypto.php';

// Shared SELECT for a list row joined to its owner and (optional) shared-with user names.
const LIST_SELECT =
    'SELECT w.wishlist_id, w.user_id, w.shared_with_user_id, w.title, w.is_child_list, w.child_name,'
    . ' w.is_locked_for_edit, UNIX_TIMESTAMP(w.locked_until) lu, owner.user_name owner_name, shared.user_name shared_name'
    . ' FROM wishlists w'
    . ' INNER JOIN users owner ON w.user_id = owner.user_id'
    . ' LEFT JOIN users shared ON w.shared_with_user_id = shared.user_id';

function endpoint_lists(array $segments): void
{
    $session = Auth::requireSession();
    $userId = (int) $session->user_id;
    $isSuper = (int) $session->is_super === 1;
    $method = Http::method();
    $id = isset($segments[0]) ? (int) $segments[0] : null;
    $sub = $segments[1] ?? null;

    if ($id === null)
    {
        if ($method === 'GET') { lists_getAll($userId, $isSuper); }
        elseif ($method === 'POST') { lists_add($userId); }
        else { Http::error(405, 'Method not allowed.'); }
    }
    elseif ($sub === 'lock')
    {
        if ($method === 'POST') { lists_lock($userId, $id); }
        else { Http::error(405, 'Method not allowed.'); }
    }
    elseif ($sub === null)
    {
        if ($method === 'GET') { lists_getOne($userId, $isSuper, $id); }
        elseif ($method === 'PUT') { lists_update($userId, $id); }
        elseif ($method === 'DELETE') { lists_delete($userId, $id); }
        else { Http::error(405, 'Method not allowed.'); }
    }
    else
    {
        Http::error(404, 'Unknown lists route.');
    }
}

// GET /lists — home page: my lists (owned or shared with me; all for super) + others' locked lists.
function lists_getAll(int $userId, bool $isSuper): void
{
    lists_autoUnlock();

    $where = $isSuper ? '' : ' WHERE w.user_id = :userId OR w.shared_with_user_id = :userId';
    $mine = Database::query(
        LIST_SELECT . $where . ' ORDER BY w.title ASC, w.child_name ASC',
        $isSuper ? [] : [':userId' => $userId]
    )->fetchAll();
    $myLists = array_map(static fn($row) => lists_mapRow($row, $userId), $mine);

    // Others' LOCKED lists (that aren't mine), grouped by a display name.
    $locked = Database::query(
        LIST_SELECT . ' WHERE w.is_locked_for_edit = 1 AND w.user_id <> :uidA'
            . ' AND (w.shared_with_user_id IS NULL OR w.shared_with_user_id <> :uidB) ORDER BY owner_name ASC',
        [':uidA' => $userId, ':uidB' => $userId]
    )->fetchAll();

    $groups = [];
    foreach ($locked as $row)
    {
        if ((int) $row->is_child_list === 1)
        {
            $name = $row->child_name;
        }
        elseif ($row->shared_with_user_id !== null)
        {
            $name = $row->owner_name . ' & ' . $row->shared_name;
        }
        else
        {
            $name = $row->owner_name;
        }
        $groups[$name][] = ['id' => (int) $row->wishlist_id, 'title' => $row->title];
    }
    ksort($groups); // approximates the legacy ORDER BY on the display name
    $othersLists = [];
    foreach ($groups as $name => $lists)
    {
        $othersLists[] = ['name' => $name, 'lists' => $lists];
    }

    Http::json(['myLists' => $myLists, 'othersLists' => $othersLists]);
}

// GET /lists/{id} — single list metadata + view authorization (wishes are added in phase 4).
function lists_getOne(int $userId, bool $isSuper, int $id): void
{
    $dto = lists_fetchDto($id, $userId);
    if ($dto === null)
    {
        Http::error(404, 'List not found.');
    }
    if (!$dto['isMine'] && !$dto['isLocked'] && !$isSuper)
    {
        Http::error(403, 'That list is not available.');
    }
    Http::json($dto);
}

function lists_add(int $userId): void
{
    [$title, $shared, $isChild, $childName] = lists_readBody();
    Database::query(
        'INSERT INTO wishlists (user_id, title, is_locked_for_edit, locked_until, shared_with_user_id, is_child_list, child_name)'
            . ' VALUES (:uid, :title, 0, NULL, :shared, :child, :cname)',
        [':uid' => $userId, ':title' => $title, ':shared' => $shared, ':child' => $isChild ? 1 : 0, ':cname' => $childName]
    );
    $id = (int) Database::connection()->lastInsertId();
    Http::json(lists_fetchDto($id, $userId), 201);
}

function lists_update(int $userId, int $id): void
{
    if (!lists_userOwns($userId, $id))
    {
        Http::error(403, 'You cannot edit that list.');
    }
    [$title, $shared, $isChild, $childName] = lists_readBody();
    Database::query(
        'UPDATE wishlists SET title = :title, shared_with_user_id = :shared, is_child_list = :child, child_name = :cname'
            . ' WHERE wishlist_id = :id',
        [':title' => $title, ':shared' => $shared, ':child' => $isChild ? 1 : 0, ':cname' => $childName, ':id' => $id]
    );
    Http::json(lists_fetchDto($id, $userId));
}

function lists_delete(int $userId, int $id): void
{
    if (!lists_userOwns($userId, $id))
    {
        Http::error(403, 'You cannot delete that list.');
    }
    // Clear the list's reservations first (they aren't FK'd; the wishes cascade on delete).
    lists_deleteReservationsForList($id);
    Database::query('DELETE FROM wishlists WHERE wishlist_id = :id', [':id' => $id]);
    Http::noContent();
}

function lists_lock(int $userId, int $id): void
{
    if (!lists_userOwns($userId, $id))
    {
        Http::error(403, 'You cannot lock that list.');
    }
    $lockDate = (string) (Http::body()['lockDate'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $lockDate) !== 1)
    {
        Http::error(400, 'lockDate must be YYYY-MM-DD.');
    }
    if (strtotime($lockDate) <= strtotime('today'))
    {
        Http::error(400, 'lockDate must be in the future.');
    }
    Database::query('UPDATE wishlists SET is_locked_for_edit = 1, locked_until = :d WHERE wishlist_id = :id',
        [':d' => $lockDate, ':id' => $id]);
    Http::noContent();
}

// --- helpers -------------------------------------------------------------------------------

// Unlock lists whose lock date passed more than a day ago (legacy home.php sweep).
function lists_autoUnlock(): void
{
    Database::query(
        'UPDATE wishlists SET is_locked_for_edit = 0, locked_until = NULL'
            . ' WHERE is_locked_for_edit = 1 AND locked_until < DATE_SUB(NOW(), INTERVAL 1 DAY)'
    );
}

// Owner or the shared-with user may mutate a list (matches legacy userOwnsWishList; super is not exempt).
function lists_userOwns(int $userId, int $id): bool
{
    $row = Database::query('SELECT user_id, shared_with_user_id FROM wishlists WHERE wishlist_id = :id', [':id' => $id])->fetch();
    return $row !== false && ((int) $row->user_id === $userId || (int) $row->shared_with_user_id === $userId);
}

// Read + validate the add/edit body. Returns [title, sharedWithUserId|null, isChildList, childName].
function lists_readBody(): array
{
    $body = Http::body();
    $title = trim((string) ($body['title'] ?? ''));
    if ($title === '')
    {
        Http::error(400, 'title is required.');
    }
    $shared = lists_normalizeShared($body['sharedWithUserId'] ?? null);
    $isChild = !empty($body['isChildList']);
    $childName = $isChild ? trim((string) ($body['childName'] ?? '')) : '';
    if ($isChild && $childName === '')
    {
        Http::error(400, 'childName is required for a child list.');
    }
    return [$title, $shared, $isChild, $childName];
}

// Normalize the several legacy "not shared" representations (NULL, 0, -1) to NULL.
function lists_normalizeShared($value): ?int
{
    if ($value === null)
    {
        return null;
    }
    $id = (int) $value;
    return $id <= 0 ? null : $id;
}

function lists_fetchDto(int $id, int $userId): ?array
{
    $row = Database::query(LIST_SELECT . ' WHERE w.wishlist_id = :id', [':id' => $id])->fetch();
    return $row === false ? null : lists_mapRow($row, $userId);
}

function lists_mapRow(object $row, int $userId): array
{
    return [
        'id' => (int) $row->wishlist_id,
        'title' => $row->title,
        'ownerUserId' => (int) $row->user_id,
        'ownerName' => $row->owner_name,
        'sharedWithUserId' => $row->shared_with_user_id === null ? null : (int) $row->shared_with_user_id,
        'sharedWithName' => $row->shared_name,
        'isChildList' => (int) $row->is_child_list === 1,
        'childName' => $row->child_name,
        'isLocked' => (int) $row->is_locked_for_edit === 1,
        'lockedUntil' => $row->lu === null ? null : date('Y-m-d', (int) $row->lu),
        'isOwner' => (int) $row->user_id === $userId,
        'isMine' => (int) $row->user_id === $userId || (int) $row->shared_with_user_id === $userId,
    ];
}

// Delete the reservations belonging to a list's wishes (matched by decrypted reservation key).
function lists_deleteReservationsForList(int $id): void
{
    $keys = [];
    foreach (Database::query('SELECT reservation_key FROM wishes WHERE wishlist_id = :id AND reservation_key IS NOT NULL', [':id' => $id])->fetchAll() as $wish)
    {
        $keys[Crypto::decrypt($wish->reservation_key)] = true;
    }
    if ($keys === [])
    {
        return;
    }
    $reservationIds = [];
    foreach (Database::query('SELECT reservation_id, `key` FROM reservations')->fetchAll() as $reservation)
    {
        if (isset($keys[Crypto::decrypt($reservation->key)]))
        {
            $reservationIds[] = (int) $reservation->reservation_id;
        }
    }
    if ($reservationIds === [])
    {
        return;
    }
    $in = implode(', ', $reservationIds);
    Database::query("DELETE FROM reservations WHERE reservation_id IN ($in)");
}
