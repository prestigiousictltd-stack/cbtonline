<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AuditService
{
    public function log(
        string  $action,
        ?string $entityType = null,
        ?int    $entityId   = null,
        ?string $description = null,
        array   $oldValues = [],
        array   $newValues = [],
        string  $userType = 'system',
        ?int    $userId = null,
        ?string $ip = null,
        ?string $ua = null
    ): void {
        try {
            Database::execute(
                'INSERT INTO audit_logs
                    (user_type, user_id, action, entity_type, entity_id, description,
                     old_values, new_values, ip_address, user_agent)
                 VALUES
                    (:ut, :uid, :a, :et, :eid, :d, :ov, :nv, :ip, :ua)',
                [
                    ':ut'  => $userType,
                    ':uid' => $userId,
                    ':a'   => $action,
                    ':et'  => $entityType,
                    ':eid' => $entityId,
                    ':d'   => $description,
                    ':ov'  => $oldValues ? json_encode($oldValues) : null,
                    ':nv'  => $newValues ? json_encode($newValues) : null,
                    ':ip'  => $ip ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                    ':ua'  => $ua ?? substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                ]
            );
        } catch (\Throwable $e) {
            \App\Core\Logger::warning('Audit log write failed', ['error' => $e->getMessage(), 'action' => $action]);
        }
    }
}