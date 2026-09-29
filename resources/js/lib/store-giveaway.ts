export type GiveawayReason =
    | 'complimentary'
    | 'service_recovery'
    | 'promotion'
    | 'staff_meal'
    | 'other';

/** Stable server reason codes (GiveawayReason) with cashier-facing labels. */
export const GIVEAWAY_REASONS: { value: GiveawayReason; label: string }[] = [
    { value: 'complimentary', label: 'Complimentary / on the house' },
    { value: 'service_recovery', label: 'Service recovery' },
    { value: 'promotion', label: 'Promo / sampling' },
    { value: 'staff_meal', label: 'Staff meal' },
    { value: 'other', label: 'Other' },
];

type Group = {
    id: string;
    name: string;
    semantic_role?: string | null;
    options: { id: string; name: string }[];
};

/**
 * Size, Add-ons and Instructions of a customized line, named from the canonical Product Groups. Only the server
 * decides what stock this moves; this is display only.
 */
export function giveawaySelection(
    groups: Group[] | undefined,
    modifiers: { group_id: string; option_id: string }[],
): { size: string | null; addOns: string[]; instructions: string[] } {
    const result = {
        size: null as string | null,
        addOns: [] as string[],
        instructions: [] as string[],
    };
    for (const selection of modifiers) {
        const group = groups?.find((item) => item.id === selection.group_id);
        const option = group?.options.find(
            (item) => item.id === selection.option_id,
        );
        if (!group || !option) continue;
        if (group.semantic_role === 'size') result.size = option.name;
        else if (group.semantic_role === 'instruction')
            result.instructions.push(option.name);
        else result.addOns.push(option.name);
    }

    return result;
}

/** One cashier-facing message from a failed Giveaway or reversal request. */
export function giveawayError(error: unknown, action = 'giveaway'): string {
    const response =
        typeof error === 'object' && error !== null && 'response' in error
            ? (error as { response?: { status?: number; data?: unknown } })
                  .response
            : undefined;
    if (!response?.status) {
        return `The ${action} could not be saved. Reconnect and try again.`;
    }
    let data = response.data;
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data) as unknown;
        } catch {
            data = null;
        }
    }
    if (response.status === 409) {
        return `This ${action} attempt was already used with different details. Review and save again.`;
    }
    if (response.status === 403) {
        return `You are not authorized to record this ${action}.`;
    }
    if (response.status === 404) {
        return `This ${action} is not in the current branch.`;
    }
    if (typeof data === 'object' && data !== null && 'errors' in data) {
        const errors = (data as { errors?: Record<string, unknown> }).errors;
        const first = errors && Object.values(errors).flat()[0];
        if (typeof first === 'string') return first;
    }

    return `The ${action} could not be saved. Check the details and try again.`;
}
