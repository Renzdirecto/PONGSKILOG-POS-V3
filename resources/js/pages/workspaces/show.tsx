import { Head, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, Globe2 } from 'lucide-react';
import type { BranchContext } from '@/types';

type Props = {
    workspace: string;
    eyebrow: string;
    description: string;
};

type SharedProps = {
    branchContext: BranchContext;
};

export default function Workspace({ workspace, eyebrow, description }: Props) {
    const { branchContext } = usePage<SharedProps>().props;
    const scope = branchContext.current?.name ?? 'All Branches';

    return (
        <>
            <Head title={`${workspace} workspace`} />

            <div className="mx-auto max-w-5xl">
                <div className="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div className="space-y-3">
                        <p className="text-xs font-bold tracking-[0.18em] text-[#8c671e] uppercase">
                            {eyebrow}
                        </p>
                        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                            {workspace} workspace
                        </h1>
                        <p className="max-w-2xl text-base leading-7 text-neutral-600">
                            {description}
                        </p>
                    </div>

                    <div className="flex min-h-12 items-center gap-3 rounded-xl border border-neutral-200 bg-white px-4 py-3 shadow-sm">
                        {branchContext.current ? (
                            <Building2 className="size-5 text-neutral-500" />
                        ) : (
                            <Globe2 className="size-5 text-neutral-500" />
                        )}
                        <div>
                            <p className="text-[0.62rem] font-semibold tracking-[0.14em] text-neutral-400 uppercase">
                                Current scope
                            </p>
                            <p className="text-sm font-bold">{scope}</p>
                        </div>
                    </div>
                </div>

                <section className="rounded-3xl border border-neutral-200 bg-white p-7 shadow-sm sm:p-10">
                    <div className="flex flex-col gap-6 sm:flex-row sm:items-center">
                        <span className="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
                            <CheckCircle2 className="size-7" />
                        </span>
                        <div>
                            <h2 className="text-xl font-bold">
                                Workspace routing is ready
                            </h2>
                            <p className="mt-2 max-w-2xl text-sm leading-6 text-neutral-600">
                                You reached the protected {workspace} entry
                                point with the correct role and branch context.
                                The operational features for this workspace are
                                intentionally delivered in later phases.
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}
