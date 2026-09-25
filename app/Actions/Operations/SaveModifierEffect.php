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
use App\Support\BranchConfiguration;
use App\Support\CatalogRealtime;
use App\Support\ExactQuantity;
use App\Support\OperationsAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Saves the selected Branch's Ingredient effect of one Add-on / Modifier option on one Product (or removes it when no
 * lines remain, which means "No ingredient effect"), using only that Branch's active Ingredients. The effect is
 * Product-specific because Modifier Groups are reusable (Extra Yakult on Lemon Yakult never changes another Product) and
 * Branch-specific (MAIN +1 Yakult, QAVE +2). Only options of an active Add-on / Modifier group (semantic_role null)
 * assigned to the Product qualify; Size options define base recipes and Instructions never move stock. Like recipes,
 * effects apply to future sales only.
 */
class SaveModifierEffect
{
    public function __construct(private OperationsAccess $access, private AuditRecorder $audit, private CatalogRealtime $realtime) {}

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
        $branch = $this->access->configurationBranch($actor);
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

        return DB::transaction(function () use ($actor, $branch, $product, $option, $lines): ?ProductModifierEffect {
            $branch = BranchConfiguration::lock($branch);
            $configuration = BranchProduct::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->lockForUpdate()->first();
            if ($configuration === null) {
                throw ValidationException::withMessages(['product' => $product->name.' is not in the '.$branch->code.' assortment.']);
            }
            $product = Product::query()->whereKey($product->id)->firstOrFail();
            $option = ModifierOption::query()->whereKey($option->id)->with('modifierGroup')->firstOrFail();
            $group = $option->modifierGroup;
            $assigned = $product->modifierGroups()->whereKey($group->id)->exists();
            if (! $assigned || ! $group->is_active || ! $option->is_active || $group->semantic_role !== null) {
                throw ValidationException::withMessages(['option' => 'Choose an active Add-on / Modifier option of '.$product->name.'. Sizes use base recipes and Instructions never use ingredients.']);
            }
            if ($lines !== [] && $configuration->no_recipe_needed) {
                throw ValidationException::withMessages(['product' => $product->name.' is marked No recipe needed at '.$branch->code.'. Choose Use ingredient recipe first.']);
            }
            if ($lines !== [] && $configuration->tracks_inventory) {
                throw ValidationException::withMessages(['product' => $product->name.' deducts Product stock at '.$branch->code.' in Catalog › Inventory. Turn off stock tracking for it at '.$branch->code.' before adding ingredient effects, so one sale never uses both.']);
            }
            $ingredients = Ingredient::query()->where('branch_id', $branch->id)->whereKey(array_keys($lines))->whereNull('archived_at')->pluck('id')->all();
            if (count($ingredients) !== count($lines)) {
                throw ValidationException::withMessages(['lines' => 'Use only active ingredients of '.$branch->code.'.']);
            }

            $effect = ProductModifierEffect::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->where('modifier_option_id', $option->id)->lockForUpdate()->first();
            $before = $effect === null ? [] : ProductModifierEffectLine::query()->where('product_modifier_effect_id', $effect->id)->orderBy('ingredient_id')->get()
                ->mapWithKeys(fn (ProductModifierEffectLine $line): array => [$line->ingredient_id => ExactQuantity::display(ExactQuantity::parse($line->quantity))])->all();

            if ($lines === []) {
                $effect?->delete();
                $effect = null;
            } else {
                $effect ??= ProductModifierEffect::query()->create(['branch_id' => $branch->id, 'product_id' => $product->id, 'modifier_option_id' => $option->id]);
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
                    branch: $branch,
                    actor: $actor,
                    module: 'operations',
                    action: $lines === [] ? 'recipe.modifier_effect_removed' : 'recipe.modifier_effect_saved',
                    auditableType: Product::class,
                    auditableId: $product->id,
                    before: ['branch_code' => $branch->code, 'add_on' => $option->name, 'group' => $group->name, 'lines' => $before],
                    after: ['branch_code' => $branch->code, 'add_on' => $option->name, 'group' => $group->name, 'lines' => $after],
                );
                /** Only a real change invalidates this Branch's catalogs (after commit). */
                $this->realtime->branchConfigurationChanged($branch, 'recipe_changed');
            }

            return $effect;
        });
    }
}
