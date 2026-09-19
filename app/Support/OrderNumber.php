<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Order;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

class OrderNumber
{
    /** @return array{order_number: string, reference_number: string} */
    public function allocate(Branch $branch, DateTimeInterface $createdAt): array
    {
        return DB::transaction(function () use ($branch, $createdAt): array {
            $branch = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            $timestamp = now();

            DB::table('order_number_counters')->insertOrIgnore([
                'branch_id' => $branch->id,
                'next_number' => 1001,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);

            $counter = DB::table('order_number_counters')
                ->where('branch_id', $branch->id)
                ->lockForUpdate()
                ->first();

            if ($counter === null) {
                throw new \LogicException('Order number counter was not created.');
            }

            $number = (int) $counter->next_number;
            while (Order::query()->where('branch_id', $branch->id)->where('order_number', (string) $number)->exists()) {
                $number++;
            }

            DB::table('order_number_counters')->where('branch_id', $branch->id)->update([
                'next_number' => $number + 1,
                'updated_at' => $timestamp,
            ]);

            $date = CarbonImmutable::instance($createdAt)->setTimezone('Asia/Manila')->format('ymd');

            return [
                'order_number' => (string) $number,
                'reference_number' => $branch->code.'-'.$date.'-'.$number,
            ];
        });
    }
}
