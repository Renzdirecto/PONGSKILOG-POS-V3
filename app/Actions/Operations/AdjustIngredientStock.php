<?php

namespace App\Actions\Operations;

use App\Actions\Audit\AuditRecorder;
use App\Enums\IngredientMovementType;
use App\Events\ReportsChanged;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\User;
use App\Support\CatalogRealtime;
use App\Support\ExactQuantity;
use App\Support\OperationsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Wastage and physical count correction for one Ingredient at the selected Branch. The current balance is never
 * edited directly: wastage appends a negative movement, and a count appends (actual count − system stock). A retried
 * request with the same idempotency key returns the original movement and changes nothing.
 */
class AdjustIngredientStock
{
    public const WASTAGE_REASONS = ['Spoiled', 'Spilled or dropped', 'Expired', 'Cracked or damaged', 'Staff drink', 'Other'];

    public const COUNT_REASONS = ['End-of-day count', 'Opening count', 'Found extra', 'Recording error', 'Other'];

    public function __construct(
        private OperationsAccess $access,
        private ApplyIngredientMovement $movements,
        private AuditRecorder $audit,
        private CatalogRealtime $realtime,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'mode' => ['required', Rule::in(['wastage', 'count'])],
            'quantity' => ['required', 'string', 'regex:'.ExactQuantity::INPUT_PATTERN],
            'reason' => ['required', 'string', Rule::in(array_values(array_unique([...self::WASTAGE_REASONS, ...self::COUNT_REASONS])))],
            'note' => ['nullable', 'string', 'max:200'],
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Ingredient $ingredient, array $input): IngredientMovement
    {
        $actor = $this->access->authorize($actor);
        $branch = $this->access->mutableBranch($actor);
        /** Only an Ingredient of the selected Branch; another Branch's Ingredient id is not found here. */
        $this->access->ownedBy($ingredient, $branch);
        $input['note'] = is_string($input['note'] ?? null) && trim($input['note']) !== '' ? trim($input['note']) : null;
        /** @var array{mode: 'wastage'|'count', quantity: string, reason: string, note: string|null, idempotency_key: string} $data */
        $data = Validator::make($input, self::rules(), [
            'quantity.regex' => 'Enter a quantity with no more than four decimal places.',
        ])->validate();
        $key = strtolower($data['idempotency_key']);
        $type = $data['mode'] === 'wastage' ? IngredientMovementType::Wastage : IngredientMovementType::CountCorrection;
        if (! in_array($data['reason'], $data['mode'] === 'wastage' ? self::WASTAGE_REASONS : self::COUNT_REASONS, true)) {
            throw ValidationException::withMessages(['reason' => 'Choose a reason for this adjustment.']);
        }
        $quantity = ExactQuantity::fromInput($data['quantity']);

        return DB::transaction(function () use ($actor, $branch, $ingredient, $data, $key, $type, $quantity): IngredientMovement {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['ingredient-adjust:'.$key]);
            }
            $replay = IngredientMovement::query()->where('idempotency_key', $key)->first();
            if ($replay !== null) {
                if ($replay->branch_id !== $branch->id || $replay->ingredient_id !== $ingredient->id
                    || $replay->movement_type !== $type || $replay->created_by_user_id !== $actor->id) {
                    abort(409, 'This adjustment attempt has already been used with different details.');
                }

                return $replay;
            }
            $branch = $this->movements->lockBranch($branch);
            $ingredient = Ingredient::query()->whereKey($ingredient->id)->whereNull('archived_at')->sharedLock()->firstOrFail();
            $stock = $this->movements->lock($branch, [$ingredient->id])->get($ingredient->id)
                ?? throw new \LogicException('The Ingredient balance could not be locked.');
            $current = ExactQuantity::parse($stock->on_hand);

            if ($type === IngredientMovementType::Wastage) {
                if ($quantity <= 0) {
                    throw ValidationException::withMessages(['quantity' => 'Enter the quantity wasted.']);
                }
                if ($quantity > $current) {
                    throw ValidationException::withMessages(['quantity' => 'Wastage can\'t exceed current stock. Record a count correction instead.']);
                }
                $delta = -$quantity;
            } else {
                $delta = $quantity - $current;
                if ($delta === 0) {
                    throw ValidationException::withMessages(['quantity' => 'The count matches current stock. Nothing to record.']);
                }
            }

            $movement = $this->movements->execute($branch, $ingredient->id, $type, $delta, [
                'reason_code' => $data['reason'],
                'reason' => $data['note'] === null ? $data['reason'] : $data['reason'].' · '.$data['note'],
                'created_by_user_id' => $actor->id,
                'idempotency_key' => $key,
            ], $stock);
            $this->audit->record(
                branch: $branch,
                actor: $actor,
                module: 'operations',
                action: $type === IngredientMovementType::Wastage ? 'ingredient.wastage_recorded' : 'ingredient.count_corrected',
                auditableType: Ingredient::class,
                auditableId: $ingredient->id,
                before: ['on_hand' => ExactQuantity::display($current)],
                after: ['on_hand' => ExactQuantity::display($current + $delta)],
                metadata: [
                    'movement_id' => $movement->id,
                    'quantity_delta' => ExactQuantity::display($delta),
                    'counted' => $type === IngredientMovementType::CountCorrection ? ExactQuantity::display($quantity) : null,
                    'reason' => $data['reason'],
                    'note' => $data['note'],
                ],
            );
            ReportsChanged::dispatch($branch->id, $type === IngredientMovementType::Wastage ? 'ingredients.wastage' : 'ingredients.count_corrected');
            $this->realtime->ingredientsChanged($branch, $type === IngredientMovementType::Wastage ? 'wastage' : 'count_correction');

            return $movement;
        });
    }
}
