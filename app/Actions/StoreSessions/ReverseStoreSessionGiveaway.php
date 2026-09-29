<?php

namespace App\Actions\StoreSessions;

use App\Actions\Audit\AuditRecorder;
use App\Actions\Inventory\ApplyInventoryMovement;
use App\Actions\Operations\ApplyIngredientMovement;
use App\Enums\IngredientMovementType;
use App\Enums\InventoryMovementType;
use App\Enums\StoreSessionStatus;
use App\Events\ReportsChanged;
use App\Http\Requests\StoreSessionGiveawayRequest;
use App\Models\Branch;
use App\Models\IngredientMovement;
use App\Models\Product;
use App\Models\StoreSession;
use App\Models\StoreSessionGiveaway;
use App\Models\StoreSessionGiveawayReversal;
use App\Models\User;
use App\Support\CatalogRealtime;
use App\Support\ExactQuantity;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Corrects a mistaken Giveaway with one append-only compensating reversal while its Store Session is still OPEN. It
 * restores exactly the stock the Giveaway recorded (its own Product-stock movement or Ingredient movements, never
 * today's recipe or Add-on effects), once, and creates no revenue, Payment or Store Expense. The Giveaway stays in the
 * history, marked reversed.
 */
class ReverseStoreSessionGiveaway
{
    public function __construct(
        private PosAccess $access,
        private ApplyInventoryMovement $inventory,
        private ApplyIngredientMovement $ingredients,
        private AuditRecorder $audit,
        private CatalogRealtime $realtime,
    ) {}

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Branch $branch, StoreSessionGiveaway $requested, array $input): StoreSessionGiveawayReversal
    {
        $input['reason'] = is_string($input['reason'] ?? null) ? trim($input['reason']) : ($input['reason'] ?? null);
        /** @var array{idempotency_key: string, reason: string} $data */
        $data = Validator::make($input, StoreSessionGiveawayRequest::reversalRules(), StoreSessionGiveawayRequest::giveawayMessages())->validate();
        $key = strtolower($data['idempotency_key']);

        return DB::transaction(function () use ($actor, $branch, $requested, $data, $key): StoreSessionGiveawayReversal {
            $branch = Branch::query()->whereKey($branch->getKey())->sharedLock()->firstOrFail();
            $actor = $this->access->authorize($actor, $branch);
            abort_unless($actor->hasPermission('store_expenses.manage'), 403);
            $session = StoreSession::query()->where('branch_id', $branch->id)->where('status', StoreSessionStatus::Open)->sharedLock()->first();
            if ($session === null) {
                throw ValidationException::withMessages(['store' => 'This Store Session is no longer open.']);
            }
            $giveaway = StoreSessionGiveaway::query()->where('branch_id', $branch->id)->whereKey($requested->getKey())->first();
            abort_if($giveaway === null, 404);
            /** One reversal per Giveaway, whatever the key: serialize on the Giveaway itself. */
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['giveaway-reversal:'.$giveaway->id]);
            }

            $intent = hash('sha256', json_encode([
                'branch_id' => $branch->id,
                'giveaway_id' => $giveaway->id,
                'actor_id' => $actor->id,
                'reason' => $data['reason'],
            ], JSON_THROW_ON_ERROR));
            $existing = StoreSessionGiveawayReversal::query()->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->intent_hash, $intent)) {
                    abort(409, 'This reversal attempt has already been used with different details.');
                }

                return $existing;
            }
            if (StoreSessionGiveawayReversal::query()->where('giveaway_id', $giveaway->id)->exists()) {
                throw ValidationException::withMessages(['giveaway' => 'This giveaway has already been reversed.']);
            }
            if ($giveaway->store_session_id !== $session->id) {
                throw ValidationException::withMessages(['giveaway' => 'A giveaway can only be reversed while its own Store Session is open.']);
            }

            $label = 'Giveaway reversed · '.$data['reason'];
            $movement = null;
            if ($giveaway->inventory_movement_id !== null) {
                $product = Product::query()->whereKey($giveaway->product_id)->firstOrFail();
                try {
                    $movement = $this->inventory->execute($branch, $product, InventoryMovementType::GiveawayReversal, $giveaway->quantity, $label, $actor);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['giveaway' => 'Product stock tracking is off for '.$product->name.' at this branch, so its stock cannot be restored. Turn tracking back on first.']);
                }
            }
            $reversal = StoreSessionGiveawayReversal::query()->create([
                'giveaway_id' => $giveaway->id,
                'branch_id' => $branch->id,
                'store_session_id' => $session->id,
                'inventory_movement_id' => $movement?->id,
                'reason' => $data['reason'],
                'created_by_user_id' => $actor->id,
                'idempotency_key' => $key,
                'intent_hash' => $intent,
            ]);

            /** Exactly the recorded deduction per Ingredient, with its historical Plan and cost, negated. */
            $recorded = IngredientMovement::query()
                ->where('store_session_giveaway_id', $giveaway->id)
                ->where('movement_type', IngredientMovementType::Giveaway->value)
                ->orderBy('ingredient_id')
                ->get(['ingredient_id', 'quantity_delta', 'estimated_cost_cents', 'operation_plan_id']);
            $restored = [];
            if ($recorded->isNotEmpty()) {
                $stocks = $this->ingredients->lock($branch, array_values($recorded->map(fn (IngredientMovement $line): string => $line->ingredient_id)->all()));
                foreach ($recorded as $line) {
                    $restore = $this->ingredients->execute($branch, $line->ingredient_id, IngredientMovementType::GiveawayReversal, -ExactQuantity::parse($line->quantity_delta), [
                        'store_session_giveaway_id' => $giveaway->id,
                        'operation_plan_id' => $line->operation_plan_id,
                        'estimated_cost_cents' => $line->estimated_cost_cents === null ? null : -$line->estimated_cost_cents,
                        'reason' => $label,
                        'created_by_user_id' => $actor->id,
                    ], $stocks->get($line->ingredient_id));
                    $restored[] = ['ingredient_id' => $line->ingredient_id, 'quantity_delta' => ExactQuantity::display(-ExactQuantity::parse($line->quantity_delta)), 'movement_id' => $restore->id];
                }
            }

            $this->audit->record(
                branch: $branch,
                actor: $actor,
                module: 'store_sessions',
                action: 'store_session.giveaway_reversed',
                auditableType: StoreSessionGiveaway::class,
                auditableId: $giveaway->id,
                after: [
                    'reversal_id' => $reversal->id,
                    'store_session_id' => $session->id,
                    'product_name' => $giveaway->product_name_snapshot,
                    'quantity' => $giveaway->quantity,
                    'reason' => $data['reason'],
                ],
                metadata: [
                    'request_hash' => $intent,
                    'inventory_movement_id' => $movement?->id,
                    'ingredient_movements' => $restored,
                ],
                idempotencyKey: $key,
            );
            if ($restored !== []) {
                $this->realtime->ingredientsChanged($branch, 'giveaway_reversal');
            }
            ReportsChanged::dispatch((string) $branch->id, 'giveaway.reversed');

            return $reversal;
        });
    }
}
