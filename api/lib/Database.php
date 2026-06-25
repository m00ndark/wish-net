<?php

namespace WishNet;

use PDO;
use PDOException;
use PDOStatement;

require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Http.php';

// Lazily creates a single shared PDO connection from the config.php "db" settings.
final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null)
        {
            $db = Config::get('db');
            try
            {
                self::$connection = new PDO($db['dsn'], $db['username'], $db['password']);
                self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
            }
            catch (PDOException $ex)
            {
                Http::error(500, 'Database connection failed.');
            }
        }
        return self::$connection;
    }

    // Prepare + execute a parameterized statement and return it (for fetch/fetchAll/rowCount).
    public static function query(string $sql, array $params = []): PDOStatement
    {
        $statement = self::connection()->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
}
