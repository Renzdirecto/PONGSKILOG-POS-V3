import { CheckCircle2, QrCode } from 'lucide-react';
import {
    orderTypeText,
    queuePositionText,
    type CustomerScreenTakeover as Takeover,
} from '@/lib/customer-screen';

/**
 * The temporary successful-order confirmation (3 s Dine In / 5 s Take Out) above whatever mode is selected: a large
 * green order number, the order type, the server-derived position in that order type's queue and, for Take Out, the
 * pickup QR (large enough to scan from a phone at arm's length). It never changes the selected mode.
 */
export function CustomerScreenTakeover({
    takeover,
    remainingMs,
}: {
    takeover: Takeover;
    remainingMs: number;
}) {
    const queue = queuePositionText(
        takeover.order_type,
        takeover.queue_position,
    );
    const pickup = takeover.order_type === 'take_out' ? takeover.pickup : null;

    return (
        <div
            role="status"
            aria-live="assertive"
            className="animate-in fade-in fixed inset-0 z-50 flex flex-col overflow-y-auto bg-[#07130d] text-white duration-300"
        >
            <div
                aria-hidden="true"
                className="h-1.5 shrink-0 origin-left bg-emerald-400"
                style={{
                    animation: `customer-screen-countdown ${remainingMs}ms linear forwards`,
                }}
            />
            <div
                className={`m-auto grid w-full max-w-6xl items-center gap-8 px-6 py-8 ${pickup ? 'min-[900px]:grid-cols-[minmax(0,1fr)_auto]' : ''}`}
            >
                <div className="flex flex-col items-center gap-4 text-center">
                    <p className="flex items-center gap-2 text-[clamp(16px,2.6vmin,26px)] font-bold text-emerald-200">
                        <CheckCircle2 className="size-[1.2em]" /> Thank you!
                        Your order is placed
                    </p>
                    <p className="text-[clamp(18px,3vmin,30px)] font-bold tracking-[0.2em] text-white/60 uppercase">
                        Order
                    </p>
                    <p className="text-[clamp(96px,24vmin,280px)] leading-none font-black tracking-tight text-emerald-400 tabular-nums">
                        #{takeover.order_number}
                    </p>
                    <span className="rounded-full border border-emerald-400/40 bg-emerald-400/10 px-5 py-1.5 text-[clamp(16px,2.8vmin,28px)] font-black tracking-[0.18em]">
                        {orderTypeText(takeover.order_type)}
                    </span>
                    {queue && (
                        <p className="text-[clamp(18px,3.4vmin,36px)] font-bold">
                            {queue}
                        </p>
                    )}
                </div>
                {pickup && (
                    <div className="mx-auto flex w-full max-w-[380px] flex-col items-center gap-3 rounded-3xl bg-white p-5 text-center text-neutral-950 shadow-2xl">
                        <img
                            src={pickup.qr_image}
                            alt={`Pickup QR for order ${takeover.order_number}`}
                            className="aspect-square w-full max-w-[320px]"
                        />
                        <p className="flex items-center gap-2 text-base font-black">
                            <QrCode className="size-5" /> Scan to track your
                            order
                        </p>
                        <p className="text-sm text-neutral-600">
                            See when it’s ready and get notified on your phone.
                        </p>
                    </div>
                )}
            </div>
        </div>
    );
}
