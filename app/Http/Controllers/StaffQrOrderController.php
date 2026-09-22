<?php

namespace App\Http\Controllers;

use App\Actions\Orders\ArchiveCustomerQrOrder;
use App\Actions\Orders\LoadCustomerQrOrder;
use App\Enums\CommercialStatus;
use App\Enums\OrderSource;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\PayLaterOrderSummary;
use App\Support\PosAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffQrOrderController extends Controller
{
    public function __construct(private ActiveBranchContext $context, private PosAccess $access, private PayLaterOrderSummary $summary) {}

    /** @return array{User, Branch} */
    private function authorize(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $this->context->current($user);
        abort_if($branch === null, 403);

        return [$this->access->authorize($user, $branch), $branch];
    }

    public function index(Request $request): JsonResponse
    {
        [$user, $branch] = $this->authorize($request);
        $data = $request->validate(['search' => ['nullable', 'string', 'max:150'], 'archived' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1']]);
        $archived = $request->boolean('archived');
        $store = $branch->storeSessions()->where('status', StoreSessionStatus::Open)->first();
        $query = Order::query()->where('branch_id', $branch->id)->where('source', OrderSource::CustomerQr)
            ->where('commercial_status', $archived ? CommercialStatus::ArchivedUnclaimed : CommercialStatus::Submitted)
            ->when(! $archived, fn ($query) => $query->where('store_session_id', $store?->id)->whereNull('loaded_by_user_id'))
            ->when(! empty($data['search']), function ($query) use ($data): void {
                $search = '%'.strtolower(ltrim($data['search'], '#')).'%';
                $query->where(fn ($query) => $query->whereRaw('LOWER(order_number) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(customer_label) LIKE ?', [$search])->orWhereRaw('LOWER(table_name_snapshot) LIKE ?', [$search]));
            })->orderBy('submitted_at')->orderBy('id');
        $orders = $query->with(['items.modifiers', 'branchTable', 'createdBy'])->paginate(30);
        $orders->through(fn (Order $order): array => $this->summary->qr($order));

        return response()->json(['orders' => $orders])->header('Cache-Control', 'no-store');
    }

    public function load(Request $request, Order $order, LoadCustomerQrOrder $load): JsonResponse
    {
        [$user, $branch] = $this->authorize($request);

        return response()->json(['order' => $this->summary->qr($load->execute($user, $branch, $order))])->header('Cache-Control', 'no-store');
    }

    public function destroy(Request $request, Order $order, ArchiveCustomerQrOrder $archive): JsonResponse
    {
        [, $branch] = $this->authorize($request);
        abort_unless($order->branch_id === $branch->id, 404);
        abort_unless($archive->execute($order, 'cashier_archived'), 409, 'This QR order is no longer unclaimed.');

        return response()->json(['archived' => true]);
    }
}
