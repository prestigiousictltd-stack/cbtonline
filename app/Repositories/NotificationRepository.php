<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class NotificationRepository
{
    public function create(
        string $userType,
        int $userId,
        string $title,
        string $message,
        string $type = 'system',
        ?string $link = null
    ): int {
        Database::execute(
            'INSERT INTO notifications (user_type, user_id, title, message, type, link)
             VALUES (:t, :u, :ti, :m, :ty, :l)',
            [
                ':t'  => $userType,
                ':u'  => $userId,
                ':ti' => $title,
                ':m'  => $message,
                ':ty' => $type,
                ':l'  => $link,
            ]
        );
        return Database::lastInsertId();
    }
}