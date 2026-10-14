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

/**
 * The portrait as it is uploaded: the landing card's 3:4 frame at the server's 1080 px bound (`LandingTeamController`).
 * The chosen photo itself never leaves the browser, so it may be larger than the 5 MB the server accepts.
 */
export const TEAM_PHOTO = { width: 810, height: 1080 } as const;
export const TEAM_PHOTO_SOURCE_BYTES = 20 * 1024 * 1024;
export const TEAM_PHOTO_MAX_ZOOM = 4;

/**
 * How a photo sits in the 3:4 card: `zoom` 1 just covers the card, and `x`/`y` are the point of the photo (0 to 1 of
 * its width and height) at the centre of the card.
 */
export type TeamPhotoCrop = { zoom: number; x: number; y: number };
type Size = { width: number; height: number };

/** The part of the photo the card shows, in the photo's own pixels. */
export function teamCropRect(crop: TeamPhotoCrop, photo: Size) {
    const width =
        Math.min(photo.width, (photo.height * 3) / 4) /
        Math.min(Math.max(crop.zoom, 1), TEAM_PHOTO_MAX_ZOOM);
    const height = (width * 4) / 3;
    const x = Math.min(
        Math.max(crop.x * photo.width - width / 2, 0),
        photo.width - width,
    );
    const y = Math.min(
        Math.max(crop.y * photo.height - height / 2, 0),
        photo.height - height,
    );
    return { x, y, width, height };
}

/** The crop with its zoom in range and the card always covered by the photo. */
export function clampTeamCrop(crop: TeamPhotoCrop, photo: Size): TeamPhotoCrop {
    const zoom = Math.min(Math.max(crop.zoom, 1), TEAM_PHOTO_MAX_ZOOM);
    const rect = teamCropRect({ ...crop, zoom }, photo);
    return {
        zoom,
        x: (rect.x + rect.width / 2) / photo.width,
        y: (rect.y + rect.height / 2) / photo.height,
    };
}

/** The crop after the photo was dragged by a share of the card's width (`dx`) and height (`dy`). */
export function movedTeamCrop(
    crop: TeamPhotoCrop,
    photo: Size,
    dx: number,
    dy: number,
): TeamPhotoCrop {
    const rect = teamCropRect(crop, photo);
    return clampTeamCrop(
        {
            zoom: crop.zoom,
            x: crop.x - (dx * rect.width) / photo.width,
            y: crop.y - (dy * rect.height) / photo.height,
        },
        photo,
    );
}

/** Where the photo is drawn inside the card preview, in percent of the card, so it fits any preview size. */
export function teamCropFrame(crop: TeamPhotoCrop, photo: Size) {
    const rect = teamCropRect(crop, photo);
    const percent = (value: number) => `${(value * 100).toFixed(3)}%`;
    return {
        width: percent(photo.width / rect.width),
        height: percent(photo.height / rect.height),
        left: percent(-rect.x / rect.width),
        top: percent(-rect.y / rect.height),
    };
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
