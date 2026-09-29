import { useState } from 'react';

export function personInitials(
    name?: string | null,
    fallback = 'Staff',
): string {
    return (name || fallback)
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

/**
 * The signed-in person's profile picture (set in Staff administration, versioned so a realtime revalidation shows a new
 * one), else their initials. Display only; it never affects access.
 */
export function PersonAvatar({
    name,
    avatarUrl,
    className,
    fallback,
}: {
    name?: string | null;
    avatarUrl?: string | null;
    className: string;
    fallback?: string;
}) {
    const [failedUrl, setFailedUrl] = useState<string | null>(null);

    return avatarUrl && failedUrl !== avatarUrl ? (
        <img
            src={avatarUrl}
            alt=""
            onError={() => setFailedUrl(avatarUrl)}
            className={`${className} object-cover`}
        />
    ) : (
        <span aria-hidden="true" className={className}>
            {personInitials(name, fallback)}
        </span>
    );
}
