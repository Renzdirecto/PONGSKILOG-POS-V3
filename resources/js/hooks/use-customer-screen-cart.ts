import { useCallback, useEffect, useRef, useSyncExternalStore } from 'react';
import { createClientUuid } from '@/lib/client-uuid';
import { createCartSequencer } from '@/lib/customer-screen';
import {
    enqueueStationWrite,
    stationRequest,
    stationScreen,
} from '@/lib/pos-station';
import {
    cart as cartRoute,
    takeover as takeoverRoute,
} from '@/routes/pos/customer-screen';
import type { CartLine, OrderType } from '@/types/pos';

/** Rapid cart edits are coalesced: the screen receives the latest cart once the cashier pauses for this long. */
const CART_DEBOUNCE_MS = 300;

/**
 * Projects this POS station's unfinished cart onto its paired customer screen (Phase 19.6A) and announces a committed
 * order for the takeover. The cart is never an order: only ids and quantities are sent (the server derives the
 * customer-safe text and prices), it lives in short-lived cache on the server, and a failed send only means the screen
 * catches up on the next change. Sends are debounced, numbered per page instance and run one at a time, so the newest
 * cart always wins; the takeover waits for the last cart send before it. Nothing is sent without a paired screen, and
 * nothing here can block or delay a payment.
 */
export function useCustomerScreenCart({
    lines,
    orderType,
    savedOrderId,
}: {
    lines: CartLine[];
    orderType: OrderType | null;
    savedOrderId: string | null;
}) {
    const paired = useSyncExternalStore(
        stationScreen.subscribe,
        () => stationScreen.get()?.paired === true,
        () => false,
    );
    const sequencer = useRef(createCartSequencer(createClientUuid()));
    const latest = useRef({ lines, orderType, savedOrderId });
    latest.current = { lines, orderType, savedOrderId };

    useEffect(() => {
        if (!paired) return;
        const timer = window.setTimeout(() => {
            const current = latest.current;
            const numbered = sequencer.current.next();
            void enqueueStationWrite(() =>
                stationRequest(cartRoute(), {
                    ...numbered,
                    order_type: current.orderType,
                    saved_order_id: current.savedOrderId,
                    items: current.lines.slice(0, 60).map((line) => ({
                        key: line.key,
                        product_id: line.product.id,
                        quantity: line.quantity,
                        modifiers: line.modifiers,
                    })),
                }),
            ).catch(() => undefined);
        }, CART_DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [paired, lines, orderType, savedOrderId]);

    /** Shows a just-committed order on the paired screen (3 s Dine In / 5 s Take Out); best effort, never awaited. */
    return useCallback((orderId: string) => {
        if (stationScreen.get()?.paired !== true) return;
        void enqueueStationWrite(() =>
            stationRequest(takeoverRoute(), {
                order_id: orderId,
                ...sequencer.current.last(),
            }),
        ).catch(() => undefined);
    }, []);
}
