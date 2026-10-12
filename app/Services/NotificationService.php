<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\NotificationRepository;

final class NotificationService
{
    public function __construct(private readonly NotificationRepository $repo = new NotificationRepository()) {}

    public function notifyClient(int $userId, string $title, string $message, string $type = 'system', ?string $link = null): void
    {
        $this->repo->create('client', $userId, $title, $message, $type, $link);
    }

    public function notifyAdmin(int $adminId, string $title, string $message, string $type = 'system', ?string $link = null): void
    {
        $this->repo->create('admin', $adminId, $title, $message, $type, $link);
    }

    public function notifyAllAdmins(string $title, string $message, string $type = 'system', ?string $link = null): void
    {
        $ids = Database::selectAll('SELECT id FROM admins WHERE status = "active"');
        foreach ($ids as $row) {
            $this->repo->create('admin', (int) $row['id'], $title, $message, $type, $link);
        }
    }

    public function notifyAllSuperAdmins(string $title, string $message, string $type = 'system', ?string $link = null): void
    {
        $ids = Database::selectAll('SELECT id FROM super_admins WHERE status = "active"');
        foreach ($ids as $row) {
            $this->repo->create('super_admin', (int) $row['id'], $title, $message, $type, $link);
        }
    }

    public function paginateFor(string $userType, int $userId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset  = ($page - 1) * $perPage;

        $rows = Database::selectAll(
            "SELECT * FROM notifications
             WHERE user_type = :t AND user_id = :u
             ORDER BY is_read ASC, created_at DESC, id DESC
             LIMIT $perPage OFFSET $offset",
            [':t' => $userType, ':u' => $userId]
        );
        $total = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM notifications WHERE user_type = :t AND user_id = :u',
            [':t' => $userType, ':u' => $userId]
        )['c'];
        $unread = (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM notifications WHERE user_type = :t AND user_id = :u AND is_read = 0',
            [':t' => $userType, ':u' => $userId]
        )['c'];

        return [
            'rows'     => $rows,
            'total'    => $total,
            'unread'   => $unread,
            'page'     => $page,
            'per_page' => $perPage,
            'pages'    => (int) ceil($total / $perPage),
        ];
    }

    public function unreadCount(string $userType, int $userId): int
    {
        return (int) Database::selectOne(
            'SELECT COUNT(*) AS c FROM notifications WHERE user_type = :t AND user_id = :u AND is_read = 0',
            [':t' => $userType, ':u' => $userId]
        )['c'];
    }

    public function markRead(string $userType, int $userId, int $notificationId): void
    {
        Database::execute(
            'UPDATE notifications SET is_read = 1, read_at = UTC_TIMESTAMP()
             WHERE id = :id AND user_type = :t AND user_id = :u',
            [':id' => $notificationId, ':t' => $userType, ':u' => $userId]
        );
    }

    public function markAllRead(string $userType, int $userId): void
    {
        Database::execute(
            'UPDATE notifications SET is_read = 1, read_at = UTC_TIMESTAMP()
             WHERE user_type = :t AND user_id = :u AND is_read = 0',
            [':t' => $userType, ':u' => $userId]
        );
    }
}