<?php

namespace App\Actions\Operations;

use App\Actions\Audit\AuditRecorder;
use App\Actions\StoreSessions\RecordStoreSessionExpense;
use App\Enums\IngredientMovementType;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\Ingredient;
use App\Models\OperationPlan;
use App\Models\PamamalengkeListEntry;
use App\Models\PamamalengkePurchase;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\User;
use App\Support\CatalogRealtime;
use App\Support\ExactMoney;
use App\Support\ExactQuantity;
use App\Support\OperationsAccess;
use App\Support\ReplenishmentAdvisor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Confirms one pamamalengke run for the selected Branch and Plan, atomically:
 *
 * 1. writes ONE canonical Store Purchase / Expense through RecordStoreSessionExpense::persist(), under the same
 *    OPEN Store Session rule and shared session lock as every Store expense (no alternate expense path);
 * 2. appends one exact base-unit restock movement per bought Ingredient (actual quantity × purchase-unit size);
 * 3. saves recommended vs actual quantities and costs as purchase metadata linked to that expense;
 * 4. updates each Ingredient's latest purchase-unit cost for future estimates only (past cost snapshots never move);
 * 5. clears the confirmed manual items and skip marks, and audits the run.
 *
 * Manual items share the expense but never touch Ingredient stock. A retry with the same idempotency key returns the
 * original run and writes nothing; a different payload under that key is rejected.
 */
