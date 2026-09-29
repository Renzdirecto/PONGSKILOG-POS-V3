import { Store } from 'lucide-react';
import { useState } from 'react';
import { OpenStoreForm } from '@/components/open-store-form';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { StoreContext } from '@/types';

const pill =
    'inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full border px-2 text-[9px] font-semibold md:px-[11px] md:text-[11.5px]';
const openTone = 'border-green-200 bg-green-50 text-green-700';
const closedTone = 'border-neutral-200 bg-neutral-50 text-neutral-600';

/**
 * THE Store status of every Store Operations page (POS, QR Orders, Dashboard, Kitchen, History, Branch Reports): one
 * source, one look. OPEN opens the current Store Session details (and their actions) for accounts that may see them;
 * CLOSED opens the Open Store form for accounts the server would let open this Branch. Everyone else sees the status
 * only — never a control they cannot use.
 */
export function StoreStatusControl({
    storeContext,
    branchName,
    canViewSession,
    onViewSession,
}: {
    storeContext: StoreContext | undefined;
    branchName: string | null;
    /** POS access and Store expenses: the permissions the current-session details require. */
    canViewSession: boolean;
    onViewSession: () => void;
}) {
    const [opening, setOpening] = useState(false);
    const isOpen = storeContext?.isOpen === true;
    const label = (
        <>
            <span
                className={`size-[7px] rounded-full ${isOpen ? 'bg-green-700' : 'bg-neutral-400'}`}
            />
            <span className="hidden sm:inline">STORE</span>{' '}
            {isOpen ? 'OPEN' : 'CLOSED'}
        </>
    );

    if (isOpen) {
        return canViewSession ? (
            <button
                type="button"
                aria-label="View current Store Session details"
                title="View current Store Session details"
                onClick={onViewSession}
                className={`${pill} ${openTone} transition hover:border-green-300 hover:bg-green-100 focus-visible:ring-2 focus-visible:ring-green-700 focus-visible:outline-none`}
            >
                {label}
            </button>
        ) : (
            <span aria-label="Store open" className={`${pill} ${openTone}`}>
                {label}
            </span>
        );
    }

    if (!storeContext?.canOpen || branchName === null) {
        return (
            <span aria-label="Store closed" className={`${pill} ${closedTone}`}>
                {label}
            </span>
        );
    }

    return (
        <>
            <button
                type="button"
                aria-label="Store closed. Open Store"
                title="Open Store"
                onClick={() => setOpening(true)}
                className={`${pill} ${closedTone} transition hover:border-neutral-400 hover:bg-white focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none`}
            >
                {label}
                <span className="ml-0.5 hidden rounded-full bg-neutral-950 px-2 py-0.5 text-[9px] font-bold text-white md:inline md:text-[10.5px]">
                    Open
                </span>
            </button>
            <Dialog open={opening} onOpenChange={setOpening}>
                <DialogContent className="max-h-[92dvh] overflow-y-auto rounded-2xl bg-white text-neutral-950 sm:max-w-xl">
                    <DialogHeader className="text-left">
                        <DialogTitle className="flex items-center gap-2 text-lg font-bold">
                            <Store className="size-5" /> Open Store
                        </DialogTitle>
                        <DialogDescription>
                            Start a new Store Session at {branchName}.
                        </DialogDescription>
                    </DialogHeader>
                    <OpenStoreForm
                        branchName={branchName}
                        heading={false}
                        onCancel={() => setOpening(false)}
                        onOpened={() => setOpening(false)}
                    />
                </DialogContent>
            </Dialog>
        </>
    );
}
