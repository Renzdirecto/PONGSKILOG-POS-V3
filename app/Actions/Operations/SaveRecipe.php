<?php

namespace App\Actions\Operations;

use App\Actions\Audit\AuditRecorder;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\User;
use App\Support\ExactQuantity;
use App\Support\OperationsAccess;
use App\Support\ProductSizes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Saves the recipe of one existing Catalog Product size (or removes it when no lines remain). Recipes apply to future
 * sales only: every committed sale keeps its own Order recipe snapshot, so editing a recipe never changes history.
 * A Product that deducts Product stock at any Branch, or is marked No recipe needed, cannot get a recipe, so one sale
 * never consumes both Product and Ingredient stock.
 */
class SaveRecipe
{
    public function __construct(private OperationsAccess $access, private ProductSizes $sizes, private AuditRecorder $audit) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'size_option_id' => ['nullable', 'uuid'],
            'lines' => ['present', 'array', 'max:50'],
            'lines.*.ingredient_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'string', 'regex:'.ExactQuantity::INPUT_PATTERN],
        ];
    }

    /** @param array<string, mixed> $input */
    public function execute(User $actor, Product $product, array $input): ?Recipe
    {
        $actor = $this->access->authorize($actor);
        /** @var array{size_option_id: string|null, lines: list<array{ingredient_id: string, quantity: string}>} $data */
        $data = Validator::make($input, self::rules(), [
            'lines.*.quantity.regex' => 'Enter a quantity above zero with no more than four decimal places.',
            'lines.*.ingredient_id.distinct' => 'Each ingredient can appear once in a recipe.',
        ])->validate();
        $sizeOptionId = isset($data['size_option_id']) ? strtolower($data['size_option_id']) : null;
        $lines = [];
        foreach ($data['lines'] as $index => $line) {
            $quantity = ExactQuantity::fromInput($line['quantity'], "lines.{$index}.quantity");
            if ($quantity <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'Enter a quantity above zero.']);
            }
            $lines[strtolower($line['ingredient_id'])] = $quantity;
        }
        ksort($lines);

        return DB::transaction(function () use ($actor, $product, $sizeOptionId, $lines): ?Recipe {
            $product = Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $conflict = $this->sizes->conflicts([$product->id])[$product->id] ?? null;
            if ($conflict !== null) {
                throw ValidationException::withMessages(['size_option_id' => ProductSizes::conflictMessage($product->name, $conflict)]);
            }
            $sizes = $this->sizes->forProduct($product);
            $size = collect($sizes)->firstWhere('option_id', $sizeOptionId);
            if ($size === null) {
                throw ValidationException::withMessages(['size_option_id' => 'Choose one of this product\'s existing sizes.']);
            }
            if ($lines !== [] && $product->no_recipe_needed) {
                throw ValidationException::withMessages(['product' => $product->name.' is marked No recipe needed. Choose Add a recipe instead first.']);
            }
            if ($lines !== [] && BranchProduct::query()->where('product_id', $product->id)->where('tracks_inventory', true)->exists()) {
                throw ValidationException::withMessages(['product' => $product->name.' deducts Product stock in Catalog › Inventory. Turn off stock tracking for it before adding a recipe, so one sale never uses both.']);
            }
            $ingredients = Ingredient::query()->whereKey(array_keys($lines))->whereNull('archived_at')->pluck('id')->all();
            if (count($ingredients) !== count($lines)) {
                throw ValidationException::withMessages(['lines' => 'Use only active ingredients.']);
            }

            $key = Recipe::sizeKey($sizeOptionId);
            $recipe = Recipe::query()->where('product_id', $product->id)->where('size_key', $key)->lockForUpdate()->first();
            $before = $recipe === null ? [] : RecipeLine::query()->where('recipe_id', $recipe->id)->orderBy('ingredient_id')->get()
                ->mapWithKeys(fn (RecipeLine $line): array => [$line->ingredient_id => ExactQuantity::display(ExactQuantity::parse($line->quantity))])->all();

            if ($lines === []) {
                $recipe?->delete();
                $recipe = null;
            } else {
                $recipe ??= Recipe::query()->create(['product_id' => $product->id, 'size_modifier_option_id' => $sizeOptionId, 'size_key' => $key]);
                $recipe->update(['updated_by_user_id' => $actor->id]);
                $recipe->touch();
                RecipeLine::query()->where('recipe_id', $recipe->id)->delete();
                foreach ($lines as $ingredientId => $quantity) {
                    RecipeLine::query()->create(['recipe_id' => $recipe->id, 'ingredient_id' => $ingredientId, 'quantity' => ExactQuantity::decimal($quantity)]);
                }
            }

            $after = array_map(fn (int $quantity): string => ExactQuantity::display($quantity), $lines);
            if ($before !== $after) {
                $this->audit->record(
                    branch: null,
                    actor: $actor,
                    module: 'operations',
                    action: $lines === [] ? 'recipe.removed' : 'recipe.saved',
                    auditableType: Product::class,
                    auditableId: $product->id,
                    before: ['size' => $size['name'], 'lines' => $before],
                    after: ['size' => $size['name'], 'lines' => $after],
                );
            }

            return $recipe;
        });
    }
}
