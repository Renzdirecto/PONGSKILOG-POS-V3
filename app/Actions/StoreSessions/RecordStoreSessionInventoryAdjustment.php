<?php

namespace App\Actions\StoreSessions;

use App\Actions\Audit\AuditRecorder;
use App\Actions\Inventory\ApplyInventoryMovement;
use App\Enums\InventoryMovementType;
use App\Enums\StockCorrectionDirection;
use App\Enums\StoreInventoryAdjustmentReason;
use App\Enums\StoreSessionStatus;
use App\Events\ReportsChanged;
use App\Http\Requests\StoreSessionInventoryAdjustmentRequest;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\StoreSession;
use App\Models\StoreSessionInventoryAdjustment;
use App\Models\User;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Store Session Stock Correction: moves the authoritative Product stock up or down so it matches the physical count
 * (count discrepancy, found or missing stock, wastage, damage, other). It never creates a Store Expense, Payment or
 * Store Purchase, so Store Close reconciliation is unaffected, and an increase is never a purchase or restock (the
 * canonical purchase paths are Store Purchase and Pamamalengke). A free Product given away is a Giveaway instead.
 */
class RecordStoreSessionInventoryAdjustment
{
    public function __construct(
        private PosAccess $access,
        private ApplyInventoryMovement $inventory,
        private AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Branch $branch, array $input): StoreSessionInventoryAdjustment
    {
        $input['note'] = is_string($input['note'] ?? null) && trim($input['note']) !== '' ? trim($input['note']) : null;
        /** @var array{idempotency_key: string, direction: string, reason_code: string, product_id: string, quantity: int|string, note: string|null} $data */
        $data = Validator::make($input, StoreSessionInventoryAdjustmentRequest::adjustmentRules(), StoreSessionInventoryAdjustmentRequest::adjustmentMessages())->validate();
        $key = strtolower($data['idempotency_key']);
        $reason = StoreInventoryAdjustmentReason::from($data['reason_code']);
        $direction = StockCorrectionDirection::from($data['direction']);
        $quantity = (int) $data['quantity'];
        if (! in_array($direction, $reason->directions(), true)) {
            throw ValidationException::withMessages(['reason_code' => $direction === StockCorrectionDirection::Increase
                ? $reason->label().' can only remove stock.'
                : $reason->label().' can only add stock.']);
        }

        return DB::transaction(function () use ($actor, $branch, $data, $key, $reason, $direction, $quantity): StoreSessionInventoryAdjustment {
            /** Branch FOR SHARE first: POS commits hold it FOR UPDATE before the Store Session, and every insert below needs a KEY SHARE on it. */
            $branch = Branch::query()->whereKey($branch->getKey())->sharedLock()->firstOrFail();
            $actor = $this->access->authorize($actor, $branch);
            abort_unless($actor->hasPermission('store_expenses.manage'), 403);

            /** Shared Session boundary: Store Close takes it exclusively, so no correction crosses a close. */
            $session = StoreSession::query()
                ->where('branch_id', $branch->id)
                ->where('status', StoreSessionStatus::Open)
                ->sharedLock()
                ->first();
            if ($session === null) {
                throw ValidationException::withMessages(['store' => 'This Store Session is no longer open.']);
            }

            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$branch->id.':inventory-adjustment:'.$key]);
            }

            $intent = hash('sha256', json_encode([
                'branch_id' => $branch->id,
                'store_session_id' => $session->id,
                'actor_id' => $actor->id,
                'direction' => $direction->value,
                'reason_code' => $reason->value,
                'product_id' => strtolower($data['product_id']),
                'quantity' => $quantity,
                'note' => $data['note'],
            ], JSON_THROW_ON_ERROR));

            $existing = StoreSessionInventoryAdjustment::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->intent_hash, $intent)) {
                    abort(409, 'This Stock Correction attempt has already been used with different details.');
                }

                return $existing->load('product', 'inventoryMovement');
            }

            $product = Product::query()->whereKey($data['product_id'])->where('is_active', true)->first();
            $tracked = $product !== null && BranchProduct::query()
                ->where('branch_id', $branch->id)
                ->where('product_id', $product->id)
                ->where('tracks_inventory', true)
                ->exists();
            if (! $tracked) {
                throw ValidationException::withMessages(['product_id' => 'This product is not inventory-tracked for this branch.']);
            }

            $delta = $direction->sign() * $quantity;
            /** ApplyInventoryMovement re-reads and locks the authoritative balance and rejects negative stock. */
            try {
                $movement = $this->inventory->execute(
                    $branch,
                    $product,
                    InventoryMovementType::ManualAdjustment,
                    $delta,
                    'Stock correction: '.$reason->label().($data['note'] !== null ? ' — '.$data['note'] : ''),
                    $actor,
                );
            } catch (ValidationException $exception) {
                if (array_key_exists('quantity_delta', $exception->errors())) {
                    throw ValidationException::withMessages(['quantity' => $direction === StockCorrectionDirection::Decrease
                        ? 'The quantity is more than the current stock.'
                        : 'The stock would exceed the supported range.']);
                }
                throw $exception;
            }
            $resultingStock = (int) BranchInventory::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->value('on_hand');

            $adjustment = StoreSessionInventoryAdjustment::query()->create([
                'branch_id' => $branch->id,
                'store_session_id' => $session->id,
                'product_id' => $product->id,
                'inventory_movement_id' => $movement->id,
                'reason_code' => $reason,
                'direction' => $direction,
                'quantity' => $quantity,
                'note' => $data['note'],
                'created_by_user_id' => $actor->id,
                'idempotency_key' => $key,
                'intent_hash' => $intent,
            ]);

            $this->audit->record(
                branch: $branch,
                actor: $actor,
                module: 'inventory',
                action: 'store_session.inventory_adjusted',
                auditableType: StoreSessionInventoryAdjustment::class,
                auditableId: $adjustment->id,
                before: ['on_hand' => $resultingStock - $delta],
                after: ['on_hand' => $resultingStock],
                metadata: [
                    'request_hash' => $intent,
                    'store_session_id' => $session->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'direction' => $direction->value,
                    'quantity' => $quantity,
                    'quantity_delta' => $delta,
                    'reason_code' => $reason->value,
                    'reason_label' => $reason->label(),
                    'note' => $data['note'],
                    'movement_id' => $movement->id,
                ],
                idempotencyKey: $key,
            );
            ReportsChanged::dispatch((string) $branch->id, 'inventory.adjusted');

            return $adjustment->load('product', 'inventoryMovement');
        });
    }
}
