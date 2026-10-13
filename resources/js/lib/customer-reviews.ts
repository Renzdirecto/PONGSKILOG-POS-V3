export type ReviewImage = {
    url: string;
    kind: 'profile' | 'review';
    width: number;
    height: number;
};
export type CustomerReview = {
    id: string;
    rating: number;
    preferred_name: string;
    description: string;
    purchased_items: string | null;
    visit_type: 'dine_in' | 'takeout' | null;
    date: string;
    images: ReviewImage[];
    status?: string;
    visibility?: string;
};
export type ReviewBranch = { id: string; name: string; code: string };

/** Mirrors `CustomerReview::PREFERRED_NAME_MAX`: new names only, older longer names are kept as saved. */
export const REVIEW_NAME_MAX = 10;

export function reviewCharacterCount(value: string): number {
    return Array.from(value).length;
}
const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];
/** "Oct 13, 2026" from the server's Manila `YYYY-MM-DD`, without a Date so no time zone can shift the day. */
export function reviewDateLabel(date: string): string {
    const [year, month, day] = date.split('-').map(Number);
    return MONTHS[month - 1] && day && year
        ? `${MONTHS[month - 1]} ${day}, ${year}`
        : date;
}
/** The photo to move to after a touch gesture: 1 next, -1 previous, 0 for a tap or a mostly vertical drag. */
export function reviewSwipeStep(deltaX: number, deltaY: number): -1 | 0 | 1 {
    if (Math.abs(deltaX) < 40 || Math.abs(deltaX) <= Math.abs(deltaY)) return 0;
    return deltaX < 0 ? 1 : -1;
}
export function reviewFileError(
    files: { size: number; type: string }[],
    maximum: number,
): string | null {
    if (files.length > maximum)
        return `Choose at most ${maximum} image${maximum === 1 ? '' : 's'}.`;
    if (files.some((file) => file.size > 5 * 1024 * 1024))
        return 'Each original image must be 5 MB or smaller.';
    if (
        files.some(
            (file) =>
                !['image/jpeg', 'image/png', 'image/webp'].includes(file.type),
        )
    )
        return 'Choose JPEG, PNG or WebP images.';
    return null;
}
/** What a guest is told when a submission fails for a reason that is not a field error (those arrive as a 422). */
export function reviewSubmitFailure(
    status: number,
    retryAfter?: string,
): string {
    if (status === 429) {
        const seconds = Number(retryAfter);
        const wait = !(seconds > 0)
            ? 'later'
            : seconds <= 60
              ? 'in about a minute'
              : `in about ${Math.ceil(seconds / 60)} minutes`;
        return `Too many review attempts from this device. Your review is still here - please try again ${wait}.`;
    }
    if (status === 419)
        return 'Your session expired and was renewed. Please submit again.';
    if (status === 413)
        return 'Your photos are too large to send together. Remove a photo or choose smaller ones.';
    if (status === 0)
        return 'We could not reach PONGSKILOG. Check your connection and submit again - your review is still here.';
    return 'Your review could not be saved right now. Nothing was posted - please try again in a moment.';
}
export function advanceReviewScroll(
    position: number,
    maximum: number,
    elapsed: number,
    direction: number,
) {
    /* 0.021 px/ms: about 17% faster than the first 0.018 (v1.2.1 Manual QA 2). */
    const next = position + Math.min(elapsed, 64) * 0.021 * direction;
    return {
        position: Math.max(0, Math.min(maximum, next)),
        direction: next >= maximum ? -1 : next <= 0 ? 1 : direction,
    };
}
