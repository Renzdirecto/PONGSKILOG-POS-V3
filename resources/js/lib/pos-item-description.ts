type PosItemDescriptionModifier = {
    name: string;
    semantic_role?: 'size' | 'instruction' | null;
};

export function posItemDescription(
    modifiers: PosItemDescriptionModifier[],
    notes?: string | null,
): string {
    const details = modifiers
        .filter((modifier) => modifier.semantic_role === 'instruction')
        .map((modifier) => modifier.name);
    const trimmedNotes = notes?.trim();

    if (trimmedNotes) {
        details.push(trimmedNotes);
    }

    return details.join(', ');
}
