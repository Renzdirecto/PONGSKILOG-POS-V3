import { Head } from '@inertiajs/react';
import { Clock3, MonitorSmartphone, WifiOff } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { CustomerOrderBoard } from '@/components/customer-order-board';
import { CustomerScreenAds, IdleBrand } from '@/components/customer-screen-ads';
import { CustomerScreenMenu } from '@/components/customer-screen-menu';
import { CustomerScreenTakeover } from '@/components/customer-screen-takeover';
import { useCustomerScreen } from '@/hooks/use-customer-screen';
import {
    activeLayer,
    connectionNotice,
    formatPairingCode,
    showsLiveCart,
    takeoverRemainingMs,
    type CustomerScreenState,
    type CustomerScreenTakeover as Takeover,
} from '@/lib/customer-screen';
import { qrRequest } from '@/lib/qr-http';
import { reset as resetRoute } from '@/routes/customer-screen';

/**
 * The customer-facing counter screen (Phase 19.6A). Kiosk layout, no staff navigation and nothing a customer could
 * use to change an order: advertising by default, the browse-only Menu (with the paired POS station's Live Cart on
 * top), or the existing order-number board — plus the temporary successful-order takeover above all of them.
 */
export default function CustomerScreen({
    screen: initial,
}: {
    screen: CustomerScreenState;
}) {
    const { screen, connection, pairingCode, menu, playlist, refresh } =
        useCustomerScreen(initial);
    const takeover = useTakeover(screen.takeover);
    const layer = activeLayer(screen, takeover !== null);
    const paired = screen.status === 'paired';
    const notice = paired ? connectionNotice(connection) : null;
    const branchName = screen.branch?.name ?? null;

    return (
        <>
            <Head title="Customer screen" />
            <div className="relative flex h-dvh flex-col overflow-hidden bg-[#0f1010] pt-[env(safe-area-inset-top)] pr-[env(safe-area-inset-right)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] text-white select-none">
                {paired && (
                    <ResetCorner
                        onReset={() => {
                            qrRequest(resetRoute())
                                .catch(() => undefined)
                                .finally(refresh);
                        }}
                    />
                )}
                {layer === 'pairing' ? (
                    <PairingView code={pairingCode} />
                ) : (
                    <>
                        {screen.mode === 'ads' && (
                            <CustomerScreenAds
                                playlist={playlist}
                                branchName={branchName}
                            />
                        )}
                        {screen.mode === 'menu' && (
                            <>
                                <ScreenHeader
                                    title={
                                        showsLiveCart(screen.mode, screen.cart)
                                            ? 'Menu · Your order'
                                            : 'Menu'
                                    }
                                    branchName={branchName}
                                />
                                <CustomerScreenMenu
                                    menu={menu}
                                    cart={screen.cart}
                                    live={connection === 'connected'}
                                />
                            </>
                        )}
                        {screen.mode === 'customer_display' && (
                            <>
                                <ScreenHeader
                                    title="Order status"
                                    branchName={branchName}
                                />
                                {screen.board ? (
                                    <CustomerOrderBoard
                                        display={screen.board}
                                    />
                                ) : (
                                    <IdleBrand branchName={branchName} />
                                )}
                                <footer className="shrink-0 border-t border-white/10 px-5 py-3 text-center text-sm font-semibold text-white/65 sm:text-base">
                                    Please listen for your number. Salamat po!
                                </footer>
                            </>
                        )}
                    </>
                )}
                {takeover && (
                    <CustomerScreenTakeover
                        key={takeover.takeover.id}
                        takeover={takeover.takeover}
                        remainingMs={takeover.remaining}
                    />
                )}
                {notice && (
                    <p
                        role="status"
                        className="pointer-events-none fixed right-3 bottom-3 z-[60] inline-flex items-center gap-1.5 rounded-full bg-amber-400/95 px-3 py-1.5 text-xs font-bold text-neutral-950 shadow-lg"
                    >
                        <WifiOff className="size-3.5" /> {notice}
                    </p>
                )}
            </div>
        </>
    );
}

/**
 * Shows each takeover once, for the time the server says is left (bounded by 3 s / 5 s), keyed by its id: a refetch
 * during the takeover never restarts it, and the server ending it hides it at once. The mode underneath stays mounted,
 * so the screen returns to exactly where it was.
 */
