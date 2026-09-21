import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, History } from 'lucide-react';
import { actionClass } from '@/components/catalog-ui';
import { InventoryPagination } from '@/components/inventory-ui';
import { OwnerPage, ownerPanelClass } from '@/components/owner-ui';
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
            <OwnerPage
                title="Movement history"
                description={`${product.name} · ${branch.name} (${branch.code})`}
                maxWidth="max-w-[1040px]"
                action={
                    <Link
                        href={inventoryIndex({
                            query: { branch_id: branch.id },
                        })}
                        className={`${actionClass} inline-flex w-full items-center justify-center gap-2 md:w-auto`}
                    >
                        <ArrowLeft className="size-4" /> Back to inventory
                    </Link>
                }
            >
                <p className="text-[11.5px] text-[#767676]">
                    {movements.total} movements · Newest first · Philippine time
                </p>
                {movements.data.length === 0 ? (
                    <div
                        className={`${ownerPanelClass} px-5 py-14 text-center`}
                    >
                        <History className="mx-auto size-7 text-[#aaa]" />
                        <h2 className="mt-3 text-sm font-semibold">
                            No stock movements yet
                        </h2>
                        <p className="mt-1 text-[12.5px] text-[#767676]">
                            Stock adjustments for this product and branch will
                            appear here.
                        </p>
                    </div>
                ) : (
                    <ol
                        className={`${ownerPanelClass} divide-y divide-[#eeeeee] overflow-hidden`}
                    >
                        {movements.data.map((movement) => (
                            <li
                                key={movement.id}
                                className="grid min-w-0 gap-3 px-3.5 py-3 sm:grid-cols-[160px_minmax(180px,1fr)_minmax(220px,1.6fr)_90px] sm:items-center sm:px-4"
                            >
                                <div>
                                    <h2 className="text-[13px] font-semibold">
                                        {movement.movement_label}
                                    </h2>
                                    <time
                                        dateTime={movement.created_at}
                                        className="mt-0.5 block text-[11px] text-[#767676]"
                                    >
                                        {movementDate.format(
                                            new Date(movement.created_at),
                                        )}
                                    </time>
                                </div>
                                <div className="text-[11.5px]">
                                    <span className="text-[#767676] sm:block">
                                        Recorded by
                                    </span>
                                    <span className="font-medium">
                                        {movement.created_by_name ??
                                            'Not available'}
                                    </span>
                                </div>
                                <div className="min-w-0 text-[11.5px]">
                                    <span className="text-[#767676] sm:block">
                                        Reason
                                    </span>
                                    <span className="break-words whitespace-pre-wrap">
                                        {movement.reason ??
                                            'No reason recorded'}
                                    </span>
                                </div>
                                <p
                                    className={`justify-self-start rounded-lg px-3 py-2 text-sm font-bold tabular-nums sm:justify-self-end ${movement.quantity_delta > 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'}`}
                                >
                                    <span className="sr-only">
                                        Quantity change:{' '}
                                    </span>
                                    {movement.quantity_delta > 0 ? '+' : ''}
                                    {movement.quantity_delta.toLocaleString()}
                                </p>
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
            </OwnerPage>
        </>
    );
}
