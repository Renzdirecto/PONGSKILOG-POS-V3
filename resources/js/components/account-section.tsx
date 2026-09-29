import type { ReactNode } from 'react';

/** One card of the Account & preferences pages: a title, a short explanation and its content. */
export function AccountSection({
    title,
    description,
    children,
    id,
}: {
    title: string;
    description?: string;
    children: ReactNode;
    id?: string;
}) {
    const headingId = id ? `${id}-title` : undefined;

    return (
        <section
            aria-labelledby={headingId}
            className="rounded-2xl border border-neutral-200 bg-white p-4 sm:p-5"
        >
            <header className="mb-4">
                <h2 id={headingId} className="text-[15px] font-bold tracking-tight">
                    {title}
                </h2>
                {description && (
                    <p className="mt-1 text-[12.5px] leading-5 text-neutral-500">
                        {description}
                    </p>
                )}
            </header>
            {children}
        </section>
    );
}
