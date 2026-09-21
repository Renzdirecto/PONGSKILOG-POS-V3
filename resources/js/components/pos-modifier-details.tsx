import { pesos } from '@/lib/pos-money';

type ModifierDetail = {
    id: string;
    name: string;
    price_delta: string;
    semantic_role?: 'size' | 'instruction' | null;
};

export function PosModifierDetails({
    modifiers,
    standardClassName,
    instructionClassName,
}: {
    modifiers: ModifierDetail[];
    standardClassName: string;
    instructionClassName: string;
}) {
    const standard = modifiers.filter(
        (modifier) =>
            modifier.semantic_role !== 'size' &&
            modifier.semantic_role !== 'instruction',
    );
    const instructions = modifiers.filter(
        (modifier) => modifier.semantic_role === 'instruction',
    );

    return (
        <>
            {standard.map((modifier) => (
                <p key={modifier.id} className={standardClassName}>
                    {modifier.name} (+{pesos(modifier.price_delta)})
                </p>
            ))}
            {instructions.length > 0 && (
                <p className={instructionClassName}>
                    <span className="font-semibold">Instructions:</span>{' '}
                    {instructions
                        .map((instruction) => instruction.name)
                        .join(', ')}
                </p>
            )}
        </>
    );
}
