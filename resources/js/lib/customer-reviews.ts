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

export function reviewCharacterCount(value: string): number {
    return Array.from(value).length;
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
export function advanceReviewScroll(
    position: number,
    maximum: number,
    elapsed: number,
    direction: number,
) {
    const next = position + Math.min(elapsed, 64) * 0.018 * direction;
    return {
        position: Math.max(0, Math.min(maximum, next)),
        direction: next >= maximum ? -1 : next <= 0 ? 1 : direction,
    };
}
