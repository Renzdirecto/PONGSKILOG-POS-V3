<?php

namespace App\Support;

use App\Enums\ReplenishmentRule;
use App\Models\Ingredient;

/**
 * The one Pamamalengke recommendation authority. Given an Ingredient's canonical Branch stock it decides whether to buy
 * and how many whole purchase units, from the replenishment rule:
 *
 * - top_up: any stock below target buys ceil((target − current) ÷ purchase-unit size), at least one unit.
 * - reorder: nothing until current ≤ reorder point, then ceil((target − current) ÷ size), at least one unit.
 * - none: never suggested automatically (the Owner may still add it by hand).
 *
 * Only the purchase-unit count rounds; stock and base quantities stay exact. Negative stock simply widens the gap.
 *
 * @phpstan-type Recommendation array{kind: 'setup'|'manual'|'buy'|'hold'|'ok', units: int, base_quantity: int, estimate_cents: int|null, reason: string}
 */
class ReplenishmentAdvisor
{
    /** @return Recommendation */
    public function recommend(Ingredient $ingredient, int $current): array
    {
        $unit = $ingredient->base_unit;
        $target = ExactQuantity::parse($ingredient->target_quantity);
        $size = ExactQuantity::parseNullable($ingredient->purchase_unit_size);
        $purchaseUnit = trim((string) $ingredient->purchase_unit_name);
        if ($size === null || $size <= 0 || $purchaseUnit === '') {
            return $this->none('setup', 'No purchase unit yet. Add one so Pamamalengke can suggest it.');
        }
        if ($ingredient->replenishment_rule === ReplenishmentRule::None) {
            return $this->none('manual', 'Automatic suggestions are off. Add it by hand when needed.');
        }
        $shortage = $target - $current;
        if ($ingredient->replenishment_rule === ReplenishmentRule::TopUp) {
            if ($current < $target) {
                return $this->buy($ingredient, $shortage, $size, self::quantity($shortage, $unit).' below target. Rounded up to whole '.self::plural($purchaseUnit, 2).'.');
            }

            return $this->none('ok', $current > $target ? 'Above target.' : 'At target.');
        }

        $reorderPoint = ExactQuantity::parse($ingredient->reorder_point ?? '0');
        if ($current <= $reorderPoint) {
            $why = $current <= 0 ? 'Out of stock.' : 'At '.self::quantity($current, $unit).', the reorder point of '.self::quantity($reorderPoint, $unit).' is reached.';

            return $this->buy($ingredient, $shortage, $size, $why.' Enough to get back to target.');
        }
        if ($current < $target) {
            return $this->none('hold', 'Below target, but above the reorder point of '.self::quantity($reorderPoint, $unit).'. No purchase yet.');
        }

        return $this->none('ok', $current > $target ? 'Above target.' : 'At target.');
    }

    /** "29.5 pcs", "970 ml" or "1 pack". */
    public static function quantity(int $scaled, string $unit): string
    {
        return ExactQuantity::display($scaled).' '.self::plural($unit, $scaled === ExactQuantity::FACTOR ? 1 : 2);
    }

    public static function plural(string $unit, int|float $count): string
    {
        if (in_array($unit, ['ml', 'L', 'g', 'kg'], true) || ($count <= 1 && $count > 0)) {
            return $unit;
        }
        if ($unit === 'pc') {
            return 'pcs';
        }

        return preg_match('/(ch|sh|x|s)$/', $unit) === 1 ? $unit.'es' : $unit.'s';
    }

    /** @return Recommendation */
    private function buy(Ingredient $ingredient, int $shortage, int $size, string $reason): array
    {
        $units = max(1, ExactQuantity::purchaseUnitsFor($shortage, $size));
        $cost = $ingredient->purchase_unit_cost === null ? null : ExactMoney::cents((string) $ingredient->purchase_unit_cost);

        return [
            'kind' => 'buy',
            'units' => $units,
            'base_quantity' => ExactQuantity::times($size, $units),
            'estimate_cents' => $cost === null ? null : ExactMoney::multiply($cost, $units),
            'reason' => $reason,
        ];
    }

    /**
     * @param  'setup'|'manual'|'hold'|'ok'  $kind
     * @return Recommendation
     */
    private function none(string $kind, string $reason): array
    {
        return ['kind' => $kind, 'units' => 0, 'base_quantity' => 0, 'estimate_cents' => 0, 'reason' => $reason];
    }
}
