<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class TrackingSequenceRepository
{
    /**
     * Atomically increment and return the next sequence number for $year.
     * MUST be called inside a transaction — uses SELECT ... FOR UPDATE.
     */
    public function nextNumber(int $year): int
    {
        $row = Database::selectOne(
            'SELECT year, last_number FROM tracking_sequences WHERE year = :y FOR UPDATE',
            [':y' => $year]
        );

        if (!$row) {
            // First ever submission for this year
            try {
                Database::execute(
                    'INSERT INTO tracking_sequences (year, last_number) VALUES (:y, 1)',
                    [':y' => $year]
                );
                return 1;
            } catch (\PDOException $e) {
                // Race: another request inserted first — retry by re-reading under lock
                $row = Database::selectOne(
                    'SELECT year, last_number FROM tracking_sequences WHERE year = :y FOR UPDATE',
                    [':y' => $year]
                );
                if (!$row) throw $e;
            }
        }

        $next = (int) $row['last_number'] + 1;
        Database::execute(
            'UPDATE tracking_sequences SET last_number = :n WHERE year = :y',
            [':n' => $next, ':y' => $year]
        );
        return $next;
    }
}