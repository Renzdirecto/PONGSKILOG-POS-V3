<?php

namespace App\Http\Controllers;

use App\Enums\CommercialStatus;
use App\Enums\StoreSessionStatus;
use App\Http\Requests\BusinessTransactionHistoryRequest;
use App\Http\Requests\TransactionHistoryRequest;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use App\Support\PosAccess;
use App\Support\PosReceipt;
use App\Support\TransactionHistory;
use App\Support\TransactionProjection;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TransactionHistoryController extends Controller
{
    public function index(TransactionHistoryRequest $request, ActiveBranchContext $context, PosAccess $access, TransactionHistory $history, BranchCatalog $catalog): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $access->authorize($user, $branch);

        return Inertia::render('workspaces/transaction-history', [
            ...$history->for($branch, $request->validated(), $branch),
            'filters' => $request->safe()->except('page'),
            'surface' => 'pos',
            'scope' => $branch->only(['id', 'name', 'code']),
            'operational' => true,
            'catalog' => fn () => $catalog->browse($branch, true),
            'tables' => fn () => $branch->tables()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * The same Transaction History page for a business-wide viewer across All Branches or the selected Branch. It is
     * read-only unless the viewer also holds POS access to the selected Branch (Super Admin, a business-wide Custom Role
     * with POS) and that Branch's Store is OPEN, in which case the usual POS rules decide each Order's Edit, Settle and
     * Void capability. A closed Store is historical, view-only reading; the write endpoints re-authorize regardless.
     */
    public function business(BusinessTransactionHistoryRequest $request, ActiveBranchContext $context, PosAccess $access, TransactionHistory $history, BranchCatalog $catalog): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        $operational = $this->operationalBranch($user, $branch, $access);

        return Inertia::render('workspaces/transaction-history', [
            ...$history->for($branch, $request->validated(), $operational),
            'filters' => $request->safe()->except('page'),
            'surface' => 'business',
            'scope' => $branch?->only(['id', 'name', 'code']),
            'operational' => $operational !== null,
            'catalog' => $operational === null ? null : fn () => $catalog->browse($operational, true),
            'tables' => $operational === null ? [] : fn () => $operational->tables()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Order $order, ActiveBranchContext $context, PosAccess $access, TransactionProjection $projection, PosReceipt $receipt): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $access->authorize($user, $branch);

        return $this->detail($this->visibleOrder($order, $branch), $branch, $projection, $receipt);
    }

    /**
     * Transaction detail for a business-wide viewer, limited to the selected Branch when one is chosen.
     */
    public function businessShow(Request $request, Order $order, ActiveBranchContext $context, PosAccess $access, TransactionProjection $projection, PosReceipt $receipt): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active
            && $user->hasPermission('transactions.view') && $user->hasBusinessWideScope(), 403);
        $branch = $context->current($user);
        $operational = $this->operationalBranch($user, $branch, $access);

        return $this->detail($this->visibleOrder($order, $branch), $operational, $projection, $receipt);
    }

    private function visibleOrder(Order $order, ?Branch $branch): Order
    {
        return Order::query()
            ->when($branch !== null, fn ($query) => $query->where('branch_id', $branch?->id))
            ->whereNotNull('committed_at')
            ->where('commercial_status', '!=', CommercialStatus::Voided)
            ->with('branch:id,name,code', 'items.modifiers', 'payments.createdBy', 'payments.invoiceProof', 'adjustments.createdBy', 'branchTable')
            ->findOrFail($order->id);
    }

    /**
     * @param  Branch|null  $mutableBranch  the POS-authorized Branch, or null for a read-only viewer
     */
    private function detail(Order $order, ?Branch $mutableBranch, TransactionProjection $projection, PosReceipt $receipt): JsonResponse
    {
        $operational = $mutableBranch !== null && $order->branch_id === $mutableBranch->id;
        $canMutate = $operational
            && $order->commercial_status === CommercialStatus::Active
            && $mutableBranch->storeSessions()->where('status', StoreSessionStatus::Open)->whereKey($order->store_session_id)->exists();
        $detail = $projection->detail($order, $canMutate);
        $summary = $receipt->summary($order);
        if (! $operational) {
            /** Invoice proofs stream through the POS-only route, so a read-only viewer sees that one exists but no link. */
            $detail['payment_groups'] = array_map(fn (array $group): array => [
                ...$group,
                'payments' => array_map(fn (array $payment): array => [
                    ...$payment,
                    'invoice' => $payment['invoice'] === null ? null : ['name' => $payment['invoice']['name'], 'url' => null],
                ], $group['payments']),
            ], $detail['payment_groups']);
            $summary['payments'] = array_map(fn (array $payment): array => [
                ...$payment,
                'invoice' => $payment['invoice'] === null ? null : ['name' => $payment['invoice']['name'], 'url' => null],
            ], $summary['payments']);
        }

        return response()->json([
            'transaction' => [
                ...$detail,
                'branch' => ['id' => $order->branch->id, 'name' => $order->branch->name, 'code' => $order->branch->code],
                'operational' => $operational,
                'receipt' => $summary,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * The selected Branch when the viewer may also operate its POS (Super Admin, business-wide Custom Role with POS) and
     * its Store Session is OPEN; Owner scope never qualifies.
     */
    private function operationalBranch(User $user, ?Branch $branch, PosAccess $access): ?Branch
    {
        if ($branch === null || ! $user->hasCashierOperationsRole()
            || ! $branch->storeSessions()->where('status', StoreSessionStatus::Open)->exists()) {
            return null;
        }

        try {
            $access->authorize($user, $branch);
        } catch (AuthorizationException) {
            return null;
        }

        return $branch;
    }
}
