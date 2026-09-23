<?php

namespace App\Actions\StoreSessions;

use App\Actions\Audit\AuditRecorder;
use App\Actions\Inventory\ApplyInventoryMovement;
use App\Enums\InventoryMovementType;
use App\Enums\StoreInventoryAdjustmentReason;
use App\Enums\StoreSessionStatus;
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
 * Inventory-only Store Session deduction (complimentary, wastage, damaged, staff meal, other).
 * It never creates a Store Expense or Payment, so Store Close reconciliation is unaffected.
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
        /** @var array{idempotency_key: string, reason_code: string, product_id: string, quantity: int|string, note: string|null} $data */
        $data = Validator::make($input, StoreSessionInventoryAdjustmentRequest::adjustmentRules(), StoreSessionInventoryAdjustmentRequest::adjustmentMessages())->validate();
        $key = strtolower($data['idempotency_key']);
        $reason = StoreInventoryAdjustmentReason::from($data['reason_code']);
        $quantity = (int) $data['quantity'];

        return DB::transaction(function () use ($actor, $branch, $data, $key, $reason, $quantity): StoreSessionInventoryAdjustment {
            $branch = Branch::query()->whereKey($branch->getKey())->firstOrFail();
            $actor = $this->access->authorize($actor, $branch);
            abort_unless($actor->hasPermission('store_expenses.manage'), 403);

            /** Shared Session boundary: Store Close takes it exclusively, so no adjustment crosses a close. */
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
                'reason_code' => $reason->value,
                'product_id' => strtolower($data['product_id']),
                'quantity' => $quantity,
                'note' => $data['note'],
            ], JSON_THROW_ON_ERROR));

            $existing = StoreSessionInventoryAdjustment::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->intent_hash, $intent)) {
                    abort(409, 'This adjustment attempt has already been used with different details.');
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

            /** ApplyInventoryMovement re-reads and locks the authoritative balance and rejects negative stock. */
            try {
                $movement = $this->inventory->execute(
                    $branch,
                    $product,
                    InventoryMovementType::ManualAdjustment,
                    -$quantity,
                    'Store Session adjustment: '.$reason->label().($data['note'] !== null ? ' — '.$data['note'] : ''),
                    $actor,
                );
            } catch (ValidationException $exception) {
                if (array_key_exists('quantity_delta', $exception->errors())) {
                    throw ValidationException::withMessages(['quantity' => 'The quantity is more than the current stock.']);
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
                before: ['on_hand' => $resultingStock + $quantity],
                after: ['on_hand' => $resultingStock],
                metadata: [
                    'request_hash' => $intent,
                    'store_session_id' => $session->id,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'quantity_deducted' => $quantity,
                    'reason_code' => $reason->value,
                    'reason_label' => $reason->label(),
                    'note' => $data['note'],
                    'movement_id' => $movement->id,
                ],
                idempotencyKey: $key,
            );

            return $adjustment->load('product', 'inventoryMovement');
        });
    }
}
