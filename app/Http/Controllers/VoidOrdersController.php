<?php

namespace App\Http\Controllers;

use App\Http\Requests\VoidOrdersRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderVoid;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class VoidOrdersController extends Controller
{
    public function __invoke(VoidOrdersRequest $request): Response
    {
        $filters = $request->safe()->only([
            'branch_id',
            'initiated_by_user_id',
            'authorized_by_user_id',
            'reason_code',
            'date',
        ]);
        $voids = OrderVoid::query()
            ->with([
                'branch:id,name,code',
                'order:id,order_number,reference_number,customer_label,total,commercial_status,voided_at',
                'initiatedBy:id,name,email',
                'authorizedBy:id,name,email',
            ])
            ->when($filters['branch_id'] ?? null, fn (Builder $query, string $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['initiated_by_user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('initiated_by_user_id', $userId))
            ->when($filters['authorized_by_user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('authorized_by_user_id', $userId))
            ->when($filters['reason_code'] ?? null, fn (Builder $query, string $reasonCode) => $query->where('reason_code', $reasonCode))
            ->when($filters['date'] ?? null, function (Builder $query, string $date): void {
                $day = CarbonImmutable::parse($date, 'Asia/Manila');
                $query->whereBetween('created_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()]);
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
        $audits = AuditLog::query()
            ->where('auditable_type', Order::class)
            ->where('action', 'order.voided')
            ->whereIn('auditable_id', $voids->getCollection()->pluck('order_id'))
            ->latest('created_at')
            ->get()
            ->keyBy('auditable_id');

        $voids->through(function (OrderVoid $void) use ($audits): array {
            /** @var AuditLog|null $audit */
            $audit = $audits->get($void->order_id);

            return [
                'id' => $void->id,
                'created_at' => $void->created_at->toIso8601String(),
                'branch' => $void->branch?->only(['id', 'name', 'code']),
                'order' => $void->order?->only(['id', 'order_number', 'reference_number', 'customer_label', 'total', 'commercial_status', 'voided_at']),
                'initiated_by' => $void->initiatedBy?->only(['id', 'name', 'email']),
                'authorized_by' => $void->authorizedBy?->only(['id', 'name', 'email']),
                'reason_code' => $void->reason_code,
                'reason_label' => $void->reason_label,
                'reason_text' => $void->reason_text,
                'authorization_method' => $void->authorization_method,
                'audit' => $audit === null ? null : [
                    'id' => $audit->id,
                    'created_at' => $audit->created_at->toIso8601String(),
                    'before' => $audit->before,
                    'after' => $audit->after,
                    'metadata' => $audit->metadata,
                ],
            ];
        });

        return Inertia::render('super-admin/void-orders', [
            'voids' => $voids,
            'filters' => $filters,
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name', 'code']),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }
}
