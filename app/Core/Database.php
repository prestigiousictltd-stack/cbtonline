<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use Throwable;

final class Database
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST, DB_PORT, DB_NAME
        );

        try {
            self::$pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'",
            ]);
        } catch (PDOException $e) {
            Logger::error('DB connection failed', ['error' => $e->getMessage()]);
            http_response_code(500);
            exit(APP_DEBUG ? 'DB error: ' . $e->getMessage() : 'Service temporarily unavailable.');
        }

        return self::$pdo;
    }

    public static function begin(): void
    {
        if (!self::pdo()->inTransaction()) {
            self::pdo()->beginTransaction();
        }
    }

    public static function commit(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->commit();
        }
    }

    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }

    /**
     * Run $fn inside a transaction. Commits on success, rolls back on any Throwable.
     * Returns whatever $fn returns.
     */
    public static function transaction(callable $fn): mixed
    {
        self::begin();
        try {
            $result = $fn(self::pdo());
            self::commit();
            return $result;
        } catch (Throwable $e) {
            self::rollback();
            throw $e;
        }
    }

    /** Small helper: SELECT returning a single row or null. */
    public static function selectOne(string $sql, array $bind = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bind);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** SELECT returning all rows. */
    public static function selectAll(string $sql, array $bind = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bind);
        return $stmt->fetchAll();
    }

    /** Execute INSERT/UPDATE/DELETE; returns affected rows. */
    public static function execute(string $sql, array $bind = []): int
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($bind);
        return $stmt->rowCount();
    }

    public static function lastInsertId(): int
    {
        return (int) self::pdo()->lastInsertId();
    }
}