class ConfirmPamamalengke
{
    public function __construct(
        private OperationsAccess $access,
        private RecordStoreSessionExpense $expenses,
        private ApplyIngredientMovement $movements,
        private ReplenishmentAdvisor $advisor,
        private AuditRecorder $audit,
        private CatalogRealtime $realtime,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        $quantity = ['string', 'regex:'.ExactQuantity::INPUT_PATTERN];
        $money = ['string', 'regex:/\A[0-9]{1,9}(?:\.[0-9]{1,2})?\z/'];

        return [
            'idempotency_key' => ['required', 'uuid'],
            'payment_source' => ['required', Rule::in(['cash', 'cashless'])],
            'note' => ['nullable', 'string', 'max:200'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.type' => ['required', Rule::in(['ingredient', 'manual'])],
            'items.*.ingredient_id' => ['nullable', 'required_if:items.*.type,ingredient', 'uuid'],
            'items.*.entry_id' => ['nullable', 'uuid'],
            'items.*.name' => ['nullable', 'required_if:items.*.type,manual', 'string', 'max:80'],
            'items.*.unit' => ['nullable', 'required_if:items.*.type,manual', 'string', 'max:30'],
            'items.*.actual_quantity' => ['required', ...$quantity],
            'items.*.actual_unit_cost' => ['required', ...$money],
            'items.*.note' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** @param array<string, mixed> $input */
    public function execute(User $actor, OperationPlan $plan, array $input): PamamalengkePurchase
    {
        $actor = $this->access->authorize($actor);
        $branch = $this->access->mutableBranch($actor);
        $input['note'] = is_string($input['note'] ?? null) && trim($input['note']) !== '' ? trim($input['note']) : null;
        /** @var array{idempotency_key: string, payment_source: string, note: string|null, items: list<array{type: string, ingredient_id?: string|null, entry_id?: string|null, name?: string|null, unit?: string|null, actual_quantity: string, actual_unit_cost: string, note?: string|null}>} $data */
        $data = Validator::make($input, self::rules(), [
            'items.required' => 'Mark at least one item as bought.',
            'items.*.actual_quantity.regex' => 'Enter a quantity with no more than four decimal places.',
            'items.*.actual_unit_cost.regex' => 'Enter a unit cost with no more than two decimal places.',
            'items.*.actual_unit_cost.required' => 'Enter the unit cost of every bought item.',
        ])->validate();
        $key = strtolower($data['idempotency_key']);
        $lines = $this->normalize($data['items']);
        $intent = hash('sha256', json_encode([
            'branch_id' => $branch->id,
            'plan_id' => $plan->id,
            'actor_id' => $actor->id,
            'payment_source' => $data['payment_source'],
            'note' => $data['note'],
            'items' => $lines,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $branch, $plan, $data, $key, $lines, $intent): PamamalengkePurchase {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['pamamalengke:'.$key]);
            }
            $replay = PamamalengkePurchase::query()->where('idempotency_key', $key)->first();
            if ($replay !== null) {
                if (! hash_equals($replay->intent_hash, $intent)) {
                    abort(409, 'This pamamalengke confirmation has already been used with different details.');
                }

                return $replay->load('items');
            }
            if (StoreSessionExpense::query()->where('idempotency_key', $key)->exists()) {
                abort(409, 'This confirmation key belongs to another Store Purchase.');
            }
            $plan = OperationPlan::query()->whereKey($plan->id)->whereNull('archived_at')->sharedLock()->first()
                ?? throw ValidationException::withMessages(['plan' => 'This Plan is archived.']);
            /** Branch → Store Session → balances: the same order as POS commits; Close Store takes the session exclusively. */
            $branch = $this->movements->lockBranch($branch);
            $session = StoreSession::query()->where('branch_id', $branch->id)->where('status', StoreSessionStatus::Open)->sharedLock()->first();
            if ($session === null) {
                throw ValidationException::withMessages(['store' => 'Open the Store at '.$branch->name.' first. A pamamalengke purchase is saved as a Store Purchase of the open Store Session.']);
            }

            $priced = $this->price($branch, $plan, $lines);
            $total = array_sum(array_column($priced, 'line_cents'));
            if ($total <= 0) {
                throw ValidationException::withMessages(['items' => 'The actual total must be more than ₱0.00 to save a Store Purchase.']);
            }
            $estimateKnown = ! in_array(null, array_column($priced, 'estimate_cents'), true);
            $estimate = array_sum(array_map(fn (array $line): int => $line['estimate_cents'] ?? 0, $priced));

            $expense = $this->expenses->persist($branch, $session, $actor, [
                'description' => mb_substr('Pamamalengke · '.$plan->name.' plan', 0, 150),
                'amount' => ExactMoney::decimal($total),
                'payment_source' => $data['payment_source'],
                'note' => $data['note'] ?? count($priced).' item'.(count($priced) === 1 ? '' : 's').' from the pamamalengke checklist',
                'idempotency_key' => $key,
                'intent_hash' => $intent,
            ], auditDetails: ['source' => 'pamamalengke', 'operation_plan_id' => $plan->id]);

            $purchase = PamamalengkePurchase::query()->create([
                'branch_id' => $branch->id,
                'store_session_id' => $session->id,
                'operation_plan_id' => $plan->id,
                'store_session_expense_id' => $expense->id,
                'estimated_total' => ExactMoney::decimal($estimate),
                'estimate_complete' => $estimateKnown,
                'note' => $data['note'],
                'created_by_user_id' => $actor->id,
                'idempotency_key' => $key,
                'intent_hash' => $intent,
            ]);

            $ingredientIds = array_values(array_filter(array_column($priced, 'ingredient_id')));
            $stocks = $this->movements->lock($branch, $ingredientIds);
            $restocks = [];
            $costUpdates = [];
            foreach ($priced as $line) {
                $movement = null;
                if ($line['ingredient'] !== null) {
                    $movement = $this->movements->execute($branch, $line['ingredient']->id, IngredientMovementType::PurchaseRestock, $line['base_quantity'], [
                        'operation_plan_id' => $plan->id,
                        'pamamalengke_purchase_id' => $purchase->id,
                        'store_session_expense_id' => $expense->id,
                        'reason' => 'Pamamalengke · '.$plan->name.' plan',
                        'created_by_user_id' => $actor->id,
                    ], $stocks->get($line['ingredient']->id));
                    $restocks[] = ['ingredient_id' => $line['ingredient']->id, 'base_quantity' => ExactQuantity::display($line['base_quantity']), 'movement_id' => $movement->id];
                    $previous = $line['ingredient']->purchase_unit_cost === null ? null : ExactMoney::cents((string) $line['ingredient']->purchase_unit_cost);
                    if ($previous !== $line['unit_cents']) {
                        $line['ingredient']->update(['purchase_unit_cost' => ExactMoney::decimal($line['unit_cents']), 'updated_by_user_id' => $actor->id]);
                        $costUpdates[] = ['ingredient_id' => $line['ingredient']->id, 'from' => $previous === null ? null : ExactMoney::decimal($previous), 'to' => ExactMoney::decimal($line['unit_cents'])];
                    }
                }
                $purchase->items()->create([
                    'line_type' => $line['type'],
                    'ingredient_id' => $line['ingredient']?->id,
                    'name_snapshot' => $line['name'],
                    'unit_label' => $line['unit'],
                    'was_recommended' => $line['recommended'] !== null,
                    'recommended_quantity' => $line['recommended'] === null ? null : ExactQuantity::decimal($line['recommended']),
                    'actual_quantity' => ExactQuantity::decimal($line['quantity']),
                    'estimated_unit_cost' => $line['estimate_unit_cents'] === null ? null : ExactMoney::decimal($line['estimate_unit_cents']),
                    'actual_unit_cost' => ExactMoney::decimal($line['unit_cents']),
                    'line_total' => ExactMoney::decimal($line['line_cents']),
                    'purchase_unit_size' => $line['size'] === null ? null : ExactQuantity::decimal($line['size']),
                    'base_quantity' => $line['ingredient'] === null ? null : ExactQuantity::decimal($line['base_quantity']),
                    'ingredient_movement_id' => $movement?->id,
                    'note' => $line['note'],
                ]);
            }

            PamamalengkeListEntry::query()->where('branch_id', $branch->id)->where('operation_plan_id', $plan->id)
                ->where(fn ($query) => $query->where('entry_type', 'skip')
                    ->orWhereIn('id', array_values(array_filter(array_column($priced, 'entry_id')))))
                ->delete();

            $this->audit->record(
                branch: $branch,
                actor: $actor,
                module: 'operations',
                action: 'pamamalengke.confirmed',
                auditableType: PamamalengkePurchase::class,
                auditableId: $purchase->id,
                after: [
                    'operation_plan_id' => $plan->id,
                    'store_session_expense_id' => $expense->id,
                    'actual_total' => ExactMoney::decimal($total),
                    'estimated_total' => $estimateKnown ? ExactMoney::decimal($estimate) : null,
                    'payment_source' => $data['payment_source'],
                    'items' => count($priced),
                ],
                metadata: ['restocks' => $restocks, 'cost_updates' => $costUpdates],
            );

            if ($restocks !== []) {
                $this->realtime->ingredientsChanged($branch, 'purchase_restock');
            }

            return $purchase->load('items');
        }, attempts: 3);
    }

    /**
     * @param  list<array{type: string, ingredient_id?: string|null, entry_id?: string|null, name?: string|null, unit?: string|null, actual_quantity: string, actual_unit_cost: string, note?: string|null}>  $items
     * @return list<array{type: string, ingredient_id: string|null, entry_id: string|null, name: string|null, unit: string|null, quantity: int, unit_cents: int, note: string|null}>
     */
    private function normalize(array $items): array
    {
        $seen = [];
        $lines = [];
        foreach ($items as $index => $item) {
            $quantity = ExactQuantity::fromInput($item['actual_quantity'], "items.{$index}.actual_quantity");
            if ($quantity <= 0) {
                throw ValidationException::withMessages(["items.{$index}.actual_quantity" => 'Enter the quantity bought.']);
            }
            $ingredientId = $item['type'] === 'ingredient' ? strtolower((string) ($item['ingredient_id'] ?? '')) : null;
            if ($ingredientId !== null) {
                if (isset($seen[$ingredientId])) {
                    throw ValidationException::withMessages(["items.{$index}.ingredient_id" => 'Each ingredient can appear once in a run.']);
                }
                $seen[$ingredientId] = true;
            }
            $trim = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
            $lines[] = [
                'type' => $item['type'],
                'ingredient_id' => $ingredientId,
                'entry_id' => $item['type'] === 'manual' && isset($item['entry_id']) ? strtolower((string) $item['entry_id']) : null,
                'name' => $item['type'] === 'manual' ? $trim($item['name'] ?? null) : null,
                'unit' => $item['type'] === 'manual' ? $trim($item['unit'] ?? null) : null,
                'quantity' => $quantity,
                'unit_cents' => ExactMoney::cents($item['actual_unit_cost']),
                'note' => $trim($item['note'] ?? null),
            ];
        }

        return $lines;
    }

    /**
     * Resolves every line against server state: Ingredient purchase units, the server-side recommendation, the manual
     * list entry's estimate, the exact base quantity and the rounded line total.
     *
     * @param  list<array{type: string, ingredient_id: string|null, entry_id: string|null, name: string|null, unit: string|null, quantity: int, unit_cents: int, note: string|null}>  $lines
     * @return list<array{type: string, ingredient: Ingredient|null, ingredient_id: string|null, entry_id: string|null, name: string, unit: string, quantity: int, unit_cents: int, line_cents: int, size: int|null, base_quantity: int, recommended: int|null, estimate_unit_cents: int|null, estimate_cents: int|null, note: string|null}>
     */
    private function price(Branch $branch, OperationPlan $plan, array $lines): array
    {
        $ingredientIds = array_values(array_filter(array_column($lines, 'ingredient_id')));
        /** FOR NO KEY UPDATE serializes definition edits without blocking sales' foreign-key KEY SHARE checks. */
        $ingredients = Ingredient::query()->whereKey($ingredientIds)->whereNull('archived_at')->orderBy('id')->lock('for no key update')->get()->keyBy('id');
        if ($ingredients->count() !== count($ingredientIds)) {
            throw ValidationException::withMessages(['items' => 'Use only active ingredients.']);
        }
        $stocks = BranchIngredientStock::query()->where('branch_id', $branch->id)->whereIn('ingredient_id', $ingredientIds)->pluck('on_hand', 'ingredient_id');
        $entryIds = array_values(array_filter(array_column($lines, 'entry_id')));
        $entries = PamamalengkeListEntry::query()->whereKey($entryIds)->where('branch_id', $branch->id)
            ->where('operation_plan_id', $plan->id)->where('entry_type', 'manual')->get()->keyBy('id');
        if ($entries->count() !== count(array_unique($entryIds))) {
            throw ValidationException::withMessages(['items' => 'A manual item is no longer on this list. Refresh and try again.']);
        }

        return array_map(function (array $line) use ($ingredients, $stocks, $entries): array {
            $lineCents = ExactQuantity::lineCents($line['quantity'], $line['unit_cents']);
            if ($line['ingredient_id'] !== null) {
                /** @var Ingredient $ingredient */
                $ingredient = $ingredients->get($line['ingredient_id']);
                $size = ExactQuantity::parseNullable($ingredient->purchase_unit_size);
                if ($size === null || $size <= 0 || trim((string) $ingredient->purchase_unit_name) === '') {
                    throw ValidationException::withMessages(['items' => $ingredient->name.' needs a purchase unit before it can be restocked.']);
                }
                $recommendation = $this->advisor->recommend($ingredient, ExactQuantity::parse($stocks->get($ingredient->id)));
                $recommended = $recommendation['kind'] === 'buy' ? $recommendation['units'] * ExactQuantity::FACTOR : null;
                $estimateUnit = $ingredient->purchase_unit_cost === null ? null : ExactMoney::cents((string) $ingredient->purchase_unit_cost);

                return [
                    ...$line,
                    'ingredient' => $ingredient,
                    'name' => $ingredient->name,
                    'unit' => (string) $ingredient->purchase_unit_name,
                    'line_cents' => $lineCents,
                    'size' => $size,
                    'base_quantity' => ExactQuantity::multiply($line['quantity'], $size),
                    'recommended' => $recommended,
                    'estimate_unit_cents' => $estimateUnit,
                    'estimate_cents' => $recommended === null || $estimateUnit === null ? null : ExactQuantity::lineCents($recommended, $estimateUnit),
                ];
            }
            $entry = $line['entry_id'] === null ? null : $entries->get($line['entry_id']);
            $recommended = $entry === null ? null : ExactQuantity::parse($entry->quantity);
            $estimateUnit = $entry?->estimated_unit_cost === null ? null : ExactMoney::cents((string) $entry->estimated_unit_cost);

            return [
                ...$line,
                'ingredient' => null,
                'name' => $line['name'] ?? (string) $entry?->name,
                'unit' => $line['unit'] ?? (string) $entry?->unit,
                'line_cents' => $lineCents,
                'size' => null,
                'base_quantity' => 0,
                'recommended' => $recommended,
                'estimate_unit_cents' => $estimateUnit,
                'estimate_cents' => $recommended === null || $estimateUnit === null ? null : ExactQuantity::lineCents($recommended, $estimateUnit),
            ];
        }, $lines);
    }
}
