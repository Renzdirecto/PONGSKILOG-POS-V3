import { Head, Link, router } from '@inertiajs/react';
import { InventoryPagination } from '@/components/inventory-ui';
import { index as inventoryIndex } from '@/routes/inventory';
import { index } from '@/routes/inventory/movements';
import type { BranchSummary } from '@/types';
import type {
    InventoryMovement,
    InventoryPagination as Paginated,
} from '@/types/inventory';

type Props = {
    branch: BranchSummary;
    product: { id: string; name: string };
    movements: Paginated<InventoryMovement>;
};

const movementDate = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Manila',
});

export default function InventoryMovements({
    branch,
    product,
    movements,
}: Props) {
    return (
        <>
            <Head title={`${product.name} · Inventory history`} />
            <div className="mx-auto flex max-w-3xl flex-col gap-4">
                <Link
                    href={inventoryIndex({ query: { branch_id: branch.id } })}
                    className="flex min-h-11 w-fit items-center text-sm font-semibold underline underline-offset-4"
                >
                    Back to inventory
                </Link>
                <header className="space-y-2">
                    <p className="text-xs font-bold tracking-[0.18em] text-[#8c671e] uppercase">
                        INVENTORY
                    </p>
                    <h1 className="text-[17px] font-bold">Movement history</h1>
                    <p className="text-sm font-semibold break-words">
                        {product.name}
                    </p>
                    <p className="text-sm break-words text-neutral-600">
                        {branch.name} ({branch.code})
                    </p>
                </header>
                <p className="text-xs text-neutral-600">
                    {movements.total} movements · Newest first · Philippine time
                </p>
                {movements.data.length === 0 ? (
                    <div className="rounded-xl border border-dashed border-neutral-300 bg-white p-6 text-center">
                        <h2 className="text-sm font-bold">
                            No stock movements yet
                        </h2>
                        <p className="mt-2 text-sm text-neutral-600">
                            Stock adjustments for this product and branch will
                            appear here.
                        </p>
                    </div>
                ) : (
                    <ol className="flex flex-col gap-2">
                        {movements.data.map((movement) => (
                            <li
                                key={movement.id}
                                className="min-w-0 rounded-xl border border-neutral-200 bg-white p-3 shadow-sm sm:p-4"
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div className="space-y-1">
                                        <h2 className="text-sm font-bold">
                                            {movement.movement_label}
                                        </h2>
                                        <time
                                            dateTime={movement.created_at}
                                            className="text-xs text-neutral-600"
                                        >
                                            {movementDate.format(
                                                new Date(movement.created_at),
                                            )}
                                        </time>
                                    </div>
                                    <p
                                        className={`rounded-lg px-3 py-2 text-sm font-bold tabular-nums ${movement.quantity_delta > 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'}`}
                                    >
                                        <span className="sr-only">
                                            Quantity change:{' '}
                                        </span>
                                        {movement.quantity_delta > 0 ? '+' : ''}
                                        {movement.quantity_delta.toLocaleString()}
                                    </p>
                                </div>
                                <dl className="mt-3 space-y-2 text-xs">
                                    <div className="space-y-1">
                                        <dt className="font-semibold text-neutral-600">
                                            Reason
                                        </dt>
                                        <dd className="break-words whitespace-pre-wrap">
                                            {movement.reason ??
                                                'No reason recorded'}
                                        </dd>
                                    </div>
                                    <div className="flex flex-wrap gap-x-2 gap-y-1">
                                        <dt className="font-semibold text-neutral-600">
                                            Recorded by
                                        </dt>
                                        <dd className="break-words">
                                            {movement.created_by_name ??
                                                'Not available'}
                                        </dd>
                                    </div>
                                </dl>
                            </li>
                        ))}
                    </ol>
                )}
                <InventoryPagination
                    currentPage={movements.current_page}
                    lastPage={movements.last_page}
                    label="Movement history pagination"
                    onPageChange={(page) =>
                        router.get(
                            index.url(
                                { branch: branch.id, product: product.id },
                                { query: { page } },
                            ),
                        )
                    }
                />
            </div>
        </>
    );
}
