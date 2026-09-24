<?php

namespace App\Enums;

enum InventoryMovementType: string
{
    case Sale = 'sale';
    case PayLaterCommit = 'pay_later_commit';
    case OrderEditDelta = 'order_edit_delta';
    case VoidRestore = 'void_restore';
    case ManualAdjustment = 'manual_adjustment';
    case StorePurchaseRestock = 'store_purchase_restock';
    case TransferOut = 'transfer_out';
    case TransferIn = 'transfer_in';
    case Giveaway = 'giveaway';
    case GiveawayReversal = 'giveaway_reversal';

    public function label(): string
    {
        return match ($this) {
            self::Sale => 'Sale',
            self::PayLaterCommit => 'Pay Later',
            self::OrderEditDelta => 'Order Edit',
            self::VoidRestore => 'Void Restore',
            self::ManualAdjustment => 'Manual Adjustment',
            self::StorePurchaseRestock => 'Store Purchase Restock',
            self::TransferOut => 'Transfer Out',
            self::TransferIn => 'Transfer In',
            self::Giveaway => 'Giveaway',
            self::GiveawayReversal => 'Giveaway Reversed',
        };
    }
}
