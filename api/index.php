<?php

// Front controller for the Wish API.
// The .htaccess rewrites every /wish-net/api/* request here with the resource path in ?path=.
// See PLAN.md section 7 (API surface) and section 11 (deployment).

use WishNet\Http;

require_once __DIR__ . '/lib/Http.php';

// Default timezone for all date math (lock auto-unlock, recovery expiry, reserve dates) —
// matches the legacy app (PLAN.md section 7).
date_default_timezone_set('Europe/Stockholm');

$resource = explode('/', Http::path())[0];

// --- routing -------------------------------------------------------------------------------
// Endpoint handlers (auth, lists, wishes, reservations, categories) are added in phases 2-4
// under endpoints/ and will require lib/Config.php + lib/Database.php as needed. For now only
// a config/DB-independent health check is wired up.

switch ($resource)
{
    case 'ping':
        Http::json([
            'status' => 'ok',
            'time'   => date('c'),
        ]);

    default:
        Http::error(404, "Unknown resource: '$resource'");
}