function useTakeover(
    current: Takeover | null,
): { takeover: Takeover; remaining: number } | null {
    const [shown, setShown] = useState<{
        takeover: Takeover;
        remaining: number;
    } | null>(null);
    const latest = useRef(current);
    latest.current = current;
    const id = current?.id ?? null;

    useEffect(() => {
        const value = latest.current;
        const remaining = value === null ? 0 : takeoverRemainingMs(value);
        if (id === null || value === null || remaining <= 0) {
            setShown(null);

            return;
        }
        setShown({ takeover: value, remaining });
        const timer = window.setTimeout(() => setShown(null), remaining);

        return () => window.clearTimeout(timer);
    }, [id]);

    return shown;
}

function ScreenHeader({
    title,
    branchName,
}: {
    title: string;
    branchName: string | null;
}) {
    const [clock, setClock] = useState(() => new Date());
    useEffect(() => {
        const timer = window.setInterval(() => setClock(new Date()), 15_000);

        return () => window.clearInterval(timer);
    }, []);

    return (
        <header className="flex min-h-[64px] shrink-0 items-center gap-3 border-b border-white/10 px-4 py-2 sm:px-6">
            <img
                src="/images/branding/logo.png"
                alt="PONGSKILOG"
                className="w-[58px] sm:w-[68px]"
            />
            <div className="min-w-0 flex-1">
                <h1 className="truncate text-lg font-black tracking-tight sm:text-2xl">
                    {title}
                </h1>
                {branchName && (
                    <p className="truncate text-xs text-white/50">
                        {branchName}
                    </p>
                )}
            </div>
            <span className="flex items-center gap-2 text-sm font-bold text-white/80 tabular-nums sm:text-lg">
                <Clock3 className="size-4 text-white/45" />
                {clock.toLocaleTimeString([], {
                    hour: '2-digit',
                    minute: '2-digit',
                })}
            </span>
        </header>
    );
}

function PairingView({
    code,
}: {
    code: { code: string; expires_at: string } | null;
}) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 1000);

        return () => window.clearInterval(timer);
    }, []);
    const seconds = code
        ? Math.max(
              0,
              Math.round((new Date(code.expires_at).getTime() - now) / 1000),
          )
        : 0;

    return (
        <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-6 overflow-y-auto px-6 py-10 text-center">
            <img
                src="/images/branding/pongskilog-emblem.png"
                alt=""
                className="size-24 rounded-full sm:size-28"
            />
            <div>
                <h1 className="text-2xl font-black sm:text-4xl">
                    Customer screen
                </h1>
                <p className="mt-2 text-sm text-white/60 sm:text-lg">
                    Pair this screen with a POS station
                </p>
            </div>
            <div className="rounded-3xl border border-white/10 bg-white/5 px-8 py-6">
                <p className="text-xs font-bold tracking-[0.25em] text-white/50 uppercase">
                    Pairing code
                </p>
                <p className="mt-2 font-mono text-[clamp(44px,12vmin,110px)] font-black tracking-[0.12em] text-[#f5c542]">
                    {code ? formatPairingCode(code.code) : '— — —'}
                </p>
                <p className="mt-1 text-xs text-white/45 tabular-nums">
                    {code
                        ? `New code in ${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`
                        : 'Getting a pairing code…'}
                </p>
            </div>
            <p className="flex max-w-md items-start gap-2 text-left text-sm text-white/65 sm:text-base">
                <MonitorSmartphone className="mt-0.5 size-5 shrink-0 text-white/40" />
                On the POS, open the customer screen button in the top bar,
                choose Pair customer screen and enter this code.
            </p>
        </div>
    );
}

/** A hidden staff reset: press and hold the top-left corner for 3 seconds to unpair this screen (e.g. a lost station). */
function ResetCorner({ onReset }: { onReset: () => void }) {
    const timer = useRef<number | undefined>(undefined);
    const cancel = () => window.clearTimeout(timer.current);

    return (
        <button
            type="button"
            aria-label="Screen setup: press and hold for 3 seconds to unpair this screen"
            className="absolute top-0 left-0 z-[70] size-16 opacity-0"
            onPointerDown={() => {
                cancel();
                timer.current = window.setTimeout(() => {
                    if (
                        window.confirm(
                            'Unpair this customer screen? It will show a new pairing code for a POS to enter.',
                        )
                    ) {
                        onReset();
                    }
                }, 3000);
            }}
            onPointerUp={cancel}
            onPointerLeave={cancel}
            onPointerCancel={cancel}
            onContextMenu={(event) => event.preventDefault()}
        />
    );
}
