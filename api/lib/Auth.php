<?php

namespace WishNet;

require_once __DIR__ . '/Http.php';
require_once __DIR__ . '/Sessions.php';

// Resolves the incoming bearer token to a session (user_id + is_super). This is the trust anchor:
// the user id always comes from here, never from a client-supplied field.
final class Auth
{
    public static function current(): ?object
    {
        $token = self::bearerToken();
        return $token === null ? null : Sessions::resolve($token);
    }

    public static function requireSession(): object
    {
        $session = self::current();
        if ($session === null)
        {
            Http::error(401, 'Authentication required.');
        }
        return $session;
    }

    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($header === '' && function_exists('apache_request_headers'))
        {
            foreach (apache_request_headers() as $name => $value)
            {
                if (strcasecmp($name, 'Authorization') === 0)
                {
                    $header = $value;
                    break;
                }
            }
        }
        return preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1 ? trim($matches[1]) : null;
    }
}
