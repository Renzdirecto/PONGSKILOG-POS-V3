<?php

namespace App\Enums;

/**
 * Who a receipt is built for (Phase 20). There is one receipt document; the audience only removes what that viewer
 * may not see — the server leaves it out, the renderer never hides private data itself.
 */
enum ReceiptAudience: string
{
    /** POS print / Transaction History reprint: staff-only extras (ids, statuses, balance, invoice proofs). */
    case Staff = 'staff';

    /** The Customer QR ordering session's own receipt. */
    case Customer = 'customer';

    /** The signed receipt link the cashier shares (Show QR). */
    case SharedLink = 'shared_link';

    /** The Take Out pickup page: never the customer's name, table or notes. */
    case Pickup = 'pickup';

    /** Customer name, table and item notes are the customer's own; the pickup link may be seen by others. */
    public function showsCustomerDetails(): bool
    {
        return $this !== self::Pickup;
    }
}
