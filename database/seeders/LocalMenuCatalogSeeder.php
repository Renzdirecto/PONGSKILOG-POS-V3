<?php

namespace Database\Seeders;

use App\Actions\Catalog\CreateCategory;
use App\Actions\Catalog\CreateProduct;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class LocalMenuCatalogSeeder extends Seeder
{
    /**
     * Product names transcribed only from unambiguous public/images/menu filenames.
     *
     * @var array<string, array{sort_order: int, products: list<string>}>
     */
    private const CATALOG = [
        'Menu' => [
            'sort_order' => 0,
            'products' => [
                'Bangus', 'Beef Ampalaya', 'Beef Broccoli', 'Beef Pares', 'Beef Pares Only',
                'Bulalo', 'Burger Steak', 'Chicken', 'Crystal', 'Egg', 'Half Rice', 'Hotdog',
                'Hungarian', 'Letchon Kawali', 'Letchon Pares', 'Letchon Pares Only', 'Liempo',
                'Lomi', 'Maling', 'Mami', 'Miki Bihon', 'Miki Guisado', 'Pansit Bihon',
                'Pansit Canton', 'Porkchop', 'Rice', 'Shanghai', 'Siomai', 'Siopao', 'Sisig',
                'Tokwat Baboy',
            ],
        ],
        'Silog' => [
            'sort_order' => 1,
            'products' => [
                'Bangsilog', 'Chickfilletsilog', 'Chicksilog', 'Embusilog', 'Hamsilog',
                'Hotsilog', 'Hungsilog', 'Liemposilog', 'Longsilog', 'Malingsilog',
                'Mixsilog', 'Nuggetsilog', 'Porksilog', 'Shanghaisilog', 'Siomaisilog',
                'Tapsilog', 'Tocilog',
            ],
        ],
        'Lemon' => [
            'sort_order' => 2,
            'products' => ['Lemon Calamansi', 'Lemon Cola', 'Lemon Pure', 'Lemon Yakult'],
        ],
    ];

    /**
     * Seed only local/test catalog metadata. Prices remain zero until explicitly configured.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Local menu catalog metadata may only be seeded locally or in tests.');
        }

        $user = User::query()->where('email', 'superadmin@gmail.com')->sole();

        foreach (self::CATALOG as $name => $catalogCategory) {
            $category = Category::query()->where('name', $name)->first()
                ?? app(CreateCategory::class)->execute($user, [
                    'name' => $name,
                    'sort_order' => $catalogCategory['sort_order'],
                    'is_active' => true,
                ]);

            foreach ($catalogCategory['products'] as $productName) {
                if (Product::query()->whereBelongsTo($category)->where('name', $productName)->exists()) {
                    continue;
                }

                app(CreateProduct::class)->execute($user, [
                    'category_id' => $category->id,
                    'name' => $productName,
                    'description' => null,
                    'default_price' => '0.00',
                    'is_active' => true,
                ]);
            }
        }
    }
}
