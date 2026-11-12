<?php
/**
 * GamitShare — Database Configuration
 * config/database.php
 *
 * Uses PDO so every controller can get a connection
 * the same way: require_once __DIR__ . '/../config/database.php';
 * then call db_connect().
 */

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'gamitshare');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
define('DB_CHARSET', 'utf8mb4');

/**
 * Returns a singleton PDO connection.
 *
 * @return PDO
 * @throws RuntimeException if the connection fails.
 */
function db_connect(): PDO
{
    static $pdo = null;

    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=%s',
        DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Automatically attach TiDB Cloud SSL CA certificate when connecting remotely
    if (DB_HOST !== 'localhost' && DB_HOST !== '127.0.0.1') {
        $caPath = __DIR__ . '/isrgrootx1.pem';
        if (file_exists($caPath)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }
    }

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        // Never expose the raw PDO error to the browser in production.
        error_log('GamitShare DB connection failed: ' . $e->getMessage());
        throw new RuntimeException('Database connection could not be established.');
    }

    return $pdo;
}