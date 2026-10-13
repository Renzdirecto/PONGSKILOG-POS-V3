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
