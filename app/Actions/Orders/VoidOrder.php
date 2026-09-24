<?php

namespace App\Actions\Orders;

use App\Actions\Audit\AuditRecorder;
use App\Actions\Inventory\ApplyInventoryMovement;
use App\Actions\Operations\RecordOrderIngredientUsage;
use App\Enums\CommercialStatus;
use App\Enums\InventoryMovementType;
use App\Enums\StoreSessionStatus;
use App\Events\CustomerTrackingChanged;
use App\Events\DisplayOrdersChanged;
use App\Events\OrderVoided;
use App\Http\Requests\VoidOrderRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Order;
use App\Models\OrderVoid;
use App\Models\Product;
use App\Models\StoreSession;
use App\Models\User;
use App\Models\VoidAuthorizationSetting;
use App\Support\PosAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class VoidOrder
{
    /** @var array<string, string> */
    private const REASONS = [
        'wrong_item' => 'Wrong item rung up',
        'customer_cancelled' => 'Customer cancelled',
        'duplicate_transaction' => 'Duplicate transaction',
        'price_or_quantity_error' => 'Price or quantity error',
        'other' => 'Other',
    ];

    public function __construct(
        private PosAccess $access,
        private ApplyInventoryMovement $inventory,
        private AuditRecorder $audit,
        private RecordOrderIngredientUsage $ingredients,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $initiator, Branch $branch, Order $requestedOrder, array $input): Order
    {
        $data = Validator::make($input, (new VoidOrderRequest)->rules())->validate();
        if ($data['reason_code'] === 'other' && $this->reasonText($data) === null) {
            throw ValidationException::withMessages(['reason_text' => 'Describe the Void reason.']);
        }
        $data['idempotency_key'] = strtolower((string) $data['idempotency_key']);

        return DB::transaction(function () use ($initiator, $branch, $requestedOrder, $data): Order {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$data['idempotency_key']]);
            }

            $branch = Branch::query()->whereKey($branch->id)->firstOrFail();
            $initiator = $this->access->authorize($initiator, $branch);
            $authorizer = $this->authorizer($data, $initiator, lock: false);
            $requestHash = $this->requestHash($requestedOrder, $initiator, $authorizer, $data);

            $replay = OrderVoid::query()->where('idempotency_key', $data['idempotency_key'])->first();
            if ($replay !== null) {
                $audit = AuditLog::query()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($replay->order_id !== $requestedOrder->id
                    || $replay->initiated_by_user_id !== $initiator->id
                    || $replay->authorized_by_user_id !== $authorizer->id
                    || data_get($audit?->metadata, 'request_hash') !== $requestHash) {
                    abort(409, 'This Void attempt has already been used with different details.');
                }

                return Order::query()->where('branch_id', $branch->id)->findOrFail($replay->order_id);
            }

            $session = StoreSession::query()
                ->where('branch_id', $branch->id)
                ->where('status', StoreSessionStatus::Open)
                ->sharedLock()
                ->first();
            if ($session === null) {
                throw ValidationException::withMessages(['store' => 'Store is closed. Historical transactions are read-only.']);
            }

            $order = Order::query()
                ->where('branch_id', $branch->id)
                ->whereKey($requestedOrder->id)
                ->lockForUpdate()
                ->firstOrFail();
            $authorizer = $this->authorizer($data, $initiator, lock: true);
            $requestHash = $this->requestHash($order, $initiator, $authorizer, $data);
            if ($order->store_session_id !== $session->id || $order->committed_at === null || $order->commercial_status !== CommercialStatus::Active) {
                throw ValidationException::withMessages(['order' => 'Only an active order from the current store session can be voided.']);
            }
            if ($order->version !== $data['expected_version']) {
                abort(409, 'This order changed after you opened it. Refresh its details and try again.');
            }
            if (OrderVoid::query()->where('order_id', $order->id)->exists()) {
                throw ValidationException::withMessages(['order' => 'This order has already been voided.']);
            }

            $before = $this->snapshot($order);
            $restorations = $this->restoreInventory($branch, $order, $initiator);
            /** Restores the current net recorded Ingredient usage (post-edit), once, from the historical snapshot. */
            $ingredientRestorations = $this->ingredients->void($order, $branch, $initiator);
            $void = OrderVoid::query()->create([
                'branch_id' => $branch->id,
                'store_session_id' => $session->id,
                'order_id' => $order->id,
                'initiated_by_user_id' => $initiator->id,
                'authorized_by_user_id' => $authorizer->id,
                'reason_code' => $data['reason_code'],
                'reason_label' => self::REASONS[$data['reason_code']],
                'reason_text' => $this->reasonText($data),
                'authorization_method' => 'super_admin_pin',
                'idempotency_key' => $data['idempotency_key'],
            ]);
            $order->update([
                'commercial_status' => CommercialStatus::Voided,
                'voided_at' => now(),
                'version' => $order->version + 1,
            ]);
            $order->refresh();

            $this->audit->record(
                branch: $branch,
                actor: $initiator,
                module: 'transactions',
                action: 'order.voided',
                auditableType: Order::class,
                auditableId: $order->id,
                before: $before,
                after: $this->snapshot($order),
                metadata: [
                    'request_hash' => $requestHash,
                    'void_id' => $void->id,
                    'initiated_by_user_id' => $initiator->id,
                    'authorized_by_user_id' => $authorizer->id,
                    'authorization_method' => 'super_admin_pin',
                    'reason_code' => $void->reason_code,
                    'reason_label' => $void->reason_label,
                    'reason_text' => $void->reason_text,
                    'inventory_restorations' => $restorations,
                    'ingredient_restorations' => $ingredientRestorations,
                    'store_session_id' => $session->id,
                ],
                idempotencyKey: $data['idempotency_key'],
            );

            OrderVoided::dispatch($order);
            DisplayOrdersChanged::dispatch($branch, now());
            CustomerTrackingChanged::dispatch($order);

            return $order;
        }, attempts: 3);
    }

    /** @param array<string, mixed> $data */
    private function authorizer(array $data, User $initiator, bool $lock): User
    {
        $setting = VoidAuthorizationSetting::query()
            ->where('scope', 'global')
            ->with('configuredBy')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
        $authorizer = $setting?->configuredBy;

        if ($setting === null
            || $authorizer === null
            || ! $authorizer->is_active
            || ! $authorizer->hasRole('super_admin')
            || $authorizer->id === $initiator->id
            || ! Hash::check((string) $data['authorization_pin'], $setting->pin_hash)) {
            throw ValidationException::withMessages(['authorization' => 'Authorization could not be verified.']);
        }

        return $authorizer;
    }

    /** @return list<array{product_id: string, quantity_restored: int, movement_id: string}> */
    private function restoreInventory(Branch $branch, Order $order, User $initiator): array
    {
        $movements = InventoryMovement::query()
            ->where('order_id', $order->id)
            ->whereIn('movement_type', [
                InventoryMovementType::Sale,
                InventoryMovementType::PayLaterCommit,
                InventoryMovementType::OrderEditDelta,
            ])
            ->orderBy('product_id')
            ->lockForUpdate()
            ->get(['id', 'product_id', 'quantity_delta']);
        $net = $movements->groupBy('product_id')
            ->map(fn (Collection $rows): int => $rows->sum('quantity_delta'))
            ->filter(fn (int $quantity): bool => $quantity < 0)
            ->map(fn (int $quantity): int => abs($quantity));
        $products = Product::query()->whereKey($net->keys())->orderBy('id')->get()->keyBy('id');
        $restorations = [];

        foreach ($net->sortKeys() as $productId => $quantity) {
            $movement = $this->inventory->execute(
                branch: $branch,
                product: $products->get($productId) ?? throw new \LogicException('Order inventory movement references a missing product.'),
                movementType: InventoryMovementType::VoidRestore,
                quantityDelta: $quantity,
                reason: 'Void order '.$order->order_number,
                actor: $initiator,
                orderId: $order->id,
            );
            $restorations[] = [
                'product_id' => $productId,
                'quantity_restored' => $quantity,
                'movement_id' => $movement->id,
            ];
        }

        return $restorations;
    }

    /** @return array<string, mixed> */
    private function snapshot(Order $order): array
    {
        return [
            'commercial_status' => $order->commercial_status->value,
            'payment_status' => $order->payment_status->value,
            'kitchen_status' => $order->kitchen_status->value,
            'total' => $order->total,
            'version' => $order->version,
            'voided_at' => $order->voided_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data */
    private function requestHash(Order $order, User $initiator, User $authorizer, array $data): string
    {
        return hash('sha256', json_encode([
            'order_id' => $order->id,
            'initiator_id' => $initiator->id,
            'authorizer_id' => $authorizer->id,
            'reason_code' => $data['reason_code'],
            'reason_text' => $this->reasonText($data),
            'expected_version' => $data['expected_version'],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $data */
    private function reasonText(array $data): ?string
    {
        $reason = trim((string) ($data['reason_text'] ?? ''));

        return $reason === '' ? null : $reason;
    }
}
