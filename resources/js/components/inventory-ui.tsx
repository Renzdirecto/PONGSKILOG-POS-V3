import { actionClass } from '@/components/catalog-ui';
import { Button } from '@/components/ui/button';
import type { StockStatus } from '@/types/inventory';

export const stockStatusLabels: Record<StockStatus, string> = {
    in_stock: 'In stock',
    low_stock: 'Low stock',
    out_of_stock: 'Out of stock',
    not_tracked: 'Not tracked',
};

const stockStatusClasses: Record<StockStatus, string> = {
    in_stock: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    low_stock: 'border-amber-200 bg-amber-50 text-amber-900',
    out_of_stock: 'border-red-200 bg-red-50 text-red-800',
    not_tracked: 'border-neutral-200 bg-neutral-100 text-neutral-600',
};

export function StockStatusBadge({ status }: { status: StockStatus }) {
    return (
        <span
            className={`inline-flex w-fit rounded-full border px-2.5 py-1 text-[10px] font-semibold tracking-[0.045em] uppercase ${stockStatusClasses[status]}`}
        >
            {stockStatusLabels[status]}
        </span>
    );
}

export function InventoryPagination({
    currentPage,
    lastPage,
    label,
    onPageChange,
}: {
    currentPage: number;
    lastPage: number;
    label: string;
    onPageChange: (page: number) => void;
}) {
    if (lastPage <= 1) return null;

    return (
        <nav
            aria-label={label}
            className="flex flex-wrap items-center justify-between gap-2"
        >
            <Button
                variant="outline"
                className={actionClass}
                disabled={currentPage <= 1}
                onClick={() => onPageChange(currentPage - 1)}
            >
                Previous
            </Button>
            <span className="text-xs text-neutral-600">
                Page {currentPage} of {lastPage}
            </span>
            <Button
                variant="outline"
                className={actionClass}
                disabled={currentPage >= lastPage}
                onClick={() => onPageChange(currentPage + 1)}
            >
                Next
            </Button>
        </nav>
    );
}
