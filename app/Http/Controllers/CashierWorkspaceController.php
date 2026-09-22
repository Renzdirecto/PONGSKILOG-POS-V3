<?php

namespace App\Http\Controllers;

use App\Enums\BranchStatus;
use App\Enums\CommercialStatus;
use App\Enums\OrderSource;
use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use App\Support\KitchenBoard;
use App\Support\PayLaterOrderSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CashierWorkspaceController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        Request $request,
        ActiveBranchContext $activeBranchContext,
        BranchCatalog $catalog,
        KitchenBoard $kitchenBoard,
    ): RedirectResponse|Response {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless($user->hasRole('cashier') || $user->hasRole('cashier_kitchen'), 403);

        $branch = $activeBranchContext->current($user);

        if ($branch === null) {
            return to_route('workspace');
        }

        return Inertia::render('workspaces/show', [
            'qrWaitingCount' => fn (): int => Order::query()->where('branch_id', $branch->id)
                ->where('source', OrderSource::CustomerQr)->where('commercial_status', CommercialStatus::Submitted)
                ->whereNull('loaded_by_user_id')->whereIn('store_session_id', $branch->storeSessions()->where('status', 'open')->select('id'))->count(),
            'loadedQr' => function () use ($branch, $user): ?array {
                $order = Order::query()->where('branch_id', $branch->id)
                    ->where('source', OrderSource::CustomerQr)
                    ->where('commercial_status', CommercialStatus::Submitted)
                    ->where('loaded_by_user_id', $user->id)->first();

                return $order === null ? null : app(PayLaterOrderSummary::class)->qr($order);
            },
            'workspace' => 'Cashier / POS',
            'eyebrow' => 'Branch Operations',
            'description' => 'Branch-scoped cashier and point-of-sale workspace.',
            'catalog' => fn () => $branch->status === BranchStatus::Active
                ? $catalog->browse($branch, customization: true)
                : ['categories' => [], 'products' => []],
            'tables' => fn () => $branch->tables()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
            'readyOrders' => fn (): array => $kitchenBoard->readyForPos($branch),
            'kitchenStatus' => fn (): array => $kitchenBoard->statusForPos($branch),
            'store' => [
                'branchStatus' => $branch->status->value,
                'canOpen' => $branch->status === BranchStatus::Active
                    && $user->hasPermission('store.open_close')
                    && $user->branches()->whereKey($branch->getKey())->wherePivot('is_active', true)->exists(),
            ],
        ]);
    }
}
