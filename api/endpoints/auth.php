<?php

use WishNet\Http;
use WishNet\Database;
use WishNet\Passwords;
use WishNet\Sessions;
use WishNet\Auth;
use WishNet\Config;

require_once __DIR__ . '/../lib/Http.php';
require_once __DIR__ . '/../lib/Database.php';
require_once __DIR__ . '/../lib/Passwords.php';
require_once __DIR__ . '/../lib/Sessions.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/Config.php';

// Suffix that elevates a session to super-user when appended to the password (PLAN.md section 5).
const SUPER_SUFFIX = "\u{00A7}"; // "§" (U+00A7)

function endpoint_auth(array $segments): void
{
    $action = $segments[0] ?? '';
    $method = Http::method();

    if ($action === 'login' && $method === 'POST')
    {
        auth_login();
    }
    elseif ($action === 'logout' && $method === 'POST')
    {
        auth_logout();
    }
    elseif ($action === 'register' && $method === 'POST')
    {
        auth_register();
    }
    elseif ($action === 'recover' && $method === 'POST')
    {
        auth_recover();
    }
    elseif ($action === 'reset' && $method === 'POST')
    {
        auth_reset();
    }
    else
    {
        Http::error(404, "Unknown auth action: '$action'");
    }
}

function auth_login(): void
{
    $body = Http::body();
    $userId = $body['userId'] ?? null;
    $password = $body['password'] ?? null;
    if ($userId === null || $password === null)
    {
        Http::error(400, 'userId and password are required.');
    }

    // A trailing "§" elevates the session to super-user. Strip it before any password check.
    $isSuper = false;
    if (str_ends_with($password, SUPER_SUFFIX))
    {
        $isSuper = true;
        $password = substr($password, 0, -strlen(SUPER_SUFFIX));
    }

    $user = Database::query('SELECT user_id, user_name, password FROM users WHERE user_id = :id', [':id' => $userId])->fetch();
    if ($user === false)
    {
        Http::error(401, 'Invalid credentials.');
    }

    // The master password (config) logs into the selected account without that user's own password.
    $masterHash = Config::get('master_password_hash');
    $authenticated = is_string($masterHash) && $masterHash !== '' && password_verify($password, $masterHash);

    if (!$authenticated)
    {
        if (!Passwords::verify($password, $user->password))
        {
            Http::error(401, 'Invalid credentials.');
        }
        // Transparently re-hash legacy/outdated hashes after a successful verify.
        if (Passwords::needsUpgrade($user->password))
        {
            Database::query('UPDATE users SET password = :hash WHERE user_id = :id',
                [':hash' => Passwords::hash($password), ':id' => $user->user_id]);
        }
    }

    $token = Sessions::create((int) $user->user_id, $isSuper);
    Http::json([
        'token' => $token,
        'user' => ['id' => (int) $user->user_id, 'userName' => $user->user_name, 'isSuper' => $isSuper],
    ]);
}

function auth_logout(): void
{
    $token = Auth::bearerToken();
    if ($token !== null)
    {
        Sessions::delete($token);
    }
    Http::noContent();
}

function auth_register(): void
{
    $body = Http::body();
    $userName = trim((string) ($body['userName'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    if ($userName === '' || $password === '')
    {
        Http::error(400, 'userName and password are required.');
    }

    // user_name is UNIQUE — report a conflict rather than letting the insert fail.
    $existing = Database::query('SELECT user_id FROM users WHERE user_name = :name', [':name' => $userName])->fetch();
    if ($existing !== false)
    {
        Http::error(409, 'That name is already taken.');
    }

    // recovery_valid_until is NOT NULL with no default — set a past timestamp (PLAN.md section 4).
    Database::query(
        'INSERT INTO users (user_name, password, recovery_valid_until) VALUES (:name, :password, :past)',
        [':name' => $userName, ':password' => Passwords::hash($password), ':past' => '2000-01-01 00:00:00']
    );
    $userId = (int) Database::connection()->lastInsertId();

    $mail = Config::get('mail');
    if (is_array($mail) && !empty($mail['reply_to']))
    {
        @mail($mail['reply_to'], 'Familjens Önskelista - ny användare', "Ny användare skapad: $userName",
            'From: ' . ($mail['from'] ?? ''));
    }

    $token = Sessions::create($userId, false);
    Http::json([
        'token' => $token,
        'user' => ['id' => $userId, 'userName' => $userName, 'isSuper' => false],
    ], 201);
}

function auth_recover(): void
{
    $body = Http::body();
    $userId = $body['userId'] ?? null;
    if ($userId === null)
    {
        Http::error(400, 'userId is required.');
    }

    $user = Database::query('SELECT user_id, email FROM users WHERE user_id = :id', [':id' => $userId])->fetch();
    // Don't reveal whether the account exists; always return 204.
    if ($user !== false)
    {
        $code = bin2hex(random_bytes(16)); // 32 hex chars
        Database::query(
            'UPDATE users SET recovery_code = :code, recovery_valid_until = DATE_ADD(NOW(), INTERVAL 600 SECOND) WHERE user_id = :id',
            [':code' => $code, ':id' => $user->user_id]
        );
        if ($user->email !== '')
        {
            $mail = Config::get('mail');
            $text = "För att ändra ditt lösenord, använd koden nedan (giltig i 10 minuter):\r\n\r\n$code";
            @mail($user->email, 'Familjens Önskelista - nytt lösenord', $text,
                'From: ' . (is_array($mail) ? ($mail['from'] ?? '') : ''));
        }
    }
    Http::noContent();
}

function auth_reset(): void
{
    $body = Http::body();
    $userId = $body['userId'] ?? null;
    $code = (string) ($body['code'] ?? '');
    $password = (string) ($body['password'] ?? '');
    if ($userId === null || $code === '' || $password === '')
    {
        Http::error(400, 'userId, code and password are required.');
    }

    $user = Database::query(
        'SELECT user_id FROM users WHERE user_id = :id AND recovery_code = :code'
            . " AND recovery_code != '' AND recovery_valid_until > NOW()",
        [':id' => $userId, ':code' => $code]
    )->fetch();
    if ($user === false)
    {
        Http::error(400, 'Invalid or expired recovery code.');
    }

    // Update the password and invalidate the code.
    Database::query(
        "UPDATE users SET password = :password, recovery_code = '', recovery_valid_until = :past WHERE user_id = :id",
        [':password' => Passwords::hash($password), ':past' => '2000-01-01 00:00:00', ':id' => $user->user_id]
    );
    Http::noContent();
}
