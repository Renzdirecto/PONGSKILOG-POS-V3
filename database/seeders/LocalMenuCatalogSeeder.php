<?php

namespace Database\Seeders;

use App\Actions\Catalog\CreateCategory;
use App\Actions\Catalog\CreateProduct;
use App\Actions\Inventory\ApplyInventoryMovement;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class LocalMenuCatalogSeeder extends Seeder
{
    /**
     * Product names come from unambiguous public/images/menu filenames. Matching
     * standalone prices are reused; the remaining values are local QA samples.
     *
     * @var array<string, array{sort_order: int, products: array<string, string>}>
     */
    private const CATALOG = [
        'Menu' => [
            'sort_order' => 0,
            'products' => [
                'Bangus' => '110.00',
                'Beef Ampalaya' => '120.00',
                'Beef Broccoli' => '120.00',
                'Beef Pares' => '120.00',
                'Beef Pares Only' => '90.00',
                'Bulalo' => '140.00',
                'Burger Steak' => '95.00',
                'Chicken' => '100.00',
                'Crystal' => '25.00',
                'Egg' => '20.00',
                'Half Rice' => '15.00',
                'Hotdog' => '30.00',
                'Hungarian' => '55.00',
                'Letchon Kawali' => '130.00',
                'Letchon Pares' => '130.00',
                'Letchon Pares Only' => '105.00',
                'Liempo' => '110.00',
                'Lomi' => '90.00',
                'Maling' => '45.00',
                'Mami' => '85.00',
                'Miki Bihon' => '95.00',
                'Miki Guisado' => '95.00',
                'Pansit Bihon' => '95.00',
                'Pansit Canton' => '95.00',
                'Porkchop' => '100.00',
                'Rice' => '25.00',
                'Shanghai' => '60.00',
                'Siomai' => '55.00',
                'Siopao' => '45.00',
                'Sisig' => '120.00',
                'Tokwat Baboy' => '80.00',
            ],
        ],
        'Silog' => [
            'sort_order' => 1,
            'products' => [
                'Bangsilog' => '110.00',
                'Chickfilletsilog' => '100.00',
                'Chicksilog' => '100.00',
                'Embusilog' => '90.00',
                'Hamsilog' => '85.00',
                'Hotsilog' => '85.00',
                'Hungsilog' => '105.00',
                'Liemposilog' => '110.00',
                'Longsilog' => '90.00',
                'Malingsilog' => '90.00',
                'Mixsilog' => '120.00',
                'Nuggetsilog' => '90.00',
                'Porksilog' => '95.00',
                'Shanghaisilog' => '90.00',
                'Siomaisilog' => '90.00',
                'Tapsilog' => '95.00',
                'Tocilog' => '95.00',
            ],
        ],
        'Lemon' => [
            'sort_order' => 2,
            'products' => [
                'Lemon Calamansi' => '45.00',
                'Lemon Cola' => '45.00',
                'Lemon Pure' => '50.00',
                'Lemon Yakult' => '55.00',
            ],
        ],
    ];

    /** @var array<string, int> */
    private const INITIAL_STOCK = [
        'MAIN' => 50,
        'QAVE' => 30,
    ];

    private const LOW_STOCK_THRESHOLD = 5;

    private const INVENTORY_REASON = 'Local POS QA setup';

    /**
     * Seed local/test catalog, branch configuration, and initial QA inventory.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Local menu catalog metadata may only be seeded locally or in tests.');
        }

        $user = User::query()->where('email', 'superadmin@gmail.com')->sole();
        $branches = Branch::query()
            ->whereIn('code', array_keys(self::INITIAL_STOCK))
            ->get()
            ->keyBy('code');
        $inventory = app(ApplyInventoryMovement::class);

        foreach (self::CATALOG as $name => $catalogCategory) {
            $category = Category::query()->where('name', $name)->first()
                ?? app(CreateCategory::class)->execute($user, [
                    'name' => $name,
                    'sort_order' => $catalogCategory['sort_order'],
                    'is_active' => true,
                ]);

            foreach ($catalogCategory['products'] as $productName => $localQaPrice) {
                $product = Product::query()
                    ->whereBelongsTo($category)
                    ->where('name', $productName)
                    ->first();

                if ($product === null) {
                    $product = app(CreateProduct::class)->execute($user, [
                        'category_id' => $category->id,
                        'name' => $productName,
                        'description' => null,
                        'default_price' => $localQaPrice,
                        'is_active' => true,
                    ]);
                } elseif ($product->default_price === '0.00') {
                    $product->update(['default_price' => $localQaPrice]);
                }

                foreach ($branches as $branch) {
                    $configuration = BranchProduct::query()->firstOrNew([
                        'branch_id' => $branch->id,
                        'product_id' => $product->id,
                    ]);

                    if (! $configuration->exists) {
                        $configuration->is_available = true;
                    }

                    $configuration->tracks_inventory = true;
                    $configuration->low_stock_threshold ??= self::LOW_STOCK_THRESHOLD;
                    $configuration->save();

                    $hasBalance = BranchInventory::query()
                        ->whereBelongsTo($branch)
                        ->whereBelongsTo($product)
                        ->exists();

                    if (! $hasBalance) {
                        $inventory->execute(
                            $branch,
                            $product,
                            InventoryMovementType::ManualAdjustment,
                            self::INITIAL_STOCK[$branch->code],
                            self::INVENTORY_REASON,
                            $user,
                        );
                    }
                }
            }
        }
    }
}
