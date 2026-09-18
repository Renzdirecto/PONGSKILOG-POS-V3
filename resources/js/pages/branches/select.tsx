import { Form, Head, usePage } from '@inertiajs/react';
import { ArrowRight, Building2, MapPin } from 'lucide-react';
import ActiveBranchController from '@/actions/App/Http/Controllers/ActiveBranchController';
import { Spinner } from '@/components/ui/spinner';
import type { BranchContext } from '@/types';

type PageProps = {
    branchContext: BranchContext;
};

export default function SelectBranch() {
    const { branchContext } = usePage<PageProps>().props;

    return (
        <>
            <Head title="Choose a branch" />

            <div className="mx-auto max-w-4xl">
                <div className="mb-8 max-w-2xl space-y-3">
                    <p className="text-xs font-bold tracking-[0.18em] text-[#8c671e] uppercase">
                        Branch access
                    </p>
                    <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                        Choose where you’re working
                    </h1>
                    <p className="text-base leading-7 text-neutral-600">
                        Your account is assigned to more than one branch. Select
                        the branch you want to use for this session.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    {branchContext.selectableBranches.map((branch) => (
                        <article
                            key={branch.id}
                            className="flex min-w-0 flex-col rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm sm:p-6"
                        >
                            <div className="mb-6 flex items-start gap-4">
                                <span className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-neutral-950 text-white">
                                    <Building2 className="size-5" />
                                </span>
                                <div className="min-w-0">
                                    <h2 className="truncate text-lg font-bold">
                                        {branch.name}
                                    </h2>
                                    <p className="mt-1 flex items-center gap-1.5 text-sm text-neutral-500">
                                        <MapPin className="size-3.5" />
                                        Branch code {branch.code}
                                    </p>
                                </div>
                            </div>

                            <Form
                                {...ActiveBranchController.update.form(
                                    branch.id,
                                )}
                                className="mt-auto"
                            >
                                {({ processing }) => (
                                    <button
                                        type="submit"
                                        disabled={processing}
                                        className="inline-flex min-h-12 w-full items-center justify-center gap-2 rounded-xl bg-neutral-950 px-4 text-sm font-semibold text-white transition hover:bg-neutral-800 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {processing ? (
                                            <>
                                                <Spinner /> Selecting…
                                            </>
                                        ) : (
                                            <>
                                                Select branch
                                                <ArrowRight className="size-4" />
                                            </>
                                        )}
                                    </button>
                                )}
                            </Form>
                        </article>
                    ))}
                </div>
            </div>
        </>
    );
}
