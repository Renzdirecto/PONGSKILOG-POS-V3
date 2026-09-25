<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Phase 18 Manual QA refinement #2.1: explicit Branch assortment and Branch-owned Operations configuration.
 *
 * Products, Categories and Modifier Groups/Options stay one global catalog. After this migration:
 *
 * - `branch_products` is explicit assortment membership (no row = not sold at that Branch) and also holds the Branch
 *   recipe mode (`no_recipe_needed`, moved from the global `products` column, which is dropped).
 * - Ingredients, Pamalengke Plans, Recipes, Add-on effects and their Plan links belong to exactly one Branch.
 *
 * Cutover preserves every existing Branch's current behaviour. Each existing Branch gets an explicit membership row for
 * every Product it implicitly sold (existing rows, including unavailable ones, are kept as they are). The oldest Branch
 * keeps the original Ingredient/Plan/Recipe/effect rows; every other Branch receives its own copy through an explicit
 * old → new id map (never matched by name). That Branch's current stock, movements, working list, purchases and Order
 * recipe snapshots are then re-pointed to its own copies, so balances, quantities, costs and commercial history are
 * unchanged and still belong to the correct Branch. From then on each Branch's configuration is independent.
 *
 * `lineage_id` records copy provenance of Ingredients and Plans (its own id when created, the source lineage when
 * copied), so a later setup copy recognizes records it already copied without relying on names.
 */
