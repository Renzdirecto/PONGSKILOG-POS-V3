import { Head } from '@inertiajs/react';
import { Building2, ShieldCheck } from 'lucide-react';

export default function UnassignedBranch() {
    return (
        <>
            <Head title="No branch assigned" />

            <div className="mx-auto flex min-h-[60svh] max-w-2xl items-center justify-center">
                <section className="w-full rounded-3xl border border-neutral-200 bg-white p-7 text-center shadow-sm sm:p-10">
                    <div className="mx-auto mb-6 flex size-16 items-center justify-center rounded-2xl bg-neutral-950 text-white">
                        <Building2 className="size-7" />
                    </div>
                    <p className="mb-3 text-xs font-bold tracking-[0.18em] text-[#8c671e] uppercase">
                        Access pending
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight">
                        No branch assigned
                    </h1>
                    <p className="mx-auto mt-4 max-w-lg text-base leading-7 text-neutral-600">
                        Your account is active, but it does not currently have
                        an active branch assignment. Ask an Owner or Super Admin
                        to update your access.
                    </p>
                    <div className="mt-7 flex items-center justify-center gap-2 text-sm text-neutral-500">
                        <ShieldCheck className="size-4 text-emerald-600" />
                        Operational workspaces remain protected.
                    </div>
                </section>
            </div>
        </>
    );
}
