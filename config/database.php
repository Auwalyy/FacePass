<?php
/**
 * PDO database connection (singleton).
 *
 * Usage:  $pdo = db();
 */

defined('APP_INIT') or exit('No direct access.');

function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
        ]);

        // Keep MySQL's clock in line with PHP's timezone.
        $offset = (new DateTime('now', new DateTimeZone(APP_TIMEZONE)))->format('P');
        $stmt = $pdo->prepare('SET time_zone = ?');
        $stmt->execute([$offset]);
    }

    return $pdo;
}
