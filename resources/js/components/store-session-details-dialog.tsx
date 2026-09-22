import { Store } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { storeSessionDetailRows } from '@/lib/store-session';
import type { CurrentStoreSession } from '@/types';

export function StoreSessionDetailsDialog({
    open,
    onOpenChange,
    session,
    loading,
    unavailable,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    session: CurrentStoreSession | null;
    loading: boolean;
    unavailable: boolean;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90svh] overflow-y-auto bg-white p-4 text-neutral-950 sm:max-w-[420px] sm:p-5 [&>button]:top-1 [&>button]:right-1 [&>button]:flex [&>button]:min-h-11 [&>button]:min-w-11 [&>button]:items-center [&>button]:justify-center">
                <DialogHeader className="pr-7 text-left">
                    <div className="flex items-center gap-3">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-green-50 text-green-700">
                            <Store className="size-5" />
                        </span>
                        <div>
                            <DialogTitle className="text-[17px] font-bold">
                                Store is Open
                            </DialogTitle>
                            <DialogDescription className="mt-0.5 text-xs text-neutral-500">
                                Current Store Session · Read-only
                            </DialogDescription>
                        </div>
                    </div>
                </DialogHeader>

                {loading && (
                    <div
                        className="flex min-h-40 items-center justify-center gap-2 text-sm text-neutral-500"
                        role="status"
                    >
                        <Spinner /> Loading opening details…
                    </div>
                )}

                {!loading && unavailable && (
                    <div
                        role="alert"
                        className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm leading-6 text-amber-900"
                    >
                        This Store Session is no longer available. The store
                        state may have changed; close this window and refresh
                        the POS.
                    </div>
                )}

                {!loading && session && (
                    <dl className="overflow-hidden rounded-xl border border-neutral-200">
                        {storeSessionDetailRows(session).map((row) => (
                            <div
                                key={row.label}
                                className="flex flex-col gap-1 border-b border-neutral-100 px-3.5 py-3 last:border-b-0 sm:flex-row sm:items-baseline sm:justify-between sm:gap-4"
                            >
                                <dt className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                                    {row.label}
                                </dt>
                                <dd className="text-sm font-bold break-words text-neutral-950 tabular-nums sm:max-w-[250px] sm:text-right">
                                    {row.value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                )}

                <Button
                    type="button"
                    variant="outline"
                    className="min-h-11 w-full rounded-xl"
                    onClick={() => onOpenChange(false)}
                >
                    Close
                </Button>
            </DialogContent>
        </Dialog>
    );
}
