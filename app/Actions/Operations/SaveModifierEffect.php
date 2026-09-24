<?php

namespace App\Actions\Operations;

use App\Actions\Audit\AuditRecorder;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\ModifierOption;
use App\Models\Product;
use App\Models\ProductModifierEffect;
use App\Models\ProductModifierEffectLine;
use App\Models\User;
use App\Support\ExactQuantity;
use App\Support\OperationsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Saves the Ingredient effect of one Add-on / Modifier option on one Product (or removes it when no lines remain, which
 * means "No ingredient effect"). The effect is Product-specific because Modifier Groups are reusable: Extra Yakult on
 * Lemon Yakult never changes the stock used by another Product. Only options of an active Add-on / Modifier group
 * (semantic_role null) assigned to the Product qualify; Size options define base recipes and Instructions never move
 * stock. Like recipes, effects apply to future sales only.
 */
class SaveModifierEffect
{
    public function __construct(private OperationsAccess $access, private AuditRecorder $audit) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'lines' => ['present', 'array', 'max:20'],
            'lines.*.ingredient_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'string', 'regex:'.ExactQuantity::INPUT_PATTERN],
        ];
    }

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Product $product, ModifierOption $option, array $input): ?ProductModifierEffect
    {
        $actor = $this->access->authorize($actor);
        /** @var array{lines: list<array{ingredient_id: string, quantity: string}>} $data */
        $data = Validator::make($input, self::rules(), [
            'lines.*.quantity.regex' => 'Enter a quantity above zero with no more than four decimal places.',
            'lines.*.ingredient_id.distinct' => 'Each ingredient can appear once in an add-on effect.',
        ])->validate();
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $quantity = ExactQuantity::fromInput($line['quantity'], "lines.{$index}.quantity");
            if ($quantity <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Enter a quantity above zero.']);
            }
            $lines[strtolower($line['ingredient_id'])] = $quantity;
        }
        ksort($lines);

        return DB::transaction(function () use ($actor, $product, $option, $lines): ?ProductModifierEffect {
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $option = ModifierOption::query()->whereKey($option->id)->with('modifierGroup')->firstOrFail();
            $group = $option->modifierGroup;
            $assigned = $product->modifierGroups()->whereKey($group->id)->exists();
            if (! $assigned || ! $group->is_active || ! $option->is_active || $group->semantic_role !== null) {
                throw ValidationException::withMessages(['option' => 'Choose an active Add-on / Modifier option of '.$product->name.'. Sizes use base recipes and Instructions never use ingredients.']);
            }
            if ($lines !== [] && $product->no_recipe_needed) {
                throw ValidationException::withMessages(['product' => $product->name.' is marked No recipe needed. Choose Use ingredient recipe first.']);
            }
            if ($lines !== [] && BranchProduct::query()->where('product_id', $product->id)->where('tracks_inventory', true)->exists()) {
                throw ValidationException::withMessages(['product' => $product->name.' deducts Product stock in Catalog › Inventory. Turn off stock tracking for it before adding ingredient effects, so one sale never uses both.']);
            }
            $ingredients = Ingredient::query()->whereKey(array_keys($lines))->whereNull('archived_at')->pluck('id')->all();
            if (count($ingredients) !== count($lines)) {
                throw ValidationException::withMessages(['lines' => 'Use only active ingredients.']);
            }

            $effect = ProductModifierEffect::query()->where('product_id', $product->id)->where('modifier_option_id', $option->id)->lockForUpdate()->first();
            $before = $effect === null ? [] : ProductModifierEffectLine::query()->where('product_modifier_effect_id', $effect->id)->orderBy('ingredient_id')->get()
                ->mapWithKeys(fn (ProductModifierEffectLine $line): array => [$line->ingredient_id => ExactQuantity::display(ExactQuantity::parse($line->quantity))])->all();

            if ($lines === []) {
                $effect?->delete();
                $effect = null;
            } else {
                $effect ??= ProductModifierEffect::query()->create(['product_id' => $product->id, 'modifier_option_id' => $option->id]);
                $effect->update(['updated_by_user_id' => $actor->id]);
                $effect->touch();
                ProductModifierEffectLine::query()->where('product_modifier_effect_id', $effect->id)->delete();
                foreach ($lines as $ingredientId => $quantity) {
                    ProductModifierEffectLine::query()->create(['product_modifier_effect_id' => $effect->id, 'ingredient_id' => $ingredientId, 'quantity' => ExactQuantity::decimal($quantity)]);
                }
            }

            $after = array_map(fn (int $quantity): string => ExactQuantity::display($quantity), $lines);
            if ($before !== $after) {
                $this->audit->record(
                    branch: null,
                    actor: $actor,
                    module: 'operations',
                    action: $lines === [] ? 'recipe.modifier_effect_removed' : 'recipe.modifier_effect_saved',
                    auditableType: Product::class,
                    auditableId: $product->id,
                    before: ['add_on' => $option->name, 'group' => $group->name, 'lines' => $before],
                    after: ['add_on' => $option->name, 'group' => $group->name, 'lines' => $after],
                );
            }

            return $effect;
        });
    }
}
