import { pesos } from '@/lib/pos-money';
import { posItemDescription } from '@/lib/pos-item-description';

type ModifierDetail = {
    id: string;
    name: string;
    price_delta: string;
    semantic_role?: 'size' | 'instruction' | null;
};

export function PosModifierDetails({
    modifiers,
    notes,
    standardClassName,
    instructionClassName,
}: {
    modifiers: ModifierDetail[];
    notes?: string | null;
    standardClassName: string;
    instructionClassName: string;
}) {
    const standard = modifiers.filter(
        (modifier) =>
            modifier.semantic_role !== 'size' &&
            modifier.semantic_role !== 'instruction',
    );
    const description = posItemDescription(modifiers, notes);

    return (
        <>
            {standard.map((modifier) => (
                <p key={modifier.id} className={standardClassName}>
                    {modifier.name} (+{pesos(modifier.price_delta)})
                </p>
            ))}
            {description && (
                <p className={instructionClassName}>
                    {description}
                </p>
            )}
        </>
    );
}
