<?php

namespace App\Support;

use App\Enums\ReceiptAudience;
use App\Models\Order;

/**
 * The staff receipt (POS print, Transaction History reprint, edit / settlement responses): the canonical
 * `ReceiptDocument` for the Staff audience, with its staff-only extras (ids, statuses, balance, invoice proofs).
 */
class PosReceipt
{
    public function __construct(private ReceiptDocument $receipts) {}

    /** @return array<string, mixed> */
    public function summary(Order $order): array
    {
        return $this->receipts->for($order, ReceiptAudience::Staff);
    }
}
