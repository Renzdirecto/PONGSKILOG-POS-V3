import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import type { BranchContext } from '@/types';

export const ownerControlClass =
    'h-[46px] min-w-0 rounded-[11px] border border-[#e5e5e5] bg-white px-3 text-base text-[#111111] outline-none transition focus-visible:border-[#111111] focus-visible:ring-2 focus-visible:ring-[#111111]/20 sm:text-[13px]';
export const ownerSecondaryActionClass =
    'min-h-11 rounded-[11px] border border-[#e5e5e5] bg-white px-3 text-[12.5px] font-semibold text-[#111111] hover:border-[#111111] hover:bg-white focus-visible:ring-2 focus-visible:ring-[#111111] focus-visible:outline-none';
export const ownerPrimaryActionClass =
    'min-h-11 rounded-[11px] border border-[#111111] bg-[#111111] px-4 text-[13px] font-semibold text-white hover:bg-neutral-800 focus-visible:ring-2 focus-visible:ring-[#111111] focus-visible:ring-offset-2 focus-visible:outline-none';
export const ownerPanelClass =
    'rounded-[20px] border border-[#ececec] bg-white shadow-[0_1px_2px_rgba(17,17,17,0.045),0_14px_32px_-16px_rgba(17,17,17,0.20)]';

export function OwnerPage({
    title,
    description,
    action,
    children,
    maxWidth = 'max-w-[1440px]',
}: {
    title: string;
    description: string;
    action?: ReactNode;
    children: ReactNode;
    maxWidth?: string;
}) {
    const { branchContext } = usePage<{ branchContext: BranchContext }>().props;
    const scope = branchContext.current?.name ?? 'All Branches';

    return (
        <div className="min-w-0">
            <header className="hidden border-b border-[#e5e5e5] bg-white px-6 py-[18px] md:block">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-1.5 text-[11px]">
                            <span className="text-[#8a8a8a]">Pongskilog</span>
                            <span className="text-[#cfcfcf]">/</span>
                            <span className="text-[#8a8a8a]">{scope}</span>
                            <span className="text-[#cfcfcf]">/</span>
                            <span className="font-semibold">{title}</span>
                        </div>
                        <h1 className="mt-1 text-[23px] font-bold tracking-[-0.02em]">
                            {title}
                        </h1>
                        <p className="mt-1 max-w-[76ch] text-[12.5px] leading-5 text-[#666]">
                            {description}
                        </p>
                    </div>
                    {action && (
                        <div className="flex shrink-0 flex-wrap gap-2">
                            {action}
                        </div>
                    )}
                </div>
            </header>
            <div
                className={`mx-auto flex ${maxWidth} flex-col gap-3 p-3 md:px-[22px] md:py-[18px]`}
            >
                {action && <div className="md:hidden">{action}</div>}
                {children}
            </div>
        </div>
    );
}

export function OwnerStatusBadge({
    tone,
    children,
}: {
    tone: 'green' | 'amber' | 'red' | 'neutral' | 'outline' | 'blue';
    children: ReactNode;
}) {
    const styles = {
        green: 'border-emerald-200 bg-emerald-50 text-emerald-800',
        amber: 'border-amber-200 bg-amber-50 text-amber-800',
        red: 'border-red-200 bg-red-50 text-red-800',
        neutral: 'border-neutral-200 bg-neutral-100 text-neutral-700',
        outline: 'border-[#d8d8d8] bg-white text-[#666]',
        blue: 'border-blue-200 bg-blue-50 text-blue-800',
    };

    return (
        <span
            className={`inline-flex w-fit items-center rounded-full border px-2.5 py-1 text-[10px] font-semibold tracking-[0.045em] uppercase ${styles[tone]}`}
        >
            {children}
        </span>
    );
}
