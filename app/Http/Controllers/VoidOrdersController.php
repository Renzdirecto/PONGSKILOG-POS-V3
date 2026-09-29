<?php

namespace App\Http\Controllers;

use App\Enums\InventoryMovementType;
use App\Http\Requests\VoidOrdersRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderVoid;
use App\Models\User;
use App\Models\VoidAuthorizationSetting;
use App\Support\TransactionProjection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Inertia\Inertia;
use Inertia\Response;

class VoidOrdersController extends Controller
{
    public function __invoke(VoidOrdersRequest $request, TransactionProjection $projection): Response
    {
        $filters = $request->safe()->only([
            'branch_id',
            'initiated_by_user_id',
            'authorized_by_user_id',
            'reason_code',
            'search',
            'date',
        ]);
        $voids = OrderVoid::query()
            ->with([
                'branch:id,name,code',
                'order.items.modifiers',
                'order.payments.createdBy',
                'order.payments.invoiceProof',
                'order.adjustments.createdBy',
                'order.branchTable',
                'order.voidRecord.initiatedBy',
                'order.voidRecord.authorizedBy',
                'order.inventoryMovements' => fn (Relation $movements) => $movements
                    ->where('movement_type', InventoryMovementType::VoidRestore)
                    ->with('product:id,name'),
                'initiatedBy:id,name,email',
                'authorizedBy:id,name,email',
            ])
            ->when($filters['branch_id'] ?? null, fn (Builder $query, string $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['initiated_by_user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('initiated_by_user_id', $userId))
            ->when($filters['authorized_by_user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('authorized_by_user_id', $userId))
            ->when($filters['reason_code'] ?? null, fn (Builder $query, string $reasonCode) => $query->where('reason_code', $reasonCode))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->whereHas('order', fn (Builder $order) => $order
                        ->whereRaw('LOWER(order_number) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(reference_number) LIKE ?', [$term]))
                        ->orWhereHas('initiatedBy', fn (Builder $user) => $user
                            ->whereRaw('LOWER(name) LIKE ?', [$term]))
                        ->orWhereHas('authorizedBy', fn (Builder $user) => $user
                            ->whereRaw('LOWER(name) LIKE ?', [$term]));
                });
            })
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

        $voids->through(function (OrderVoid $void) use ($audits, $projection): array {
            /** @var AuditLog|null $audit */
            $audit = $audits->get($void->order_id);

            return [
                'id' => $void->id,
                'created_at' => $void->created_at->toIso8601String(),
                'branch' => $void->branch?->only(['id', 'name', 'code']),
                'order' => $void->order === null ? null : $projection->detail($void->order, false),
                'initiated_by' => $void->initiatedBy?->only(['id', 'name', 'email']),
                'authorized_by' => $void->authorizedBy?->only(['id', 'name', 'email']),
                'reason_code' => $void->reason_code,
                'reason_label' => $void->reason_label,
                'reason_text' => $void->reason_text,
                'authorization_method' => $void->authorization_method,
                'audit' => $audit === null ? null : [
                    'id' => $audit->id,
                    'created_at' => $audit->created_at->toIso8601String(),
                ],
            ];
        });

        $pinSetting = VoidAuthorizationSetting::query()
            ->where('scope', 'global')
            ->with('configuredBy:id,name,email')
            ->first();

        return Inertia::render('super-admin/void-orders', [
            'voids' => $voids,
            'filters' => $filters,
            /** Closures: the realtime partial reload (`voids`, `pinStatus`) never runs the filter option queries. */
            'branches' => fn () => Branch::query()->orderBy('name')->get(['id', 'name', 'code']),
            'users' => fn () => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'pinStatus' => $pinSetting === null ? null : [
                'configured_at' => $pinSetting->configured_at->toIso8601String(),
                'configured_by' => $pinSetting->configuredBy?->only(['id', 'name', 'email']),
            ],
        ]);
    }
}
