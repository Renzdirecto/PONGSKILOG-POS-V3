<?php

namespace App\Http\Controllers;

use App\Actions\CustomerScreens\PairCustomerScreen;
use App\Actions\CustomerScreens\ToggleCustomerScreenMode;
use App\Actions\CustomerScreens\UnpairCustomerScreen;
use App\Enums\CommercialStatus;
use App\Enums\CustomerScreenMode;
use App\Events\CustomerScreenChanged;
use App\Http\Requests\SyncCustomerScreenCartRequest;
use App\Models\Branch;
use App\Models\CustomerScreen;
use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\CustomerScreenCart;
use App\Support\CustomerScreenLiveState;
use App\Support\CustomerScreens;
use App\Support\PosAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The POS station's side of its customer screen (Phase 19.6A): the Store Operations header control (pairing status,
 * pair / unpair, the MENU and CUSTOMER DISPLAY controls), the live cart projection and the successful-order takeover.
 * Every request is a POS action at the selected Branch (`PosAccess`), the station comes from the `X-POS-Station`
 * header and the Branch from the server-side Branch context — a forged Branch or station id finds no screen.
 */
class PosCustomerScreenController extends Controller
{
    /** A takeover is only for an order this station just committed, never a replay of an old one. */
    private const TAKEOVER_WINDOW_MINUTES = 10;

    public function __construct(
        private CustomerScreens $screens,
        private CustomerScreenLiveState $live,
        private ActiveBranchContext $context,
        private PosAccess $access,
    ) {}

    public function status(Request $request): JsonResponse
    {
        [$user, $branch, $station] = $this->station($request);
        $this->access->authorize($user, $branch);

        return response()->json($this->statusPayload($this->screens->forStation($branch, $station)));
    }

    public function pair(Request $request, PairCustomerScreen $pair): JsonResponse
    {
        [$user, $branch, $station] = $this->station($request);
        $screen = $pair->execute($user, $branch, $station, $request->input('code'));

        return response()->json($this->statusPayload($screen));
    }

    public function unpair(Request $request, UnpairCustomerScreen $unpair): JsonResponse
    {
        [$user, $branch, $station] = $this->station($request);
        $unpair->fromStation($user, $branch, $station);

        return response()->json($this->statusPayload(null));
    }

    public function mode(Request $request, ToggleCustomerScreenMode $toggle): JsonResponse
    {
        [$user, $branch, $station] = $this->station($request);
        $data = $request->validate(['control' => ['required', Rule::in([CustomerScreenMode::Menu->value, CustomerScreenMode::CustomerDisplay->value])]]);
        $screen = $toggle->execute($user, $branch, $station, CustomerScreenMode::from($data['control']));
        abort_if($screen === null, 404, 'No customer screen is paired with this POS station.');

        return response()->json($this->statusPayload($screen));
    }

    public function cart(SyncCustomerScreenCartRequest $request, CustomerScreenCart $projection): JsonResponse
    {
        [$user, $branch, $station] = $this->station($request);
        $user = $this->access->authorize($user, $branch);
        $screen = $this->screens->forStation($branch, $station);
        if ($screen === null) {
            return response()->json(['paired' => false]);
        }
        $orderType = $request->validated('order_type');
        $cart = $projection->project($branch, $screen->id, $request->items(), $projection->savedOrder($branch, $request->validated('saved_order_id')));
        $changed = $this->live->putCart($screen, (string) $request->validated('instance'), (int) $request->validated('sequence'), (int) $user->getKey(), is_string($orderType) ? $orderType : null, $cart);
        if ($changed) {
            CustomerScreenChanged::dispatch([$screen->channel_key], 'cart');
        }

        return response()->json(['paired' => true]);
    }

    public function takeover(Request $request): JsonResponse
    {
        [$user, $branch, $station] = $this->station($request);
        $data = $request->validate([
            'order_id' => ['required', 'uuid'],
            'instance' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9-]{8,64}\z/'],
            'sequence' => ['nullable', 'integer', 'min:0', 'max:2147483647'],
        ]);
        $this->access->authorize($user, $branch);
        $screen = $this->screens->forStation($branch, $station);
        if ($screen === null) {
            return response()->json(['shown' => false]);
        }
        $order = Order::query()
            ->where('branch_id', $branch->getKey())
            ->whereKey($data['order_id'])
            ->whereNotNull('committed_at')
            ->where('committed_at', '>=', now()->subMinutes(self::TAKEOVER_WINDOW_MINUTES))
            ->whereIn('commercial_status', [CommercialStatus::Active, CommercialStatus::Completed])
            ->first();
        abort_if($order === null || $order->order_number === null, 404, 'This order cannot be shown on the customer screen.');
        $through = isset($data['instance'], $data['sequence']) ? ['instance' => (string) $data['instance'], 'sequence' => (int) $data['sequence']] : null;
        $takeover = $this->live->startTakeover($screen, $order->id, $order->order_type->value, $through);
        CustomerScreenChanged::dispatch([$screen->channel_key], 'takeover');

        return response()->json(['shown' => true, 'duration_ms' => $takeover['duration_ms']]);
    }

    /** @return array{0: User, 1: Branch, 2: string} */
    private function station(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $this->context->current($user) ?? abort(403, 'Select a Branch first.');
        $station = $this->screens->stationId($request)
            ?? throw ValidationException::withMessages(['station' => 'This POS station is not identified. Refresh the page and try again.']);

        return [$user, $branch, $station];
    }

    /** @return array{paired: bool, mode: string|null, paired_at: string|null, last_seen_at: string|null} */
    private function statusPayload(?CustomerScreen $screen): array
    {
        return [
            'paired' => $screen !== null && $screen->isPaired(),
            'mode' => $screen?->isPaired() ? $screen->mode->value : null,
            'paired_at' => $screen?->paired_at?->toIso8601String(),
            'last_seen_at' => $screen?->last_seen_at?->toIso8601String(),
        ];
    }
}
