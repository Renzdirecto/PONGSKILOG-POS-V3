<?php

namespace App\Enums;

/** Which canonical stock a Giveaway moved: Recipe Ingredients or direct Product stock, never both. */
enum GiveawayStockMode: string
{
    case Recipe = 'recipe';
    case ProductStock = 'product_stock';
    case None = 'none';
}
