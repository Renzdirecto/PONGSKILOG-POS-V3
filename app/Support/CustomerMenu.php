<?php

namespace App\Support;

use App\Models\Branch;
use Carbon\CarbonImmutable;

/**
 * The browse-only customer Menu (Phase 19.6A): a projection of the canonical Branch catalog (`BranchCatalog`, so the
 * same assortment, active state, Branch price, stock and Recipe availability as the POS), never a second menu. It
 * lists only what the Branch sells and has switched on (inactive Products/Categories are hidden, temporarily
 * unavailable or sold-out Products stay listed with a label) and carries no ids, stock counts, serving capacity,
 * costs or controls. Nothing here can create or change an order.
 *
 * @phpstan-type MenuSize array{name: string, price: string, available: bool}
 * @phpstan-type MenuProduct array{key: string, category: string, name: string, description: string|null, price: string, available: bool, status: 'available'|'sold_out'|'unavailable', image_url: string|null, sizes: list<MenuSize>, has_options: bool}
 */
class CustomerMenu
{
    /** Signed image links stay valid this long; the screen refetches the Menu before they expire. */
    public const IMAGE_MINUTES = 60;

    /** Hidden entirely: not on this Branch's menu at all. */
    private const HIDDEN_REASONS = ['not_in_branch', 'product_disabled', 'category_disabled'];

    public function __construct(private BranchCatalog $catalog) {}

    /**
     * @return array{categories: list<array{key: string, name: string, icon_key: string}>, products: list<MenuProduct>, expires_at: string}
     */
    public function for(Branch $branch): array
    {
        $expiresAt = CarbonImmutable::now()->addMinutes(self::IMAGE_MINUTES);
        $catalog = $this->catalog->browse($branch, customization: true, imageExpiresAt: $expiresAt);
        $categoryKeys = [];
        foreach ($catalog['categories'] as $index => $category) {
            $categoryKeys[$category['id']] = 'c'.$index;
        }

        $products = [];
        foreach ($catalog['products'] as $index => $product) {
            if (in_array($product['availability_reason'], self::HIDDEN_REASONS, true) || ! isset($categoryKeys[$product['category_id']])) {
                continue;
            }
            $base = ExactMoney::cents($product['effective_price']);
            $sizeAvailability = [];
            foreach ($product['recipe']['sizes'] ?? [] as $size) {
                if ($size['option_id'] !== null) {
                    $sizeAvailability[$size['option_id']] = $size['state'] === 'available';
                }
            }
            $sizes = [];
            $hasOptions = false;
            foreach ($product['modifier_groups'] ?? [] as $group) {
                if ($group['semantic_role'] !== 'size') {
                    $hasOptions = $hasOptions || ($group['semantic_role'] === null && $group['options'] !== []);

                    continue;
                }
                foreach ($group['options'] as $option) {
                    $sizes[] = [
                        'name' => (string) $option['name'],
                        'price' => ExactMoney::decimal(ExactMoney::add($base, ExactMoney::cents((string) $option['price_delta']))),
                        'available' => $product['is_available'] && ($sizeAvailability[$option['id']] ?? true),
                    ];
                }
            }
            $products[] = [
                'key' => 'p'.$index,
                'category' => $categoryKeys[$product['category_id']],
                'name' => $product['name'],
                'description' => $product['description'],
                'price' => $product['effective_price'],
                'available' => $product['is_available'],
                'status' => $product['is_available'] ? 'available' : ($product['availability_reason'] === 'out_of_stock' ? 'sold_out' : 'unavailable'),
                'image_url' => $product['image_url'],
                'sizes' => $sizes,
                'has_options' => $hasOptions,
            ];
        }

        $usedCategories = array_flip(array_column($products, 'category'));

        return [
            'categories' => array_values(array_filter(array_map(fn (array $category): array => [
                'key' => $categoryKeys[$category['id']],
                'name' => $category['name'],
                'icon_key' => $category['icon_key'],
            ], $catalog['categories']), fn (array $category): bool => isset($usedCategories[$category['key']]))),
            'products' => $products,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }
}
