<?php

namespace App\Http\Controllers;

use App\Actions\Orders\SubmitCustomerQrOrder;
use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Http\Requests\SubmitCustomerQrOrderRequest;
use App\Models\Branch;
use App\Models\CustomerQrSession;
use App\Support\CustomerQrAccess;
use App\Support\CustomerQrProjection;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

class CustomerQrOrderController extends Controller
{
    public function __construct(private CustomerQrAccess $access, private CustomerQrProjection $projection) {}

    public function store(SubmitCustomerQrOrderRequest $request, Branch $branch, SubmitCustomerQrOrder $submit): JsonResponse
    {
        $order = $submit->execute($branch, $this->access->requireSession($request, $branch), $request->validated());

        return response()->json(['order' => $this->projection->order($order)])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, Branch $branch, string $tracking): JsonResponse
    {
        $order = $this->access->order($this->access->requireSession($request, $branch), $tracking);

        return response()->json(['order' => $this->projection->order($order)])->header('Cache-Control', 'no-store');
    }

    public function receipt(Request $request, Branch $branch, string $tracking): JsonResponse
    {
        $order = $this->access->order($this->access->requireSession($request, $branch), $tracking);

        return response()->json(['receipt' => $this->projection->receipt($order)])->header('Cache-Control', 'no-store');
    }

    public function reset(Request $request, Branch $branch): JsonResponse
    {
        $session = $this->access->requireSession($request, $branch);
        DB::transaction(function () use ($session): void {
            $session = CustomerQrSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            abort_if($session->expires_at->lte(now()), 419);
            $order = $session->activeOrder()->lockForUpdate()->first();
            abort_if($order !== null && $order->kitchen_status !== KitchenStatus::Done
                && $order->commercial_status !== CommercialStatus::ArchivedUnclaimed, 409, 'May active order ka pa.');
            $session->update(['active_order_id' => null]);
        });

        return response()->json(['reset' => true])->header('Cache-Control', 'no-store');
    }

    public function authorizeChannel(Request $request, Branch $branch): JsonResponse
    {
        $session = $this->access->requireSession($request, $branch);
        $data = $request->validate(['channel_name' => ['required', 'string', 'max:150'], 'socket_id' => ['required', 'regex:/\A[0-9]+\.[0-9]+\z/']]);
        $channel = $data['channel_name'];
        if ($channel !== 'private-qr-catalog.'.$branch->id) {
            abort_unless(str_starts_with($channel, 'private-order-tracking.'), 403);
            $this->access->order($session, substr($channel, strlen('private-order-tracking.')));
        }
        $broadcaster = Broadcast::connection();
        abort_unless($broadcaster instanceof PusherBroadcaster, 503, 'Live updates are temporarily unavailable.');

        return response()->json(json_decode($broadcaster->getPusher()->authorizeChannel($channel, $data['socket_id']), true, flags: JSON_THROW_ON_ERROR))
            ->header('Cache-Control', 'no-store');
    }
}
