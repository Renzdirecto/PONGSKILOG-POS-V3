<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class TransactionHistory
{
    public function __construct(private TransactionProjection $projection) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function for(Branch $branch, array $filters): array
    {
        $query = Order::query()
            ->where('branch_id', $branch->id)
            ->whereNotNull('committed_at')
            ->whereIn('commercial_status', [CommercialStatus::Active, CommercialStatus::Completed])
            ->with(['items', 'payments.createdBy', 'payments.invoiceProof', 'adjustments', 'branchTable']);

        $this->applyFilters($query, $filters);
        $metricsQuery = clone $query;
        $openSessionId = $branch->storeSessions()->where('status', StoreSessionStatus::Open)->value('id');
        $page = $query->orderByDesc('committed_at')->orderByDesc('id')->paginate(10)->withQueryString();
        $page->through(fn (Order $order): array => [
            ...$this->projection->summary($order),
            'can_edit' => $openSessionId !== null && $order->store_session_id === $openSessionId,
        ]);

        return [
            'transactions' => $page,
            'metrics' => [
                'total' => (clone $metricsQuery)->count(),
                'paid' => (clone $metricsQuery)->where('payment_status', 'paid')->count(),
                'pending' => (clone $metricsQuery)->where('payment_status', 'unpaid')->count(),
                'balance' => (clone $metricsQuery)->where('payment_status', 'partial')->count(),
                'sales' => (string) (clone $metricsQuery)->sum('total'),
            ],
        ];
    }

    /** @param Builder<Order> $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $needle = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
            $query->where(fn (Builder $builder) => $builder
                ->whereRaw('LOWER(order_number) LIKE LOWER(?)', [$needle])
                ->orWhereRaw('LOWER(reference_number) LIKE LOWER(?)', [$needle])
                ->orWhereRaw('LOWER(customer_label) LIKE LOWER(?)', [$needle]));
        }

        [$from, $to] = $this->dateRange($filters);
        if ($from !== null) {
            $query->whereBetween('committed_at', [$from->utc(), $to->utc()]);
        }
        foreach (['kitchen_status', 'order_type'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }
        if (! empty($filters['payment_status'])) {
            $status = match ($filters['payment_status']) {
                'pending' => 'unpaid', 'balance' => 'partial', default => $filters['payment_status'],
            };
            $query->where('payment_status', $status);
        }
        if (! empty($filters['payment_method'])) {
            $method = $filters['payment_method'];
            $firstAttempt = fn (Builder $payments, string $paymentMethod) => $payments
                ->where('method', $paymentMethod)
                ->whereRaw('payments.paid_at = (select min(first_payment.paid_at) from payments as first_payment where first_payment.order_id = orders.id)');
            if ($method === 'split') {
                $query->whereHas('payments', fn (Builder $payments) => $firstAttempt($payments, 'cash'))
                    ->whereHas('payments', fn (Builder $payments) => $firstAttempt($payments, 'cashless'));
            } else {
                $other = $method === 'cash' ? 'cashless' : 'cash';
                $query->whereHas('payments', fn (Builder $payments) => $firstAttempt($payments, $method))
                    ->whereDoesntHave('payments', fn (Builder $payments) => $firstAttempt($payments, $other));
            }
        }
    }

    /** @param array<string, mixed> $filters
     * @return array{0: CarbonImmutable|null, 1: CarbonImmutable|null}
     */
    private function dateRange(array $filters): array
    {
        $now = CarbonImmutable::now('Asia/Manila');

        return match ($filters['date'] ?? null) {
            'today' => [$now->startOfDay(), $now->endOfDay()],
            'yesterday' => [$now->subDay()->startOfDay(), $now->subDay()->endOfDay()],
            'week' => [$now->startOfWeek(), $now->endOfDay()],
            'month' => [$now->startOfMonth(), $now->endOfDay()],
            'custom' => [CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay(), CarbonImmutable::parse($filters['to'], 'Asia/Manila')->endOfDay()],
            default => [null, null],
        };
    }
}
