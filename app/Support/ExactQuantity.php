<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Exact Ingredient quantities. A quantity is held in PHP as an integer count of ten-thousandths of the base unit
 * (29.5 pc = 295000), matching the numeric(18, 4) database columns, so recipe and stock math never touches floats.
 */
class ExactQuantity
{
    public const SCALE = 4;

    public const FACTOR = 10_000;

    /** Largest quantity a person may enter (one million base units). */
    public const MAX_INPUT = 1_000_000 * self::FACTOR;

    /** Largest persisted magnitude, far below numeric(18, 4) and PHP_INT_MAX. */
    public const MAX_STORED = 99_999_999_999_999 * self::FACTOR;

    public const INPUT_PATTERN = '/\A[0-9]{1,7}(?:\.[0-9]{1,4})?\z/';

    /** Parses a validated, non-negative input such as "29.5" or "0.125". */
    public static function fromInput(string $value, string $field = 'quantity'): int
    {
        $value = trim($value);
        if (preg_match(self::INPUT_PATTERN, $value) !== 1) {
            throw ValidationException::withMessages([$field => 'Enter a quantity with no more than four decimal places.']);
        }
        $scaled = self::parseDecimal($value);
        if ($scaled > self::MAX_INPUT) {
            throw ValidationException::withMessages([$field => 'The quantity is too large.']);
        }

        return $scaled;
    }

    /** Reads a persisted numeric value: PostgreSQL returns "29.5000", SQLite may return 29.5 or 30. */
    public static function parse(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }
        if (is_int($value)) {
            return $value * self::FACTOR;
        }
        if (is_float($value)) {
            return (int) round($value * self::FACTOR);
        }
        $value = trim((string) $value);
        if (preg_match('/\A-?[0-9]+(?:\.[0-9]+)?\z/', $value) !== 1) {
            throw new \InvalidArgumentException('Invalid stored quantity.');
        }

        return self::parseDecimal($value);
    }

    public static function parseNullable(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : self::parse($value);
    }

    /** Persistable decimal string with four places, for example "-0.2500". */
    public static function decimal(int $scaled): string
    {
        self::guard($scaled);
        $magnitude = abs($scaled);

        return ($scaled < 0 ? '-' : '').intdiv($magnitude, self::FACTOR).'.'.str_pad((string) ($magnitude % self::FACTOR), self::SCALE, '0', STR_PAD_LEFT);
    }

    /** Human decimal without trailing zeros, for example "29.5", "-0.25" or "30". */
    public static function display(int $scaled): string
    {
        $decimal = self::decimal($scaled);

        return rtrim(rtrim($decimal, '0'), '.');
    }

    public static function add(int $left, int $right): int
    {
        $sum = $left + $right;
        self::guard($sum);

        return $sum;
    }

    /** A per-unit quantity times a whole number of Product units. */
    public static function times(int $scaled, int $units): int
    {
        if ($units !== 0 && abs($scaled) > intdiv(self::MAX_STORED, abs($units))) {
            throw ValidationException::withMessages(['quantity' => 'The quantity is too large.']);
        }

        return $scaled * $units;
    }

    /** A decimal count of purchase units times the base-unit size of one purchase unit, exactly. */
    public static function multiply(int $scaledLeft, int $scaledRight): int
    {
        $product = self::multiplyExact($scaledLeft, $scaledRight);

        return self::divideRounded($product, self::FACTOR);
    }

    /** Whole purchase units needed to cover a positive base-unit shortage (always rounds up). */
    public static function purchaseUnitsFor(int $shortage, int $purchaseUnitSize): int
    {
        if ($shortage <= 0) {
            return 0;
        }
        if ($purchaseUnitSize <= 0) {
            throw new \InvalidArgumentException('A purchase unit must hold a positive quantity.');
        }

        return intdiv($shortage + $purchaseUnitSize - 1, $purchaseUnitSize);
    }

    /**
     * Estimated centavos for a base-unit quantity at a purchase-unit cost: quantity × cost ÷ purchase-unit size,
     * rounded half away from zero. The sign follows the quantity.
     */
    public static function costCents(int $scaledQuantity, int $costCents, int $scaledPurchaseUnitSize): int
    {
        if ($scaledPurchaseUnitSize <= 0) {
            throw new \InvalidArgumentException('A purchase unit must hold a positive quantity.');
        }

        return self::divideRounded(self::multiplyExact($scaledQuantity, $costCents), $scaledPurchaseUnitSize);
    }

    /** Centavos for a decimal quantity at a per-unit price, rounded half away from zero. */
    public static function lineCents(int $scaledQuantity, int $unitCents): int
    {
        return self::divideRounded(self::multiplyExact($scaledQuantity, $unitCents), self::FACTOR);
    }

    private static function multiplyExact(int $left, int $right): int
    {
        if ($left !== 0 && abs($right) > intdiv(PHP_INT_MAX, abs($left))) {
            throw ValidationException::withMessages(['quantity' => 'The quantity or cost is too large.']);
        }

        return $left * $right;
    }

    private static function divideRounded(int $numerator, int $denominator): int
    {
        $quotient = intdiv(abs($numerator), $denominator);
        if ((abs($numerator) % $denominator) * 2 >= $denominator) {
            $quotient++;
        }

        return $numerator < 0 ? -$quotient : $quotient;
    }

    private static function parseDecimal(string $value): int
    {
        $negative = str_starts_with($value, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        if (strlen(rtrim($fraction, '0')) > self::SCALE) {
            throw new \InvalidArgumentException('A quantity supports at most four decimal places.');
        }
        $fraction = substr(str_pad($fraction, self::SCALE, '0'), 0, self::SCALE);
        if (strlen(ltrim($whole, '0')) > 15) {
            throw new \InvalidArgumentException('The quantity is too large.');
        }
        $scaled = ((int) $whole * self::FACTOR) + (int) $fraction;

        return $negative ? -$scaled : $scaled;
    }

    private static function guard(int $scaled): void
    {
        if (abs($scaled) > self::MAX_STORED) {
            throw ValidationException::withMessages(['quantity' => 'The quantity is too large.']);
        }
    }
}
