<?php

namespace App\Enums;

/**
 * Why a Store Session Stock Correction changed the authoritative Product stock to match the physical count.
 *
 * Complimentary and Staff meal are historical only (Phase 20): a free Product is always recorded as a Giveaway, never
 * as a Stock Correction, so new corrections cannot use them.
 */
enum StoreInventoryAdjustmentReason: string
{
    case PhysicalCount = 'physical_count';
    case FoundStock = 'found_stock';
    case MissingStock = 'missing_stock';
    case Wastage = 'wastage';
    case Damaged = 'damaged';
    case Other = 'other';
    case Complimentary = 'complimentary';
    case StaffMeal = 'staff_meal';

    public function label(): string
    {
        return match ($this) {
            self::PhysicalCount => 'Physical count / discrepancy',
            self::FoundStock => 'Found stock',
            self::MissingStock => 'Missing stock',
            self::Wastage => 'Wastage',
            self::Damaged => 'Damaged',
            self::Other => 'Other',
            self::Complimentary => 'Complimentary / Free item',
            self::StaffMeal => 'Staff meal',
        };
    }

    /**
     * Reasons a new Stock Correction may use.
     *
     * @return list<self>
     */
    public static function correctionReasons(): array
    {
        return [self::PhysicalCount, self::FoundStock, self::MissingStock, self::Wastage, self::Damaged, self::Other];
    }

    /**
     * The directions this reason can explain: found stock only adds, loss reasons only remove.
     *
     * @return list<StockCorrectionDirection>
     */
    public function directions(): array
    {
        return match ($this) {
            self::PhysicalCount, self::Other => [StockCorrectionDirection::Decrease, StockCorrectionDirection::Increase],
            self::FoundStock => [StockCorrectionDirection::Increase],
            self::MissingStock, self::Wastage, self::Damaged, self::Complimentary, self::StaffMeal => [StockCorrectionDirection::Decrease],
        };
    }
}
