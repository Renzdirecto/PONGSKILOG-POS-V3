/**
 * Required-field state (manual QA rule): anything REQUIRED that is still empty or unconfigured has a red outline and
 * readable text ("Required", "Recipe required"); once valid it returns to the neutral gray outline. Optional controls
 * never use it. Text inputs get the same result from `aria-invalid` on the shared Input; these helpers cover
 * selectable cards, chip groups and pick lists.
 */
export const REQUIRED_RED = '#b91c1c';

/** Border of a selectable card: red while its required configuration is missing, else neutral or selected. */
export function requiredOutline(missing: boolean, selected = false): string {
    if (missing) {
        return selected
            ? 'border-[1.5px] border-[#b91c1c]'
            : 'border border-[#b91c1c]';
    }

    return selected
        ? 'border-[1.5px] border-[#111]'
        : 'border border-[#e5e5e5]';
}

/** Border of a required group (chips, pick list) that has no valid choice yet. */
export function requiredGroupOutline(missing: boolean): string {
    return missing ? 'border-[#b91c1c]' : 'border-neutral-200';
}

/** A peso amount with at most two decimals (zero allowed), as the server accepts it. */
export function isMoneyInput(value: string | null | undefined): boolean {
    return /^\d{1,12}(?:\.\d{1,2})?$/.test((value ?? '').trim());
}

/** A peso amount above ₱0.00. */
export function isPositiveMoneyInput(
    value: string | null | undefined,
): boolean {
    return isMoneyInput(value) && Number((value ?? '').trim()) > 0;
}

/** A whole quantity from `min` to `max`. */
export function isWholeQuantity(
    value: string | null | undefined,
    min = 1,
    max = 1_000_000,
): boolean {
    const trimmed = (value ?? '').trim();

    return (
        /^\d+$/.test(trimmed) &&
        Number(trimmed) >= min &&
        Number(trimmed) <= max
    );
}

/** True when a required text value is still blank. */
export function isBlank(value: string | null | undefined): boolean {
    return (value ?? '').trim() === '';
}
