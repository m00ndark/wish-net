<?php

// Front controller for the Wish API.
// The .htaccess rewrites every /wish-net/api/* request here with the resource path in ?path=.
// See PLAN.md section 7 (API surface) and section 11 (deployment).

use WishNet\Http;

require_once __DIR__ . '/lib/Http.php';

// Default timezone for all date math (lock auto-unlock, recovery expiry, reserve dates) —
// matches the legacy app (PLAN.md section 7).
date_default_timezone_set('Europe/Stockholm');

// CORS: production serves the client same-origin (no CORS needed), but local `dotnet run` hosts
// the client on a different port. Reflect the request origin (bearer-token API, no cookies) and
// answer preflight requests.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '')
{
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Vary: Origin');
}
if (Http::method() === 'OPTIONS')
{
    http_response_code(204);
    exit;
}

$segments = explode('/', Http::path());
$resource = $segments[0];
$rest = array_slice($segments, 1);

// --- routing -------------------------------------------------------------------------------
// Each endpoint handler lives under endpoints/ and ends by sending a response (Http::json/error,
// which exit). Lists, wishes and reservations are added in phases 3-4.

switch ($resource)
{
    case 'ping':
        Http::json(['status' => 'ok', 'time' => date('c')]);

    case 'auth':
        require __DIR__ . '/endpoints/auth.php';
        endpoint_auth($rest);
        break;

    case 'users':
        require __DIR__ . '/endpoints/users.php';
        endpoint_users($rest);
        break;

    case 'categories':
        require __DIR__ . '/endpoints/categories.php';
        endpoint_categories($rest);
        break;

    case 'lists':
        require __DIR__ . '/endpoints/lists.php';
        endpoint_lists($rest);
        break;

    case 'wishes':
        require __DIR__ . '/endpoints/wishes.php';
        endpoint_wishes($rest);
        break;

    default:
        Http::error(404, "Unknown resource: '$resource'");
}
