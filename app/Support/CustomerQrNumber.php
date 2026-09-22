<?php

namespace App\Support;

use App\Models\StoreSession;
use Illuminate\Support\Facades\DB;

class CustomerQrNumber
{
    public function allocate(StoreSession $session): int
    {
        DB::table('customer_qr_order_counters')->insertOrIgnore(['store_session_id' => $session->id, 'next_number' => 1]);
        $counter = DB::table('customer_qr_order_counters')->where('store_session_id', $session->id)->lockForUpdate()->first();
        if ($counter === null) {
            throw new \LogicException('QR counter was not created.');
        }
        $number = (int) $counter->next_number;
        DB::table('customer_qr_order_counters')->where('store_session_id', $session->id)->update(['next_number' => $number + 1]);

        return $number;
    }

    public static function display(?int $sequence): ?string
    {
        return $sequence === null ? null : 'QR-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }
}
