<?php
declare(strict_types=1);

/** Currency formatting. Money comes in as string|int|float DECIMAL. */
function money(string|int|float $amount, string $symbol = '₦'): string
{
    return $symbol . number_format((float) $amount, 2);
}

/** Human-readable duration from minutes. */
function human_duration(int $minutes): string
{
    if ($minutes < 60) {
        return $minutes . ' min';
    }
    if ($minutes < 1440) {
        $h = intdiv($minutes, 60);
        $m = $minutes % 60;
        return $m ? "{$h}h {$m}m" : "{$h} hours";
    }
    $d = intdiv($minutes, 1440);
    $h = intdiv($minutes % 1440, 60);
    return $h ? "{$d}d {$h}h" : "{$d} days";
}

/** Seconds -> HH:MM:SS. Used by the task timer. */
function human_countdown(int $seconds): string
{
    if ($seconds < 0) $seconds = 0;
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    $s = $seconds % 60;
    return sprintf('%02d:%02d:%02d', $h, $m, $s);
}

/** Friendly datetime in the configured timezone. */
function fmt_dt(?string $mysqlDatetime, string $format = 'd M Y, H:i'): string
{
    if (!$mysqlDatetime) return '—';
    try {
        $dt = new DateTimeImmutable($mysqlDatetime, new DateTimeZone('UTC'));
        $dt = $dt->setTimezone(new DateTimeZone(APP_TIMEZONE));
        return $dt->format($format);
    } catch (Throwable) {
        return '—';
    }
}

/** Turn a snake_case status into a Bootstrap badge class. */
function status_badge(string $status): string
{
    return match ($status) {
        'active', 'completed', 'successful', 'approved', 'paid' => 'success',
        'pending', 'processing', 'awaiting_verification'        => 'warning',
        'inactive', 'cancelled'                                 => 'secondary',
        'suspended', 'failed', 'rejected', 'error_detected'     => 'danger',
        default                                                 => 'secondary',
    };
}