return new class extends Migration
{
    /** Tables that gain a Branch owner. */
    private const OWNED = ['ingredients', 'operation_plans', 'recipes', 'product_modifier_effects', 'operation_plan_products', 'operation_plan_ingredients'];

    public function up(): void
    {
        Schema::table('branch_products', function (Blueprint $table) {
            $table->boolean('no_recipe_needed')->default(false);
        });
        foreach (self::OWNED as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
                if (in_array($name, ['ingredients', 'operation_plans'], true)) {
                    $table->uuid('lineage_id')->nullable();
                }
            });
        }

        /** The global uniqueness rules are replaced by per-Branch ones below; the Branch copies need them gone first. */
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
        Schema::table('operation_plan_products', function (Blueprint $table) {
            $table->dropUnique(['product_id']);
        });
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'size_key']);
        });
        Schema::table('product_modifier_effects', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'modifier_option_id']);
        });

        $branchIds = array_values(DB::table('branches')->orderBy('created_at')->orderBy('id')->pluck('id')->map(fn ($id): string => (string) $id)->all());
        $this->materializeAssortment($branchIds);
        $this->cutoverOperations($branchIds);

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('no_recipe_needed');
        });

        foreach (self::OWNED as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->uuid('branch_id')->nullable(false)->change();
                if (in_array($name, ['ingredients', 'operation_plans'], true)) {
                    $table->uuid('lineage_id')->nullable(false)->change();
                }
            });
        }

        Schema::table('ingredients', function (Blueprint $table) {
            /** Composite key target so stock, movements and Plan links can only reference an Ingredient of their own Branch. */
            $table->unique(['branch_id', 'id']);
            $table->unique(['branch_id', 'lineage_id']);
        });
        /** Names stay unique per Branch ignoring case (archived included), as SaveIngredient already checks. */
        DB::statement('CREATE UNIQUE INDEX ingredients_branch_name_unique ON ingredients (branch_id, lower(name))');
        Schema::table('operation_plans', function (Blueprint $table) {
            $table->unique(['branch_id', 'id']);
            $table->unique(['branch_id', 'lineage_id']);
            $table->index(['branch_id', 'archived_at', 'name']);
        });
        Schema::table('operation_plan_products', function (Blueprint $table) {
            /** A Product belongs to at most one Plan per Branch (a different Plan at another Branch is fine). */
            $table->unique(['branch_id', 'product_id']);
            $table->index('product_id');
        });
        Schema::table('operation_plan_ingredients', function (Blueprint $table) {
            $table->index(['branch_id', 'ingredient_id']);
        });
        Schema::table('recipes', function (Blueprint $table) {
            $table->unique(['branch_id', 'product_id', 'size_key']);
            $table->index('product_id');
        });
        Schema::table('product_modifier_effects', function (Blueprint $table) {
            $table->unique(['branch_id', 'product_id', 'modifier_option_id'], 'product_modifier_effects_branch_unique');
            $table->index('product_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                ['branch_ingredient_stocks', 'ingredient_id', 'ingredients', 'branch_ingredient_stocks_branch_ingredient_foreign'],
                ['ingredient_movements', 'ingredient_id', 'ingredients', 'ingredient_movements_branch_ingredient_foreign'],
                ['operation_plan_ingredients', 'ingredient_id', 'ingredients', 'operation_plan_ingredients_branch_ingredient_foreign'],
                ['operation_plan_ingredients', 'operation_plan_id', 'operation_plans', 'operation_plan_ingredients_branch_plan_foreign'],
                ['operation_plan_products', 'operation_plan_id', 'operation_plans', 'operation_plan_products_branch_plan_foreign'],
                ['pamamalengke_list_entries', 'operation_plan_id', 'operation_plans', 'pamamalengke_list_entries_branch_plan_foreign'],
                ['pamamalengke_list_entries', 'ingredient_id', 'ingredients', 'pamamalengke_list_entries_branch_ingredient_foreign'],
            ] as [$table, $column, $target, $name]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} FOREIGN KEY (branch_id, {$column}) REFERENCES {$target} (branch_id, id) ON DELETE RESTRICT");
            }
        }
    }

    public function down(): void
    {
        foreach (['ingredients', 'operation_plans', 'recipes', 'product_modifier_effects'] as $name) {
            if (DB::table($name)->exists()) {
                throw new RuntimeException("Branch-owned {$name} exist; they cannot be merged back into one shared set without losing a Branch's configuration.");
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                ['branch_ingredient_stocks', 'branch_ingredient_stocks_branch_ingredient_foreign'],
                ['ingredient_movements', 'ingredient_movements_branch_ingredient_foreign'],
                ['operation_plan_ingredients', 'operation_plan_ingredients_branch_ingredient_foreign'],
                ['operation_plan_ingredients', 'operation_plan_ingredients_branch_plan_foreign'],
                ['operation_plan_products', 'operation_plan_products_branch_plan_foreign'],
                ['pamamalengke_list_entries', 'pamamalengke_list_entries_branch_plan_foreign'],
                ['pamamalengke_list_entries', 'pamamalengke_list_entries_branch_ingredient_foreign'],
            ] as [$table, $name]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT {$name}");
            }
        }
        DB::statement('DROP INDEX ingredients_branch_name_unique');
        Schema::table('product_modifier_effects', function (Blueprint $table) {
            $table->dropUnique('product_modifier_effects_branch_unique');
            $table->dropIndex(['product_id']);
            $table->unique(['product_id', 'modifier_option_id']);
        });
        Schema::table('recipes', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'product_id', 'size_key']);
            $table->dropIndex(['product_id']);
            $table->unique(['product_id', 'size_key']);
        });
        Schema::table('operation_plan_ingredients', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'ingredient_id']);
        });
        Schema::table('operation_plan_products', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'product_id']);
            $table->dropIndex(['product_id']);
            $table->unique('product_id');
        });
        Schema::table('operation_plans', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'id']);
            $table->dropUnique(['branch_id', 'lineage_id']);
            $table->dropIndex(['branch_id', 'archived_at', 'name']);
        });
        Schema::table('ingredients', function (Blueprint $table) {
            $table->dropUnique(['branch_id', 'id']);
            $table->dropUnique(['branch_id', 'lineage_id']);
            $table->unique('name');
        });
        foreach (self::OWNED as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropForeign(['branch_id']);
                $table->dropColumn(in_array($name, ['ingredients', 'operation_plans'], true) ? ['branch_id', 'lineage_id'] : ['branch_id']);
            });
        }
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('no_recipe_needed')->default(false);
        });
        DB::table('products')->whereIn('id', DB::table('branch_products')->where('no_recipe_needed', true)->select('product_id'))
            ->update(['no_recipe_needed' => true]);
        Schema::table('branch_products', function (Blueprint $table) {
            $table->dropColumn('no_recipe_needed');
        });
    }

    /**
     * Every existing Branch implicitly sold every Product without a configuration row, so it gets an explicit default
     * row (default price, available, untracked). Existing rows keep their price, availability, tracking and threshold;
     * a Product the Branch had made unavailable stays unavailable (never re-enabled). The global recipe mode becomes
     * each Branch's recipe mode.
     *
     * @param  list<string>  $branchIds
     */
    private function materializeAssortment(array $branchIds): void
    {
        if ($branchIds !== []) {
            DB::table('products')->orderBy('id')->select(['id', 'no_recipe_needed'])->chunk(200, function ($products) use ($branchIds): void {
                $now = now();
                $rows = [];
                foreach ($products as $product) {
                    foreach ($branchIds as $branchId) {
                        $rows[] = [
                            'id' => (string) Str::uuid(),
                            'branch_id' => $branchId,
                            'product_id' => $product->id,
                            'price_override' => null,
                            'is_available' => true,
                            'tracks_inventory' => false,
                            'low_stock_threshold' => null,
                            'no_recipe_needed' => (bool) $product->no_recipe_needed,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('branch_products')->insertOrIgnore($chunk);
                }
            });
        }
        DB::table('branch_products')
            ->whereIn('product_id', DB::table('products')->where('no_recipe_needed', true)->select('id'))
            ->update(['no_recipe_needed' => true]);
    }

    /**
     * The oldest Branch keeps the original rows; every other Branch gets an identical copy and its own history is
     * re-pointed to it through the explicit id maps.
     *
     * @param  list<string>  $branchIds
     */
    private function cutoverOperations(array $branchIds): void
    {
        $anchor = $branchIds[0] ?? null;
        if ($anchor === null) {
            foreach (['ingredients', 'operation_plans', 'recipes', 'product_modifier_effects'] as $name) {
                if (DB::table($name)->exists()) {
                    throw new RuntimeException("Operations configuration ({$name}) exists without any Branch to own it.");
                }
            }

            return;
        }
        $others = array_slice($branchIds, 1);

        /** @var array<string, array<string, string>> $plans Branch id → old Plan id → that Branch's Plan id */
        $plans = $this->cloneOwned('operation_plans', $anchor, $others, lineage: true);
        /** @var array<string, array<string, string>> $ingredients Branch id → old Ingredient id → that Branch's Ingredient id */
        $ingredients = $this->cloneOwned('ingredients', $anchor, $others, lineage: true);

        DB::table('operation_plan_products')->update(['branch_id' => $anchor]);
        DB::table('operation_plan_ingredients')->update(['branch_id' => $anchor]);
        DB::table('recipes')->update(['branch_id' => $anchor]);
        DB::table('product_modifier_effects')->update(['branch_id' => $anchor]);
        $now = now();

        foreach ($others as $branchId) {
            foreach (DB::table('operation_plan_products')->where('branch_id', $anchor)->orderBy('id')->get() as $row) {
                DB::table('operation_plan_products')->insert([
                    'id' => (string) Str::uuid(),
                    'branch_id' => $branchId,
                    'operation_plan_id' => $plans[$branchId][$row->operation_plan_id],
                    'product_id' => $row->product_id,
                    'created_at' => $row->created_at ?? $now,
                    'updated_at' => $row->updated_at ?? $now,
                ]);
            }
            foreach (DB::table('operation_plan_ingredients')->where('branch_id', $anchor)->orderBy('id')->get() as $row) {
                DB::table('operation_plan_ingredients')->insert([
                    'id' => (string) Str::uuid(),
                    'branch_id' => $branchId,
                    'operation_plan_id' => $plans[$branchId][$row->operation_plan_id],
                    'ingredient_id' => $ingredients[$branchId][$row->ingredient_id],
                    'created_at' => $row->created_at ?? $now,
                    'updated_at' => $row->updated_at ?? $now,
                ]);
            }
            foreach (DB::table('recipes')->where('branch_id', $anchor)->orderBy('id')->get() as $recipe) {
                $recipeId = (string) Str::uuid();
                DB::table('recipes')->insert([...(array) $recipe, 'id' => $recipeId, 'branch_id' => $branchId]);
                foreach (DB::table('recipe_lines')->where('recipe_id', $recipe->id)->orderBy('id')->get() as $line) {
                    DB::table('recipe_lines')->insert([
                        ...(array) $line,
                        'id' => (string) Str::uuid(),
                        'recipe_id' => $recipeId,
                        'ingredient_id' => $ingredients[$branchId][$line->ingredient_id],
                    ]);
                }
            }
            foreach (DB::table('product_modifier_effects')->where('branch_id', $anchor)->orderBy('id')->get() as $effect) {
                $effectId = (string) Str::uuid();
                DB::table('product_modifier_effects')->insert([...(array) $effect, 'id' => $effectId, 'branch_id' => $branchId]);
                foreach (DB::table('product_modifier_effect_lines')->where('product_modifier_effect_id', $effect->id)->orderBy('id')->get() as $line) {
                    DB::table('product_modifier_effect_lines')->insert([
                        ...(array) $line,
                        'id' => (string) Str::uuid(),
                        'product_modifier_effect_id' => $effectId,
                        'ingredient_id' => $ingredients[$branchId][$line->ingredient_id],
                    ]);
                }
            }

            $this->repointHistory($branchId, $plans[$branchId], $ingredients[$branchId]);
        }
    }

    /**
     * Assigns the existing rows to the anchor Branch and inserts one copy per other Branch.
     *
     * @param  list<string>  $others
     * @return array<string, array<string, string>>
     */
    private function cloneOwned(string $table, string $anchor, array $others, bool $lineage): array
    {
        $map = [];
        foreach (DB::table($table)->orderBy('id')->get() as $row) {
            DB::table($table)->where('id', $row->id)->update(['branch_id' => $anchor, ...($lineage ? ['lineage_id' => $row->id] : [])]);
            $map[$anchor][$row->id] = $row->id;
            foreach ($others as $branchId) {
                $copyId = (string) Str::uuid();
                DB::table($table)->insert([
                    ...(array) $row,
                    'id' => $copyId,
                    'branch_id' => $branchId,
                    ...($lineage ? ['lineage_id' => $row->id] : []),
                ]);
                $map[$branchId][$row->id] = $copyId;
            }
        }
        foreach ($others as $branchId) {
            $map[$branchId] ??= [];
        }

        return $map;
    }

    /**
     * Re-points one Branch's physical and historical Operations rows from the shared definitions to that Branch's own
     * copies. Only identities change: quantities, balances, costs, totals and timestamps are untouched.
     *
     * @param  array<string, string>  $plans  old Plan id → this Branch's Plan id
     * @param  array<string, string>  $ingredients  old Ingredient id → this Branch's Ingredient id
     */
    private function repointHistory(string $branchId, array $plans, array $ingredients): void
    {
        $snapshots = DB::table('order_recipe_snapshots')->where('branch_id', $branchId)->select('id');
        $snapshotModifiers = DB::table('order_recipe_snapshot_modifiers')->whereIn('order_recipe_snapshot_id', DB::table('order_recipe_snapshots')->where('branch_id', $branchId)->select('id'))->select('id');
        $purchases = DB::table('pamamalengke_purchases')->where('branch_id', $branchId)->select('id');

        foreach ($ingredients as $old => $new) {
            DB::table('branch_ingredient_stocks')->where('branch_id', $branchId)->where('ingredient_id', $old)->update(['ingredient_id' => $new]);
            DB::table('ingredient_movements')->where('branch_id', $branchId)->where('ingredient_id', $old)->update(['ingredient_id' => $new]);
            DB::table('pamamalengke_list_entries')->where('branch_id', $branchId)->where('ingredient_id', $old)->update(['ingredient_id' => $new]);
            DB::table('pamamalengke_purchase_items')->whereIn('pamamalengke_purchase_id', clone $purchases)->where('ingredient_id', $old)->update(['ingredient_id' => $new]);
            DB::table('order_recipe_snapshot_lines')->whereIn('order_recipe_snapshot_id', clone $snapshots)->where('ingredient_id', $old)->update(['ingredient_id' => $new]);
            DB::table('order_recipe_snapshot_modifier_lines')->whereIn('order_recipe_snapshot_modifier_id', clone $snapshotModifiers)->where('ingredient_id', $old)->update(['ingredient_id' => $new]);
        }
        foreach ($plans as $old => $new) {
            DB::table('order_recipe_snapshots')->where('branch_id', $branchId)->where('operation_plan_id', $old)->update(['operation_plan_id' => $new]);
            DB::table('ingredient_movements')->where('branch_id', $branchId)->where('operation_plan_id', $old)->update(['operation_plan_id' => $new]);
            DB::table('pamamalengke_purchases')->where('branch_id', $branchId)->where('operation_plan_id', $old)->update(['operation_plan_id' => $new]);
            DB::table('pamamalengke_list_entries')->where('branch_id', $branchId)->where('operation_plan_id', $old)->update(['operation_plan_id' => $new]);
        }
        /** A Giveaway's recipe basis names Ingredient ids (UUIDs, so a plain replacement cannot hit anything else). */
        if ($ingredients !== []) {
            foreach (DB::table('store_session_giveaways')->where('branch_id', $branchId)->whereNotNull('stock_basis')->orderBy('id')->get(['id', 'stock_basis']) as $giveaway) {
                $basis = strtr((string) $giveaway->stock_basis, $ingredients);
                if ($basis !== $giveaway->stock_basis) {
                    DB::table('store_session_giveaways')->where('id', $giveaway->id)->update(['stock_basis' => $basis]);
                }
            }
        }
    }
};
