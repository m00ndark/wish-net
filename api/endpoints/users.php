<?php

use WishNet\Http;
use WishNet\Database;

require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Database.php';

// GET /users — id + name only, for the login and share dropdowns. Intentionally PUBLIC (the login
// page needs it before authentication) and never exposes passwords or emails.
function endpoint_users(array $segments): void
{
    if (Http::method() !== 'GET')
    {
        Http::error(405, 'Method not allowed.');
    }
    $rows = Database::query('SELECT user_id, user_name FROM users ORDER BY user_name ASC')->fetchAll();
    $users = array_map(static fn($row) => ['id' => (int) $row->user_id, 'userName' => $row->user_name], $rows);
    Http::json($users);
}
