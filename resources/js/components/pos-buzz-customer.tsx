import { router } from '@inertiajs/react';
import { BellRing, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { createClientUuid } from '@/lib/client-uuid';
import { relativePlacedTime } from '@/lib/kitchen';
import { qrError, qrRequest } from '@/lib/qr-http';
import { buzz as buzzRoute } from '@/routes/pos/orders';
import type { PosReadyBuzz, PosReadyOrder } from '@/types';

/** The server's cooldown after an accepted Buzz (the server enforces it; this only paces the button). */
const COOLDOWN_MS = 5000;

/**
 * Buzz Customer on the existing Ready notification (Phase 19.6B). Rendered only when the server says this Ready Take
 * Out order's customer turned notifications on (`order.buzz`); otherwise there is no button at all. The server
 * decides every Buzz: 5-second cooldown, at most 5 per order, Ready + Take Out + Branch checks. A sent Buzz is a
 * queued push to the customer's phone; it never changes the order.
 */
export function BuzzCustomerButton({ order }: { order: PosReadyOrder }) {
    const [latest, setLatest] = useState<PosReadyBuzz | null>(order.buzz);
    const [cooldownEndsAt, setCooldownEndsAt] = useState(() =>
        localCooldownEnd(order.buzz),
    );
    const [busy, setBusy] = useState(false);
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        setLatest(order.buzz);
        setCooldownEndsAt((current) =>
            Math.max(current, localCooldownEnd(order.buzz)),
        );
    }, [order.buzz]);

    const coolingDown = cooldownEndsAt > now;
    useEffect(() => {
        if (!coolingDown) return;
        const timer = window.setInterval(() => setNow(Date.now()), 250);

        return () => window.clearInterval(timer);
    }, [coolingDown]);

    if (latest === null) {
        return null;
    }
    const exhausted = latest.remaining <= 0;

    const send = async () => {
        if (busy || coolingDown || exhausted) return;
        setBusy(true);
        try {
            const result = await qrRequest<{ buzz: PosReadyBuzz }>(
                buzzRoute(order.id),
                { idempotency_key: createClientUuid() },
            );
            setLatest(result.buzz);
            setCooldownEndsAt(Date.now() + COOLDOWN_MS);
            setNow(Date.now());
            toast.success(`Buzz sent to order #${order.number}`);
        } catch (reason) {
            const failure = qrError(reason);
            if (failure.status === 429) {
                setCooldownEndsAt(Date.now() + COOLDOWN_MS);
                setNow(Date.now());
                toast.info('Wait a few seconds before buzzing again.');
            } else {
                toast.error(failure.message);
                router.reload({ only: ['readyOrders'] });
            }
        } finally {
            setBusy(false);
        }
    };

    const seconds = Math.ceil((cooldownEndsAt - now) / 1000);

    return (
        <div className="flex min-w-0 flex-col gap-1 sm:mr-auto">
            <button
                type="button"
                onClick={send}
                disabled={busy || coolingDown || exhausted}
                className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-amber-300 bg-amber-50 px-5 text-sm font-bold text-amber-900 transition hover:bg-amber-100 disabled:cursor-not-allowed disabled:opacity-60"
            >
                {busy ? (
                    <LoaderCircle className="size-4 animate-spin" />
                ) : (
                    <BellRing className="size-4" />
                )}
                {exhausted
                    ? `Buzz limit reached (${latest.count} of ${latest.max})`
                    : coolingDown
                      ? `Notified · wait ${seconds}s`
                      : latest.count > 0
                        ? `Buzz again (${latest.count} of ${latest.max})`
                        : 'Buzz Customer'}
            </button>
            <p className="text-[11px] text-neutral-500" aria-live="polite">
                {exhausted
                    ? 'No more buzzes for this order. Call the order number instead.'
                    : latest.last_buzzed_at
                      ? `Last notified ${relativePlacedTime(latest.last_buzzed_at, now).toLowerCase()}`
                      : 'The customer turned on pickup notifications.'}
            </p>
        </div>
    );
}

/** A cooldown reported by the server (another cashier device buzzed), bounded so a skewed clock cannot freeze the button. */
function localCooldownEnd(buzz: PosReadyBuzz | null): number {
    if (!buzz?.cooldown_until) return 0;
    const until = Date.parse(buzz.cooldown_until);

    return Number.isNaN(until) ? 0 : Math.min(until, Date.now() + COOLDOWN_MS);
}
