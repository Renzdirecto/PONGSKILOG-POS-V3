import { Form } from '@inertiajs/react';
import { Building2, Check, ChevronDown, Globe2 } from 'lucide-react';
import ActiveBranchController from '@/actions/App/Http/Controllers/ActiveBranchController';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import type { BranchContext, BranchSummary } from '@/types';

type Props = {
    branchContext: BranchContext;
};

function BranchOption({
    branch,
    currentBranchId,
}: {
    branch: BranchSummary;
    currentBranchId?: string;
}) {
    const isCurrent = branch.id === currentBranchId;

    return (
        <Form
            {...ActiveBranchController.update.form(branch.id)}
            className="w-full"
        >
            {({ processing }) => (
                <button
                    type="submit"
                    disabled={processing || isCurrent}
                    className="flex min-h-12 w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm transition hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none disabled:opacity-60"
                >
                    <Building2 className="size-4 shrink-0 text-neutral-500" />
                    <span className="min-w-0 flex-1">
                        <span className="block truncate font-semibold text-neutral-900">
                            {branch.name}
                        </span>
                        <span className="block truncate text-xs text-neutral-500">
                            {branch.code}
                        </span>
                    </span>
                    {processing ? (
                        <Spinner className="size-4" />
                    ) : isCurrent ? (
                        <Check className="size-4 text-emerald-600" />
                    ) : null}
                </button>
            )}
        </Form>
    );
}

export function BranchSwitcher({ branchContext }: Props) {
    const canSwitch =
        branchContext.businessWide ||
        branchContext.selectableBranches.length > 1;
    const currentLabel =
        branchContext.current?.name ??
        (branchContext.businessWide ? 'All Branches' : 'Choose a branch');

    if (!canSwitch) {
        return (
            <div className="flex min-h-11 items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2">
                <Building2 className="size-4 text-neutral-500" />
                <div className="min-w-0">
                    <p className="text-[0.62rem] font-semibold tracking-[0.14em] text-neutral-400 uppercase">
                        Active branch
                    </p>
                    <p className="truncate text-sm font-semibold text-neutral-900">
                        {currentLabel}
                    </p>
                </div>
            </div>
        );
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="flex min-h-11 max-w-64 items-center gap-3 rounded-xl border border-neutral-200 bg-white px-3 py-2 text-left shadow-sm transition hover:border-neutral-300 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none"
                >
                    {branchContext.current ? (
                        <Building2 className="size-4 shrink-0 text-neutral-500" />
                    ) : (
                        <Globe2 className="size-4 shrink-0 text-neutral-500" />
                    )}
                    <span className="min-w-0 flex-1">
                        <span className="block text-[0.62rem] font-semibold tracking-[0.14em] text-neutral-400 uppercase">
                            Business · branch
                        </span>
                        <span className="block truncate text-sm font-semibold text-neutral-900">
                            {currentLabel}
                        </span>
                    </span>
                    <ChevronDown className="size-4 shrink-0 text-neutral-400" />
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="w-[min(22rem,calc(100vw-2rem))] rounded-xl p-2"
            >
                <DropdownMenuLabel className="px-3 py-2 text-xs tracking-[0.12em] text-neutral-400 uppercase">
                    Select scope
                </DropdownMenuLabel>
                {branchContext.businessWide && (
                    <>
                        <Form
                            {...ActiveBranchController.destroy.form()}
                            className="w-full"
                        >
                            {({ processing }) => (
                                <button
                                    type="submit"
                                    disabled={
                                        processing ||
                                        branchContext.current === null
                                    }
                                    className="flex min-h-12 w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm transition hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none disabled:opacity-60"
                                >
                                    <Globe2 className="size-4 text-neutral-500" />
                                    <span className="flex-1 font-semibold text-neutral-900">
                                        All Branches
                                    </span>
                                    {processing ? (
                                        <Spinner className="size-4" />
                                    ) : branchContext.current === null ? (
                                        <Check className="size-4 text-emerald-600" />
                                    ) : null}
                                </button>
                            )}
                        </Form>
                        <DropdownMenuSeparator />
                    </>
                )}
                <div className="max-h-72 overflow-y-auto">
                    {branchContext.selectableBranches.map((branch) => (
                        <BranchOption
                            key={branch.id}
                            branch={branch}
                            currentBranchId={branchContext.current?.id}
                        />
                    ))}
                </div>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
