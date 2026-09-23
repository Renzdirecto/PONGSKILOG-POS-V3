<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class ExactMoney
{
    public const MAX_CENTS = 99999999999999;

    public static function cents(string $amount): int
    {
        if (! preg_match('/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/', $amount)) {
            throw ValidationException::withMessages(['items' => 'The order contains an invalid price.']);
        }
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }

    /** Parses a persisted signed decimal such as a reconciliation value "-250.00". */
    public static function signedCents(string $amount): int
    {
        return str_starts_with($amount, '-') ? -self::cents(substr($amount, 1)) : self::cents($amount);
    }

    public static function decimal(int $cents): string
    {
        self::guard($cents);

        return intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** Reconciliation values such as variances may be negative; the magnitude keeps the same bound. */
    public static function signedDecimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').self::decimal(abs($cents));
    }

    public static function display(int $cents): string
    {
        [$whole, $fraction] = explode('.', self::decimal(abs($cents)));

        return ($cents < 0 ? '-' : '').'₱'.number_format((int) $whole).'.'.$fraction;
    }

    public static function add(int $left, int $right): int
    {
        self::guard($left);
        self::guard($right);
        self::guard($left + $right);

        return $left + $right;
    }

    public static function multiply(int $cents, int $quantity): int
    {
        self::guard($cents);
        if ($quantity < 1 || $cents > intdiv(self::MAX_CENTS, $quantity)) {
            throw ValidationException::withMessages(['items' => 'The order total is too large. Reduce the quantity.']);
        }

        return $cents * $quantity;
    }

    private static function guard(int $cents): void
    {
        if ($cents < 0 || $cents > self::MAX_CENTS) {
            throw ValidationException::withMessages(['items' => 'The order total exceeds the supported amount.']);
        }
    }
}
