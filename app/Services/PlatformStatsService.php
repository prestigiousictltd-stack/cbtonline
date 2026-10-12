<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SuperAdminStatsRepository;

final class PlatformStatsService
{
    public function __construct(
        private readonly SuperAdminStatsRepository $repo = new SuperAdminStatsRepository()
    ) {}

    /**
     * @param array{from?:?string,to?:?string} $range
     */
    public function summary(array $range = []): array
    {
        return [
            'clients'   => $this->repo->clientStats(),
            'new_clients' => $this->repo->newClientsCount($range),
            'services'  => $this->repo->serviceStats($range),
            'admins'    => $this->repo->adminStats($range),
            'financial' => $this->repo->financialStats($range),
        ];
    }

    public function revenueByService(array $range = [], int $limit = 10): array
    {
        return $this->repo->revenueByService($range, $limit);
    }

    /**
     * Convert a named range into absolute UTC datetime bounds.
     *
     * @return array{from:?string,to:?string}
     */
    public function resolveRange(string $preset, ?string $customFrom = null, ?string $customTo = null): array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        return match ($preset) {
            'today' => [
                'from' => $now->setTime(0,0,0)->format('Y-m-d H:i:s'),
                'to'   => $now->setTime(23,59,59)->format('Y-m-d H:i:s'),
            ],
            'week'  => [
                'from' => $now->modify('monday this week')->setTime(0,0,0)->format('Y-m-d H:i:s'),
                'to'   => $now->format('Y-m-d H:i:s'),
            ],
            'month' => [
                'from' => $now->modify('first day of this month')->setTime(0,0,0)->format('Y-m-d H:i:s'),
                'to'   => $now->format('Y-m-d H:i:s'),
            ],
            'year'  => [
                'from' => $now->setDate((int) $now->format('Y'), 1, 1)->setTime(0,0,0)->format('Y-m-d H:i:s'),
                'to'   => $now->format('Y-m-d H:i:s'),
            ],
            'custom' => [
                'from' => $customFrom ? ($customFrom . ' 00:00:00') : null,
                'to'   => $customTo   ? ($customTo   . ' 23:59:59') : null,
            ],
            default => ['from' => null, 'to' => null],  // 'all'
        };
    }
}