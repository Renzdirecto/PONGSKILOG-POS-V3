<?php

namespace App\Console\Commands;

use App\Models\Branch;
use App\Models\Product;
use App\Support\RecipeCapacity;
use Database\Seeders\LocalOperationsQaSeeder;
use Illuminate\Console\Command;

/** LOCAL QA / DEVELOPMENT ONLY: ensures the Phase 16E Operations manual-QA dataset. Never resets or wipes data. */
class SeedOperationsQa extends Command
{
    protected $signature = 'operations:seed-qa';

    protected $description = 'LOCAL QA ONLY: ensure the Drinks plan, Lemon drink recipes, add-on effects and MAIN/QAVE ingredient stock (idempotent)';

    public function handle(RecipeCapacity $capacity): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('operations:seed-qa is LOCAL QA / DEVELOPMENT ONLY and refuses to run in '.app()->environment().'.');

            return self::FAILURE;
        }

        $this->call('db:seed', ['--class' => LocalOperationsQaSeeder::class, '--force' => true]);

        $products = Product::query()->whereIn('name', LocalOperationsQaSeeder::PRODUCTS)->orderBy('name')->get();
        $rows = [];
        foreach (Branch::query()->whereIn('code', ['MAIN', 'QAVE'])->orderBy('code')->get() as $branch) {
            $availability = $capacity->catalog($branch, $products->map(fn (Product $product): string => $product->id)->all());
            foreach ($products as $product) {
                $sizes = collect($availability[$product->id]['sizes'] ?? [])->pluck('capacity', 'name');
                $rows[] = [$branch->code, $product->name, $sizes['Small'] ?? '-', $sizes['Medium'] ?? '-', $sizes['Large'] ?? '-'];
            }
        }
        $this->table(['Branch', 'Product', 'Small', 'Medium', 'Large'], $rows);
        $this->info('Operations QA data is ready (LOCAL QA ONLY). Servings above come from RecipeCapacity.');

        return self::SUCCESS;
    }
}
