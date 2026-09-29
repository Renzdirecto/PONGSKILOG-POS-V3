<?php

use App\Support\ExactQuantity;
use App\Support\RecipeCapacity;

/** @param array<string, string> $quantities */
function exactQuantities(array $quantities): array
{
    return array_map(fn (string $quantity): int => str_starts_with($quantity, '-')
        ? -ExactQuantity::fromInput(substr($quantity, 1))
        : ExactQuantity::fromInput($quantity), $quantities);
}

test('servings are the minimum whole servings across required ingredients', function (array $recipe, array $stock, int $servings) {
    expect(RecipeCapacity::servings(exactQuantities($recipe), exactQuantities($stock)))->toBe($servings);
})->with([
    'limiting ingredient (lemon 20, cup 15, syrup 33)' => [['lemon' => '0.5', 'cup' => '1', 'syrup' => '30'], ['lemon' => '10', 'cup' => '15', 'syrup' => '1000'], 15],
    'whole quantities' => [['egg' => '2'], ['egg' => '30'], 15],
    'fractional stock' => [['lemon' => '0.5'], ['lemon' => '29.5'], 59],
    'fractional recipe rounds down' => [['sugar' => '0.3'], ['sugar' => '1'], 3],
    'fractional both, exact at four places' => [['milk' => '0.0625'], ['milk' => '1.2499'], 19],
    'zero stock' => [['lemon' => '0.5', 'cup' => '1'], ['lemon' => '0', 'cup' => '15'], 0],
    'negative existing stock is zero, never negative servings' => [['lemon' => '0.5'], ['lemon' => '-3'], 0],
    'missing stock record counts as zero' => [['lemon' => '0.5', 'cup' => '1'], ['cup' => '15'], 0],
    'just short of one serving' => [['syrup' => '30'], ['syrup' => '29.9999'], 0],
]);

test('a requirement without ingredients or with a non-positive quantity is a programming error', function (array $recipe) {
    expect(fn () => RecipeCapacity::servings($recipe, []))->toThrow(LogicException::class);
})->with([[[]], [['lemon' => 0]]]);
