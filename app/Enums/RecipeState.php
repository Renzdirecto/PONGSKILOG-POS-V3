<?php

namespace App\Enums;

/** How a committed Order line affects Ingredient stock, fixed when the Product/size was first committed. */
enum RecipeState: string
{
    /** A recipe existed: the sale consumed Ingredient stock and has an estimated cost. */
    case Recipe = 'recipe';

    /** No recipe yet: the sale succeeded, moved no Ingredient stock and is not costed. */
    case Missing = 'missing';

    /** Direct resale: the Product uses Product stock (or is marked No recipe needed); no Ingredient moves. */
    case NotNeeded = 'not_needed';
}
