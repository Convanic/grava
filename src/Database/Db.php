<?php
declare(strict_types=1);

namespace App\Database;

use App\Config\Config;
use PDO;
use PDOException;
use RuntimeException;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $cfg = Config::instance();
        $socket = (string)$cfg->get('DB_SOCKET', '');
        $dbName = $cfg->requireValue('DB_NAME');

        if ($socket !== '') {
            $dsn = "mysql:unix_socket={$socket};dbname={$dbName};charset=utf8mb4";
        } else {
            $host = $cfg->requireValue('DB_HOST');
            $port = $cfg->int('DB_PORT', 3306);
            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
        }

        try {
            self::$pdo = new PDO(
                $dsn,
                (string)$cfg->get('DB_USER', ''),
                (string)$cfg->get('DB_PASS', ''),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', NAMES utf8mb4",
                ]
            );
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return self::$pdo;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }
}
