<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;
use App\Repositories\TrackingSequenceRepository;

final class TrackingIdService
{
    public function __construct(
        private readonly TrackingSequenceRepository $sequences = new TrackingSequenceRepository()
    ) {}

    /**
     * Generate the next unique tracking ID for this calendar year.
     * MUST be called inside a transaction.
     *
     * Format: PREFIX-YYYY-NNNNNN   e.g. ESC-2026-000123
     */
    public function generate(?string $prefix = null): string
    {
        $year   = (int) date('Y');
        $prefix = strtoupper($prefix ?? (string) Settings::get('tracking_id_prefix', 'ESC'));
        $n      = $this->sequences->nextNumber($year);
        return sprintf('%s-%d-%06d', $prefix, $year, $n);
    }
}