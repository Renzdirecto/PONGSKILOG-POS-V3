export function WorkspaceSkeleton({ component }: { component: string }) {
    const catalog = component === 'workspaces/show';
    const kitchen = component === 'workspaces/kitchen';
    return (
        <div
            role="status"
            aria-label="Loading workspace"
            className="h-full space-y-4 p-4 sm:p-6"
        >
            <span className="sr-only">Loading fresh workspace data…</span>
            <div
                aria-hidden="true"
                className="space-y-4 motion-safe:animate-pulse"
            >
                <div className="flex gap-3">
                    {[0, 1, 2, 3].map((item) => (
                        <div
                            key={item}
                            className="h-11 w-28 rounded-xl bg-neutral-200"
                        />
                    ))}
                </div>
                <div
                    className={
                        catalog
                            ? 'grid grid-cols-2 gap-3 md:grid-cols-4'
                            : kitchen
                              ? 'grid gap-4 sm:grid-cols-2 xl:grid-cols-4'
                              : 'grid gap-4 sm:grid-cols-3'
                    }
                >
                    {Array.from(
                        { length: catalog ? 12 : kitchen ? 8 : 6 },
                        (_, item) => (
                            <div
                                key={item}
                                className={`rounded-2xl border border-neutral-200 bg-white p-4 ${kitchen ? 'h-64' : 'h-40'}`}
                            >
                                <div className="h-20 rounded-lg bg-neutral-100" />
                                <div className="mt-3 h-3 w-2/3 rounded bg-neutral-200" />
                            </div>
                        ),
                    )}
                </div>
            </div>
        </div>
    );
}
