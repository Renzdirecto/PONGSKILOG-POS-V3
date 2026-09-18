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
}
