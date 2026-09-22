import {
    CakeSlice,
    Coffee,
    Cookie,
    CupSoda,
    Drumstick,
    EggFried,
    PlusCircle,
    Sandwich,
    Soup,
    UtensilsCrossed,
    Wheat,
} from 'lucide-react';
import type { ComponentType } from 'react';
import type { CategoryIconKey } from '@/types/catalog';

const icons: Record<CategoryIconKey, ComponentType<{ className?: string }>> = {
    utensils: UtensilsCrossed,
    meal: Soup,
    rice: Wheat,
    drink: CupSoda,
    coffee: Coffee,
    dessert: CakeSlice,
    snack: Cookie,
    chicken: Drumstick,
    breakfast: EggFried,
    add_ons: PlusCircle,
    food: Sandwich,
};

export const categoryIconChoices: { key: CategoryIconKey; label: string }[] = [
    { key: 'utensils', label: 'Utensils' },
    { key: 'meal', label: 'Meal' },
    { key: 'rice', label: 'Rice' },
    { key: 'drink', label: 'Drink' },
    { key: 'coffee', label: 'Coffee' },
    { key: 'dessert', label: 'Dessert' },
    { key: 'snack', label: 'Snack' },
    { key: 'chicken', label: 'Chicken or meat' },
    { key: 'breakfast', label: 'Breakfast' },
    { key: 'add_ons', label: 'Add-ons' },
    { key: 'food', label: 'Generic food' },
];

export function CategoryIcon({
    iconKey,
    className = 'size-4',
}: {
    iconKey?: CategoryIconKey | null;
    className?: string;
}) {
    const Icon = icons[iconKey ?? 'food'];

    return <Icon className={className} aria-hidden="true" />;
}
