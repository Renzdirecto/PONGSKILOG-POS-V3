import { Head } from '@inertiajs/react';
import { MonitorSmartphone, WifiOff } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { CustomerOrderBoard } from '@/components/customer-order-board';
import { CustomerScreenAds, IdleBrand } from '@/components/customer-screen-ads';
import { CustomerScreenHeader } from '@/components/customer-screen-header';
import { CustomerScreenMenu } from '@/components/customer-screen-menu';
import { CustomerScreenOrderSummary } from '@/components/customer-screen-order-summary';
import { CustomerScreenTakeover } from '@/components/customer-screen-takeover';
import { useCustomerScreen } from '@/hooks/use-customer-screen';
import {
    activeLayer,
    connectionNotice,
    formatPairingCode,
    pendingTakeover,
    takeoverShowMs,
    type CustomerScreenState,
    type CustomerScreenTakeover as Takeover,
} from '@/lib/customer-screen';
import { qrRequest } from '@/lib/qr-http';
import { reset as resetRoute } from '@/routes/customer-screen';

/**
 * The customer-facing counter screen (Phase 19.6). Kiosk layout with its own header (logo, Branch, MENU, CUSTOMER
 * DISPLAY, Fullscreen) and nothing a customer could use to change an order: advertising by default (replaced by the
 * full order summary while the paired POS builds a cart), the browse-only Menu (with the Live Cart on top), or the
 * normal order-number board — plus the dedicated order confirmation above all of them after every committed order.
 */
export default function CustomerScreen({
    screen: initial,
}: {
    screen: CustomerScreenState;
}) {
    const {
        screen,
        connection,
        pairingCode,
        menu,
        playlist,
        refresh,
        pressControl,
        reportTakeoverShown,
    } = useCustomerScreen(initial);
    const paired = screen.status === 'paired';
    const confirmation = useOrderConfirmation(
        paired ? screen.takeover : null,
        reportTakeoverShown,
        screen.sound_url,
    );
    const base = activeLayer(screen, false);
    const notice = paired ? connectionNotice(connection) : null;
    const branchName = screen.branch?.name ?? null;
    const live = connection === 'connected';

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
                {base === 'pairing' ? (
                    <PairingView code={pairingCode} />
                ) : (
                    <>
                        <CustomerScreenHeader
                            branchName={branchName}
                            mode={screen.mode}
                            onControl={pressControl}
                        />
                        {screen.mode === 'ads' && (
                            <CustomerScreenAds
                                playlist={playlist}
                                branchName={branchName}
                                suspended={
                                    base !== 'ads' ||
                                    confirmation.current !== null
                                }
                            />
                        )}
                        {base === 'order_summary' && screen.cart && (
                            <CustomerScreenOrderSummary
                                cart={screen.cart}
                                live={live}
                            />
                        )}
                        {screen.mode === 'menu' && (
                            <CustomerScreenMenu
                                menu={menu}
                                cart={screen.cart}
                                live={live}
                            />
                        )}
                        {screen.mode === 'customer_display' && (
                            <>
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
                {confirmation.current && base !== 'pairing' && (
                    <CustomerScreenTakeover
                        key={confirmation.current.takeover.id}
                        takeover={confirmation.current.takeover}
                        showing={confirmation.current.showing}
                        showMs={confirmation.current.showMs}
                        onReady={confirmation.ready}
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

type Confirmation = { takeover: Takeover; showing: boolean; showMs: number };

/**
 * The order confirmation's lifecycle on this screen, keyed by the server's takeover id:
 * - a new confirmation mounts first ("preparing"); its countdown starts only when the component reports it is really
 *   on screen (the QR rendered for Take Out), then the server is told so a reload shows only the time left;
 * - a refetch during it only refreshes its data (queue rows), never restarts it; a newer order replaces it at once;
 * - when its time is up it is remembered as finished, so the same confirmation is never shown twice; the mode
 *   underneath is whatever the server stores (Menu has already closed to Ads; Customer Display stays).
 * An optional approved sound plays when it appears, if the browser allows audio (after any tap on the screen).
 */
function useOrderConfirmation(
    serverTakeover: Takeover | null,
    reportShown: (takeoverId: string) => void,
    soundUrl: string | null,
) {
    const finished = useRef(new Set<string>());
    const [current, setCurrent] = useState<Confirmation | null>(null);
    const candidate = pendingTakeover(serverTakeover, finished.current);

    useEffect(() => {
        setCurrent((previous) => {
            if (candidate === null) {
                /** Ended on the server: one already counting down finishes on its own timer; one never shown is dropped. */
                return previous !== null && previous.showing ? previous : null;
            }
            if (previous !== null && previous.takeover.id === candidate.id) {
                return { ...previous, takeover: candidate };
            }
            if (previous !== null) {
                finished.current.add(previous.takeover.id);
            }

            return {
                takeover: candidate,
                showing: false,
                showMs: takeoverShowMs(candidate),
            };
        });
    }, [candidate]);

    const ready = useCallback(() => {
        setCurrent((previous) =>
            previous === null || previous.showing
                ? previous
                : {
                      ...previous,
                      showing: true,
                      showMs: takeoverShowMs(previous.takeover),
                  },
        );
    }, []);

    const reportRef = useRef(reportShown);
    reportRef.current = reportShown;
    const soundRef = useRef(soundUrl);
    soundRef.current = soundUrl;
    const announced = useRef<string | null>(null);
    const showingId = current?.showing ? current.takeover.id : null;
    const showMs = current?.showMs ?? 0;
    useEffect(() => {
        if (showingId === null) return;
        if (announced.current !== showingId) {
            announced.current = showingId;
            reportRef.current(showingId);
            playCue(soundRef.current);
        }
        const timer = window.setTimeout(() => {
            finished.current.add(showingId);
            setCurrent((previous) =>
                previous?.takeover.id === showingId ? null : previous,
            );
        }, showMs);

        return () => window.clearTimeout(timer);
    }, [showingId, showMs]);

    return { current, ready };
}

/** The optional customer-screen cue: silently skipped when absent or when the browser blocks autoplay. */
function playCue(url: string | null) {
    if (url === null) return;
    try {
        void new Audio(url).play().catch(() => undefined);
    } catch {
        /** Audio unavailable in this browser: the confirmation is visual anyway. */
    }
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
