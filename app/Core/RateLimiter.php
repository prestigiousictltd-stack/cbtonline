<?php
declare(strict_types=1);

namespace App\Core;

final class RateLimiter
{
    /**
     * @param string $userType  client|admin|super_admin
     * @param string $identifier email or username attempted
     * @param string $ip
     */
    public static function tooMany(string $userType, string $identifier, string $ip): bool
    {
        $since = date('Y-m-d H:i:s', time() - LOGIN_LOCKOUT_MINUTES * 60);

        $byId = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM login_attempts
             WHERE user_type = :t AND identifier = :i AND successful = 0 AND created_at >= :s',
            [':t' => $userType, ':i' => $identifier, ':s' => $since]
        )['c'];

        if ($byId >= LOGIN_MAX_ATTEMPTS) {
            return true;
        }

        $byIp = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM login_attempts
             WHERE user_type = :t AND ip_address = :ip AND successful = 0 AND created_at >= :s',
            [':t' => $userType, ':ip' => $ip, ':s' => $since]
        )['c'];

        return $byIp >= (LOGIN_MAX_ATTEMPTS * 3);
    }

    public static function record(string $userType, string $identifier, string $ip, bool $successful): void
    {
        Database::execute(
            'INSERT INTO login_attempts (user_type, identifier, ip_address, user_agent, successful)
             VALUES (:t, :i, :ip, :ua, :s)',
            [
                ':t'  => $userType,
                ':i'  => $identifier,
                ':ip' => $ip,
                ':ua' => user_agent(),
                ':s'  => $successful ? 1 : 0,
            ]
        );
    }

    public static function clear(string $userType, string $identifier): void
    {
        Database::execute(
            'UPDATE login_attempts SET successful = 1
             WHERE user_type = :t AND identifier = :i AND successful = 0',
            [':t' => $userType, ':i' => $identifier]
        );
    }
}