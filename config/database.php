<?php
/*
 * Database connection manager. Creates and shares a single PDO connection to the
 * database using settings from the environment file.
 */

require_once __DIR__ . '/bootstrap.php';

class Database {
    private static ?PDO $instance = null;

    public static function getConnection(): PDO {
        if (self::$instance === null) {

            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $dbname = getenv('DB_NAME') ?: 'erp_db';
            $username = getenv('DB_USER') ?: 'root';
            $password = getenv('DB_PASS') ?: '';
            $charset = 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, $username, $password, $options);

                self::$instance->exec("SET time_zone = " . self::$instance->quote(getDbTimezoneOffset()));

                self::$instance->exec("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_TRANS_TABLES')");
            } catch (PDOException $e) {
                error_log("Database connection error: " . $e->getMessage());
                http_response_code(500);
                $isProd = (getenv('APP_ENV') === 'production');
                echo json_encode([
                    'success' => false,
                    'error'   => 'Database connection failed',
                    'detail'  => $isProd 
                        ? 'An internal database error occurred. The incident has been logged.' 
                        : $e->getMessage()
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;
            }
        }

        return self::$instance;
    }
}
