import type { BranchSummary } from '@/types';

/** What can be copied from another Branch. Stock and history are never a section. */
export type SetupCopySection = 'plans' | 'ingredients' | 'recipes';

export const SETUP_COPY_SECTIONS: {
    key: SetupCopySection;
    label: string;
    help: string;
}[] = [
    {
        key: 'plans',
        label: 'Pamalengke Plans',
        help: 'Plan names, icons, their ingredients and which products belong to each plan.',
    },
    {
        key: 'ingredients',
        label: 'Ingredients & settings',
        help: 'Unit, target stock, purchase unit and cost, replenishment rule and reorder point.',
    },
    {
        key: 'recipes',
        label: 'Recipes & add-on effects',
        help: 'Recipe mode, recipes per size and add-on ingredient effects, for products this Branch already sells.',
    },
];

/** What is never part of a setup copy, shown on every review. */
export const NEVER_COPIED = [
    'Product stock',
    'Ingredient stock',
    'Stock movements and history',
    'Pamamalengke purchases and Store expenses',
    'Sales, Orders and Store Sessions',
];

/** The server's dry-run result of a copy (CopyOperationsSetup), identical to what confirming writes. */
export type SetupCopyResult = {
    products: number;
    not_in_destination: number;
    plans: { new: number; existing: number; replaced: number };
    ingredients: {
        new: number;
        existing: number;
        replaced: number;
        conflicts: number;
    };
    plan_products: { assigned: number; moved: number; kept: number };
    recipes: {
        products: number;
        recipes: number;
        effects: number;
        direct: number;
        replaced: number;
        kept: number;
    };
    skipped: string[];
};

export type SetupCopyPreview = {
    source: BranchSummary;
    destination: BranchSummary;
    result: SetupCopyResult;
};

function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}

/** Review lines: what the copy writes at the destination, then what it keeps as is. */
export function setupCopyLines(result: SetupCopyResult): {
    copies: string[];
    kept: string[];
} {
    const copies: string[] = [];
    const kept: string[] = [];
    const plans = result.plans.new + result.plans.replaced;
    const ingredients = result.ingredients.new + result.ingredients.replaced;
    if (plans > 0) {
        copies.push(plural(plans, 'Plan'));
    }
    if (ingredients > 0) {
        copies.push(plural(ingredients, 'Ingredient'));
    }
    if (result.recipes.recipes > 0) {
        copies.push(plural(result.recipes.recipes, 'Recipe'));
    }
    if (result.recipes.effects > 0) {
        copies.push(plural(result.recipes.effects, 'Add-on effect'));
    }
    if (result.recipes.direct > 0) {
        copies.push(
            `${plural(result.recipes.direct, 'product')} set to No recipe needed`,
        );
    }
    if (result.plan_products.assigned + result.plan_products.moved > 0) {
        copies.push(
            `${plural(result.plan_products.assigned + result.plan_products.moved, 'product')} placed in a Plan`,
        );
    }
    if (result.plans.existing > 0) {
        kept.push(`${plural(result.plans.existing, 'Plan')} already here`);
    }
    if (result.ingredients.existing > 0) {
        kept.push(
            `${plural(result.ingredients.existing, 'Ingredient')} already here`,
        );
    }
    if (result.recipes.kept > 0) {
        kept.push(
            `${plural(result.recipes.kept, 'product')} already configured here`,
        );
    }

    return { copies, kept };
}

/** Nothing to write: every selected record already exists and is kept. */
export function nothingToCopy(result: SetupCopyResult): boolean {
    return setupCopyLines(result).copies.length === 0;
}
