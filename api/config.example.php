<?php

// Copy this file to config.php and fill in the real values.
// config.php is git-ignored and lives only on the server (and your local XAMPP) — never commit it.
// Requires PHP 8.1+.

return [

    // Database — matches the legacy DSN exactly: a utf8 connection over latin1 columns
    // (see PLAN.md section 4). host=127.0.0.1 forces TCP rather than a unix socket.
    'db' => [
        'dsn'      => 'mysql:host=127.0.0.1;dbname=u137273347_wishlist_db;charset=utf8',
        'username' => 'CHANGE_ME',
        'password' => 'CHANGE_ME',
    ],

    // Reservation encryption — UNCHANGED from the legacy app so existing rows still decrypt
    // (see PLAN.md section 6). Do not change the cipher or key value.
    'encryption' => [
        'cipher' => 'aes-128-ctr',
        'key'    => 'CHANGE_ME',
    ],

    // Super-user master password (PLAN.md section 5) — store a hash, never plaintext.
    // Generate with: php -r "echo password_hash('the-master-password', PASSWORD_DEFAULT), PHP_EOL;"
    'master_password_hash' => 'CHANGE_ME',

    // Outgoing mail (new-user notification, password recovery).
    'mail' => [
        'from'     => 'wish@m00ndark.com',
        'reply_to' => 'mattias.wijkstrom@gmail.com',
    ],

    // Bearer-token session lifetime, in seconds (default 30 days).
    'session_ttl' => 2592000,
];
