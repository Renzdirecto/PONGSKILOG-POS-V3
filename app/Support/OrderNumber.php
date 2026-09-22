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

            $date = CarbonImmutable::instance($createdAt)->setTimezone('Asia/Manila')->format('Y-m-d');

            DB::table('order_reference_counters')->insertOrIgnore([
                'branch_id' => $branch->id, 'business_date' => $date, 'next_number' => 1,
            ]);
            $daily = DB::table('order_reference_counters')->where('branch_id', $branch->id)->where('business_date', $date);
            $dailyCounter = (clone $daily)->lockForUpdate()->first();
            if ($dailyCounter === null) {
                throw new \LogicException('Reference counter was not created.');
            }
            $referenceSequence = (int) $dailyCounter->next_number;
            $prefix = $branch->code.'-'.CarbonImmutable::instance($createdAt)->setTimezone('Asia/Manila')->format('mdy').'-';
            while (Order::query()->where('reference_number', $prefix.str_pad((string) $referenceSequence, 4, '0', STR_PAD_LEFT))->exists()) {
                $referenceSequence++;
            }
            $daily->update(['next_number' => $referenceSequence + 1]);

            return [
                'order_number' => (string) $number,
                'reference_number' => $prefix.str_pad((string) $referenceSequence, 4, '0', STR_PAD_LEFT),
            ];
        });
    }
}
