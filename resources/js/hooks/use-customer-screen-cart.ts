import { useCallback, useEffect, useRef, useSyncExternalStore } from 'react';
import { createClientUuid } from '@/lib/client-uuid';
import {
    createCartSequencer,
    customerScreenCommitHeaders,
} from '@/lib/customer-screen';
import {
    enqueueStationWrite,
    posStationId,
    stationRequest,
    stationScreen,
} from '@/lib/pos-station';
import { cart as cartRoute } from '@/routes/pos/customer-screen';
import type { CartLine, OrderType } from '@/types/pos';

/** Rapid cart edits are coalesced: the screen receives the latest cart once the cashier pauses for this long. */
const CART_DEBOUNCE_MS = 300;

/**
 * Projects this POS station's unfinished cart onto its paired customer screen (Phase 19.6). The cart is never an
 * order: only ids and quantities are sent (the server derives the customer-safe text and prices), it lives in
 * short-lived cache on the server, and a failed send only means the screen catches up on the next change. Sends are
 * debounced, numbered per page instance and run one at a time, so the newest cart always wins.
 *
 * The committed order is confirmed on the screen by the Pay Now / Pay Later request itself: the returned
 * `commitHeaders()` add this station and the last cart number to that request, so there is no second request that
 * could be late, lost or skipped. Nothing here can block or delay a payment.
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

    /** Headers for Pay Now / Pay Later: the server confirms the committed order on the paired screen (best effort). */
    return useCallback(
        () =>
            customerScreenCommitHeaders(
                posStationId(),
                stationScreen.get()?.paired === true,
                sequencer.current.last(),
            ),
        [],
    );
}
