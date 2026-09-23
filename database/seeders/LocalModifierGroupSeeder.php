<?php

namespace Database\Seeders;

use App\Enums\ModifierSelectionType;
use App\Enums\ModifierSemanticRole;
use App\Models\ModifierGroup;
use App\Models\Product;
use Illuminate\Database\Seeder;
use RuntimeException;

class LocalModifierGroupSeeder extends Seeder
{
    /**
     * @var array<string, array{
     *     semantic_role: ModifierSemanticRole|null,
     *     selection_type: ModifierSelectionType,
     *     min_select: int,
     *     max_select: int,
     *     options: array<string, string>
     * }>
     */
    private const GROUPS = [
        'Egg options' => [
            'semantic_role' => null,
            'selection_type' => ModifierSelectionType::Single,
            'min_select' => 1,
            'max_select' => 1,
            'options' => ['Sunny side up' => '0.00', 'Scrambled' => '0.00'],
        ],
        'Rice' => [
            'semantic_role' => null,
            'selection_type' => ModifierSelectionType::Single,
            'min_select' => 1,
            'max_select' => 1,
            'options' => ['Regular' => '0.00', 'Extra rice' => '25.00'],
        ],
        'Size' => [
            'semantic_role' => ModifierSemanticRole::Size,
            'selection_type' => ModifierSelectionType::Single,
            'min_select' => 1,
            'max_select' => 1,
            'options' => ['Medium' => '15.00', 'Large' => '30.00'],
        ],
        'Sugar level' => [
            'semantic_role' => null,
            'selection_type' => ModifierSelectionType::Single,
            'min_select' => 1,
            'max_select' => 1,
            'options' => ['50%' => '0.00', '25%' => '0.00'],
        ],
        'Add-ons' => [
            'semantic_role' => null,
            'selection_type' => ModifierSelectionType::Multiple,
            'min_select' => 0,
            'max_select' => 1,
            'options' => ['Pearls' => '15.00'],
        ],
    ];

    /** @var array<string, list<string>> */
    private const PRODUCT_GROUPS = [
        'Tapsilog' => ['Egg options', 'Rice'],
        'Hotsilog' => ['Egg options', 'Rice'],
        'Chicksilog' => ['Egg options', 'Rice'],
        'Bangsilog' => ['Egg options', 'Rice'],
        'Longsilog' => ['Egg options', 'Rice'],
        'Lemon Yakult' => ['Size', 'Sugar level', 'Add-ons'],
    ];

    /**
     * Seed only proven local QA modifier fixtures without replacing manual edits.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Local modifier fixtures may only be seeded locally or in tests.');
        }

        $groups = collect();
        foreach (self::GROUPS as $name => $fixture) {
            $group = ModifierGroup::query()->firstOrCreate(
                ['name' => $name],
                [
                    'semantic_role' => $fixture['semantic_role'],
                    'selection_type' => $fixture['selection_type'],
                    'min_select' => $fixture['min_select'],
                    'max_select' => $fixture['max_select'],
                    'is_active' => true,
                ],
            );
            $sortOrder = 0;
            foreach ($fixture['options'] as $optionName => $priceDelta) {
                $group->options()->firstOrCreate(
                    ['name' => $optionName],
                    [
                        'price_delta' => $priceDelta,
                        'is_active' => true,
                        'sort_order' => $sortOrder,
                    ],
                );
                $sortOrder++;
            }
            $groups->put($name, $group);
        }

        foreach (self::PRODUCT_GROUPS as $productName => $groupNames) {
            $product = Product::query()->where('name', $productName)->first();
            if ($product === null) {
                continue;
            }

            $product->modifierGroups()->syncWithoutDetaching(
                $groups->only($groupNames)->pluck('id')->all(),
            );
        }
    }
}
