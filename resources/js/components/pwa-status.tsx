import { Download, RefreshCw, WifiOff } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import {
    usePwaConnectivity,
    usePwaStatusSlot,
    usePwaUi,
    usePwaUpdate,
} from '@/hooks/use-pwa';
import { connectivityCopy, lastSyncedText } from '@/lib/pwa-connectivity';
import { pwaRuntime } from '@/lib/pwa-runtime';

type Tone = 'light' | 'dark';

const PILL =
    'inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full border px-2.5 text-[10px] font-semibold focus-visible:ring-2 focus-visible:outline-none md:px-[11px] md:text-[11.5px]';

const TONES: Record<Tone, Record<'offline' | 'reconnecting' | 'update', string>> =
    {
        light: {
            offline:
                'border-red-200 bg-red-50 text-red-800 focus-visible:ring-red-700',
            reconnecting:
                'border-amber-200 bg-amber-50 text-amber-900 focus-visible:ring-amber-700',
            update: 'border-neutral-300 bg-white text-neutral-800 hover:border-neutral-400 focus-visible:ring-neutral-950',
        },
        dark: {
            offline:
                'border-red-300/40 bg-red-500/15 text-red-100 focus-visible:ring-red-200',
            reconnecting:
                'border-amber-200/40 bg-amber-400/15 text-amber-100 focus-visible:ring-amber-100',
            update: 'border-white/25 bg-white/10 text-white hover:bg-white/15 focus-visible:ring-white',
        },
    };

const PANEL =
    'absolute top-[calc(100%+8px)] right-0 z-50 w-[min(300px,calc(100vw-24px))] rounded-[14px] border border-neutral-200 bg-white p-4 text-left text-neutral-950 shadow-xl';

const PANEL_BUTTON =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-3 text-[13px] font-semibold focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';

/**
 * A pill with an inline detail panel. The panel is rendered next to the pill (no portal), so it stays visible inside a
 * fullscreen Kitchen board; it closes on Escape or an outside tap.
 */
function StatusDisclosure({
    className,
    label,
    trigger,
    children,
}: {
    className: string;
    label: string;
    trigger: React.ReactNode;
    children: (close: () => void) => React.ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const panelId = useId();
    const root = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) {
            return;
        }
        const outside = (event: PointerEvent) => {
            if (!root.current?.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };
        document.addEventListener('pointerdown', outside);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    return (
        <div ref={root} className="relative shrink-0">
            <button
                type="button"
                aria-label={label}
                aria-expanded={open}
                aria-controls={panelId}
                onClick={() => setOpen((current) => !current)}
                className={className}
            >
                {trigger}
            </button>
            {open && (
                <div
                    id={panelId}
                    role="region"
                    aria-label={label}
                    className={`pos-surface ${PANEL}`}
                >
                    {children(() => setOpen(false))}
                </div>
            )}
        </div>
    );
}

/**
 * The app status for a header: nothing while online and current; an Offline / Reconnecting pill (with "Last
 * synced") when the server is not confirmed, and an Update pill when a new version is ready.
 */
export function PwaStatus({
    tone = 'light',
    floating = false,
}: {
    tone?: Tone;
    floating?: boolean;
}) {
    const ui = usePwaUi();
    const connectivity = usePwaConnectivity();
    const update = usePwaUpdate();
    usePwaStatusSlot(!floating);

    if (!ui.active) {
        return null;
    }

    const offline = connectivity.phase !== 'online';
    if (!offline && !update.available) {
        return null;
    }
    const copy = connectivityCopy(connectivity.phase);
    const lastSynced = lastSyncedText(connectivity.lastSyncedAt);
    const blocked = update.blockers.length > 0;
    const runtime = pwaRuntime();

    return (
        <div className="flex shrink-0 items-center gap-2">
            {offline && (
                <StatusDisclosure
                    className={`${PILL} ${TONES[tone][connectivity.phase === 'offline' ? 'offline' : 'reconnecting']}`}
                    label={[copy.title, lastSynced].filter(Boolean).join('. ')}
                    trigger={
                        <>
                            {connectivity.phase === 'offline' ? (
                                <WifiOff className="size-3.5" aria-hidden="true" />
                            ) : (
                                <RefreshCw
                                    className="size-3.5 motion-safe:animate-spin"
                                    aria-hidden="true"
                                />
                            )}
                            <span>{copy.label}</span>
                            {lastSynced && (
                                <span className="hidden font-medium opacity-80 lg:inline">
                                    · {lastSynced}
                                </span>
                            )}
                        </>
                    }
                >
                    {() => (
                        <>
                            <p className="text-[13px] font-bold">{copy.title}</p>
                            <p className="mt-1 text-[12px] leading-5 text-neutral-600">
                                {copy.detail}
                            </p>
                            {lastSynced && (
                                <p className="mt-2 text-[12px] font-semibold">
                                    {lastSynced}
                                </p>
                            )}
                            <button
                                type="button"
                                onClick={() => runtime?.connectivity.retry()}
                                className={`${PANEL_BUTTON} mt-3 w-full bg-neutral-950 text-white hover:bg-black`}
                            >
                                <RefreshCw className="size-4" aria-hidden="true" />
                                Retry now
                            </button>
                        </>
                    )}
                </StatusDisclosure>
            )}
            {update.available && (
                <StatusDisclosure
                    className={`${PILL} ${TONES[tone].update}`}
                    label="PONGSKILOG update available"
                    trigger={
                        <>
                            <Download className="size-3.5" aria-hidden="true" />
                            <span>Update</span>
                        </>
                    }
                >
                    {(close) => (
                        <>
                            <p className="text-[13px] font-bold">
                                {update.source === 'activated-elsewhere'
                                    ? 'PONGSKILOG was updated'
                                    : 'PONGSKILOG update available'}
                            </p>
                            <p className="mt-1 text-[12px] leading-5 text-neutral-600">
                                Updating reloads PONGSKILOG. Everything already
                                saved stays on the server.
                            </p>
                            {blocked && (
                                <p className="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-2 text-[12px] leading-5 text-amber-900">
                                    The update will wait: {update.blockers.join(' ')}
                                </p>
                            )}
                            <div className="mt-3 grid grid-cols-2 gap-2">
                                <button
                                    type="button"
                                    disabled={blocked || update.applying}
                                    onClick={() => runtime?.requestUpdate()}
                                    className={`${PANEL_BUTTON} bg-neutral-950 text-white hover:bg-black`}
                                >
                                    {update.applying ? 'Updating…' : 'Update now'}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => {
                                        runtime?.update.snooze();
                                        close();
                                    }}
                                    className={`${PANEL_BUTTON} border border-neutral-200 bg-white text-neutral-800 hover:bg-neutral-50`}
                                >
                                    Later
                                </button>
                            </div>
                        </>
                    )}
                </StatusDisclosure>
            )}
        </div>
    );
}

/** For pages without a header slot (settings, sign-in): the same status, floating at the top right. */
export function PwaFloatingStatus() {
    const ui = usePwaUi();

    if (!ui.active || ui.statusSlots > 0) {
        return null;
    }

    return (
        <div className="fixed top-[max(8px,env(safe-area-inset-top))] right-[max(8px,env(safe-area-inset-right))] z-40 print:hidden">
            <PwaStatus floating />
        </div>
    );
}
