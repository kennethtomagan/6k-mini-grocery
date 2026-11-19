<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Turns the Sales Reports page filters into a whole-day date range in the app timezone.
 *
 * Filter state comes from the URL, the session and Livewire requests and is never validated, so anything that
 * is not a real Y-m-d date is ignored and replaced by the default (the last 30 days, today included).
 */
final class ReportRange
{
    /**
     * The longest range a report covers, in days: two years, so monthly figures can be compared year on year.
     * A longer range keeps its end date and is shortened from the start.
     */
    public const MAX_DAYS = 731;

    /**
     * @param  array<string, mixed>|null  $filters  Filament page filters: ['from' => 'Y-m-d', 'to' => 'Y-m-d']
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} start of first day, end of last day
     */
    public static function fromFilters(?array $filters): array
    {
        $today = CarbonImmutable::now();

        $from = (self::parseDate($filters['from'] ?? null) ?? $today->subDays(29))->startOfDay();
        $to = (self::parseDate($filters['to'] ?? null) ?? $today)->startOfDay();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ((int) $from->diffInDays($to) >= self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        return [$from->startOfDay(), $to->endOfDay()->startOfSecond()];
    }

    /**
     * Accepts only a real calendar date written exactly as Y-m-d; anything else yields null.
     */
    public static function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        if (! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null;
    }
}
