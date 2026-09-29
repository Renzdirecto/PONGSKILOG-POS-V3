import type { ModifierSemanticRole } from '@/types/catalog';

/**
 * The three user-facing Group behaviours. The stored semantic_role stays the existing `size` / `instruction` / null;
 * null is presented as "Add-on / Modifier" (formerly "Standard options").
 */
export const MODIFIER_ROLE_OPTIONS: {
    value: '' | 'size' | 'instruction';
    role: ModifierSemanticRole;
    label: string;
    help: string;
}[] = [
    {
        value: 'size',
        role: 'size',
        label: 'Size',
        help: 'Defines the base recipe variant, such as Small, Medium, or Large.',
    },
    {
        value: '',
        role: null,
        label: 'Add-on / Modifier',
        help: 'Optional or required choices that may add price and ingredient usage.',
    },
    {
        value: 'instruction',
        role: 'instruction',
        label: 'Instructions',
        help: 'Preparation requests only. No ingredient or price effect.',
    },
];

export function modifierRoleLabel(
    role: ModifierSemanticRole | undefined,
): string {
    return (
        MODIFIER_ROLE_OPTIONS.find((option) => option.role === (role ?? null))
            ?.label ?? 'Add-on / Modifier'
    );
}

export function modifierRoleHelp(
    role: ModifierSemanticRole | undefined,
): string {
    return (
        MODIFIER_ROLE_OPTIONS.find((option) => option.role === (role ?? null))
            ?.help ?? ''
    );
}

export function modifierRoleFromValue(value: string): ModifierSemanticRole {
    return value === 'size' || value === 'instruction' ? value : null;
}

/** Names of the active Size groups in a selection; more than one is rejected by the server. */
export function activeSizeGroupNames(
    groups: {
        id: string;
        name: string;
        semantic_role: ModifierSemanticRole;
        is_active: boolean;
    }[],
    selectedIds: string[],
): string[] {
    return groups
        .filter(
            (group) =>
                selectedIds.includes(group.id) &&
                group.semantic_role === 'size' &&
                group.is_active,
        )
        .map((group) => group.name);
}
