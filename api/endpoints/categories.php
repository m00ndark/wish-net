<?php

use WishNet\Http;
use WishNet\Auth;
use WishNet\Database;

require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Database.php';

// GET /categories — the fixed wish categories. Requires authentication.
function endpoint_categories(array $segments): void
{
    Auth::requireSession();
    if (Http::method() !== 'GET')
    {
        Http::error(405, 'Method not allowed.');
    }
    $rows = Database::query('SELECT category_id, name FROM categories ORDER BY category_id ASC')->fetchAll();
    $categories = array_map(static fn($row) => ['id' => (int) $row->category_id, 'name' => $row->name], $rows);
    Http::json($categories);
}
