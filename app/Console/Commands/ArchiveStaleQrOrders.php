<?php

namespace App\Console\Commands;

use App\Actions\Orders\ArchiveCustomerQrOrder;
use App\Enums\CommercialStatus;
use App\Enums\OrderSource;
use App\Models\Order;
use Illuminate\Console\Command;

class ArchiveStaleQrOrders extends Command
{
    protected $signature = 'qr:archive-stale';

    protected $description = 'Archive untouched customer QR submissions after 30 minutes';

    public function handle(ArchiveCustomerQrOrder $archive): int
    {
        $count = 0;
        Order::query()->where('source', OrderSource::CustomerQr)->where('commercial_status', CommercialStatus::Submitted)
            ->whereNull('loaded_by_user_id')->where('submitted_at', '<=', now()->subMinutes(30))
            ->chunkById(100, function ($orders) use ($archive, &$count): void {
                foreach ($orders as $order) {
                    $count += (int) $archive->execute($order);
                }
            });
        $this->info("Archived {$count} unclaimed QR orders.");

        return self::SUCCESS;
    }
}
