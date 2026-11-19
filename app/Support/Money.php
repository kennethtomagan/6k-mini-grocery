<?php

namespace App\Support;

/**
 * Single conversion point between integer centavos, MySQL decimals and display strings.
 */
final class Money
{
    /**
     * Convert a decimal amount (number or numeric string, commas allowed) to integer centavos.
     * Empty, non-numeric, non-finite or out-of-range (absolute value of 1e13 or more) input yields 0
     * so raw, unvalidated keystrokes never throw. Amounts round half away from zero at two decimals.
     */
    public static function toCents(int|float|string|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $normalized = str_replace(',', '', (string) $amount);

        if (! is_numeric($normalized)) {
            return 0;
        }

        $value = (float) $normalized;

        if (! is_finite($value) || abs($value) >= 1e13) {
            return 0;
        }

        return (int) round(round($value, 2) * 100);
    }

    /**
     * Convert integer centavos to a plain decimal string suitable for DECIMAL columns.
     */
    public static function fromCents(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /**
     * Format integer centavos as a peso display string.
     */
    public static function format(int $cents): string
    {
        return ($cents < 0 ? '-' : '').'₱'.number_format(abs($cents) / 100, 2);
    }
}
