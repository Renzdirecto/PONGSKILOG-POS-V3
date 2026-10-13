import type { TeamCard } from '@/lib/landing';

/** Mirrors `UpdateLandingTeamMemberRequest`: the longest value each field of a team card accepts. */
export const TEAM_LIMITS = {
    role_label: 12,
    role_title: 60,
    name: 60,
    bio: 120,
} as const;

/** What Website → Team sends for one card; an empty name or bio is saved as none. */
export type TeamCardForm = {
    role_label: string;
    role_title: string;
    name: string;
    bio: string;
    is_visible: boolean;
};

/** Whether the form differs from the saved card, so Save is offered only when there is something to save. */
export function teamCardChanged(form: TeamCardForm, member: TeamCard): boolean {
    return (
        form.role_label.trim() !== member.code ||
        form.role_title.trim() !== member.title ||
        form.name.trim() !== (member.name ?? '') ||
        form.bio.trim() !== (member.bio ?? '') ||
        form.is_visible !== member.visible
    );
}

/** Mirrors `LandingTeamMember::MAX_MEMBERS`: the most members the team may have. */
export const TEAM_MAX_MEMBERS = 30;

/** A new member's form: the same details as a card, starting empty and visible. */
export const NEW_TEAM_MEMBER: TeamCardForm = {
    role_label: '',
    role_title: '',
    name: '',
    bio: '',
    is_visible: true,
};

/**
 * The team's ids after one member moves one place earlier (-1) or later (1), or null when that member is already at
 * that end. The whole order is sent to the server, which accepts it only if it still names every member.
 */
export function movedTeamOrder(
    ids: number[],
    id: number,
    direction: -1 | 1,
): number[] | null {
    const from = ids.indexOf(id);
    const to = from + direction;
    if (from < 0 || to < 0 || to >= ids.length) return null;
    const order = [...ids];
    [order[from], order[to]] = [order[to], order[from]];
    return order;
}
