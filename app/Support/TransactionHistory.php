<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Order;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class TransactionHistory
{
    public function __construct(private TransactionProjection $projection) {}

    /**
     * One page of committed, non-voided transactions for a Branch, or for every Branch when a business-wide viewer
     * chose All Branches. Edit, Settle and Void are offered only on the POS-authorized mutable Branch, for Orders of
     * its current OPEN Store Session; every other surface is read-only and the write endpoints re-authorize anyway.
     *
     * @param  array<string, mixed>  $filters
     * @param  Branch|null  $mutableBranch  the Branch the viewer may operate on through the POS, if any
     * @return array<string, mixed>
     */
    public function for(?Branch $branch, array $filters, ?Branch $mutableBranch = null): array
    {
        $query = $this->baseQuery($branch)
            ->with(['branch:id,name,code', 'items.modifiers', 'payments.createdBy', 'payments.invoiceProof', 'adjustments', 'branchTable', 'voidRecord.initiatedBy', 'voidRecord.authorizedBy']);

        $this->applyFilters($query, $filters);
        $metricsQuery = $this->baseQuery($branch);
        $this->applyFilters($metricsQuery, Arr::except($filters, ['kitchen_status', 'payment_status']));
        $metrics = $metricsQuery->where('commercial_status', '!=', CommercialStatus::Voided->value)->toBase()
            ->selectRaw("SUM(CASE WHEN kitchen_status = 'kitchen' THEN 1 ELSE 0 END) AS in_kitchen")
            ->selectRaw("SUM(CASE WHEN kitchen_status = 'preparing' THEN 1 ELSE 0 END) AS preparing")
            ->selectRaw("SUM(CASE WHEN kitchen_status = 'done' THEN 1 ELSE 0 END) AS done")
            ->selectRaw("SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) AS paid")
            ->selectRaw("SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) AS pending")
            ->first();
        $openSessionId = $mutableBranch?->storeSessions()->where('status', StoreSessionStatus::Open)->value('id');
        $page = $query->orderByDesc('committed_at')->orderByDesc('id')->paginate(10)->withQueryString();
        $page->through(function (Order $order) use ($openSessionId, $mutableBranch): array {
            $canMutate = $openSessionId !== null
                && $order->branch_id === $mutableBranch->id
                && $order->store_session_id === $openSessionId
                && $order->commercial_status === CommercialStatus::Active;
            $summary = $this->projection->summary($order);

            return [
                ...$summary,
                'branch' => ['id' => $order->branch->id, 'name' => $order->branch->name, 'code' => $order->branch->code],
                'can_edit' => $canMutate,
                'can_settle' => $canMutate && (float) $summary['outstanding'] > 0,
                'can_void' => $canMutate,
            ];
        });

        return [
            'transactions' => $page,
            'history_total' => $this->baseQuery($branch)->count(),
            'metrics' => [
                'in_kitchen' => (int) ($metrics->in_kitchen ?? 0),
                'preparing' => (int) ($metrics->preparing ?? 0),
                'done' => (int) ($metrics->done ?? 0),
                'paid' => (int) ($metrics->paid ?? 0),
                'pending' => (int) ($metrics->pending ?? 0),
            ],
        ];
    }

    /** @return Builder<Order> */
    private function baseQuery(?Branch $branch): Builder
    {
        return Order::query()
            ->when($branch !== null, fn (Builder $query) => $query->where('branch_id', $branch?->id))
            ->whereNotNull('committed_at')
            ->whereIn('commercial_status', [CommercialStatus::Active, CommercialStatus::Completed]);
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
                ->where(function (Builder $attempt): void {
                    $attempt
                        ->whereIn('payment_context', ['initial', 'pay_later_settlement'])
                        ->orWhere(function (Builder $legacy): void {
                            $legacy
                                ->whereNull('payment_context')
                                ->whereRaw('payments.paid_at = (select min(first_payment.paid_at) from payments as first_payment where first_payment.order_id = orders.id)');
                        });
                });
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
            'last_7_days' => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
            'month' => [$now->startOfMonth(), $now->endOfDay()],
            'custom' => [CarbonImmutable::parse($filters['from'], 'Asia/Manila')->startOfDay(), CarbonImmutable::parse($filters['to'], 'Asia/Manila')->endOfDay()],
            default => [null, null],
        };
    }
}
