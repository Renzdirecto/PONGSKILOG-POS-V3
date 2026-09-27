import { LayoutList, Maximize, Minimize, UtensilsCrossed } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { LucideIcon } from 'lucide-react';
import {
    fullscreenSupported,
    type CustomerScreenControl,
    type CustomerScreenMode,
} from '@/lib/customer-screen';

/**
 * The customer screen's persistent header (Phase 19.6): logo, Branch name, the MENU and CUSTOMER DISPLAY controls
 * and Fullscreen. MENU / CUSTOMER DISPLAY press the same single stored mode as the POS header (the server toggles it:
 * one on at a time, the active one again → Ads). Fullscreen only changes how the browser shows this page; entering or
 * leaving it never touches pairing, mode or cart. Compact, with large tap targets readable from counter distance.
 */
export function CustomerScreenHeader({
    branchName,
    mode,
    onControl,
}: {
    branchName: string | null;
    mode: CustomerScreenMode;
    onControl: (control: CustomerScreenControl) => Promise<void>;
}) {
    const [busy, setBusy] = useState(false);
    const press = (control: CustomerScreenControl) => {
        if (busy) return;
        setBusy(true);
        onControl(control)
            .catch(() => undefined)
            .finally(() => setBusy(false));
    };

    return (
        <header className="relative z-10 flex min-h-[60px] shrink-0 items-center gap-2 border-b border-white/10 bg-[#0f1010] px-3 py-2 sm:gap-3 sm:px-5">
            <img
                src="/images/branding/logo.png"
                alt="PONGSKILOG"
                className="w-[48px] shrink-0 sm:w-[64px]"
            />
            <p className="min-w-0 flex-1 truncate text-sm font-bold text-white/80 sm:text-lg">
                {branchName}
            </p>
            <nav
                aria-label="Screen display"
                className="flex shrink-0 items-center gap-1.5 sm:gap-2"
            >
                <HeaderToggle
                    label="MENU"
                    icon={UtensilsCrossed}
                    active={mode === 'menu'}
                    disabled={busy}
                    onPress={() => press('menu')}
                />
                <HeaderToggle
                    label="CUSTOMER DISPLAY"
                    shortLabel="DISPLAY"
                    icon={LayoutList}
                    active={mode === 'customer_display'}
                    disabled={busy}
                    onPress={() => press('customer_display')}
                />
                <FullscreenButton />
            </nav>
        </header>
    );
}

function HeaderToggle({
    label,
    shortLabel,
    icon: Icon,
    active,
    disabled,
    onPress,
}: {
    label: string;
    shortLabel?: string;
    icon: LucideIcon;
    active: boolean;
    disabled: boolean;
    onPress: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            aria-label={label}
            disabled={disabled}
            onClick={onPress}
            className={`inline-flex min-h-11 min-w-11 items-center justify-center gap-1.5 rounded-xl border px-2.5 text-[11px] font-black tracking-[0.08em] transition-colors disabled:opacity-60 sm:px-3.5 sm:text-xs ${active ? 'border-[#f5c542] bg-[#f5c542] text-neutral-950' : 'border-white/15 bg-white/5 text-white/80 hover:bg-white/10'}`}
        >
            <Icon className="size-4 shrink-0" />
            <span className="hidden min-[520px]:inline">
                {shortLabel ? (
                    <>
                        <span className="min-[900px]:hidden">{shortLabel}</span>
                        <span className="hidden min-[900px]:inline">
                            {label}
                        </span>
                    </>
                ) : (
                    label
                )}
            </span>
        </button>
    );
}

/** Browser fullscreen where supported; hidden where the browser cannot do it (e.g. iPhone Safari). */
function FullscreenButton() {
    const [supported] = useState(() =>
        typeof document === 'undefined' ? false : fullscreenSupported(document),
    );
    const [active, setActive] = useState(
        () =>
            typeof document !== 'undefined' &&
            document.fullscreenElement !== null,
    );
    useEffect(() => {
        if (!supported) return;
        const sync = () => setActive(document.fullscreenElement !== null);
        document.addEventListener('fullscreenchange', sync);

        return () => document.removeEventListener('fullscreenchange', sync);
    }, [supported]);

    if (!supported) {
        return null;
    }

    return (
        <button
            type="button"
            aria-label={active ? 'Exit fullscreen' : 'Fullscreen'}
            aria-pressed={active}
            onClick={() => {
                try {
                    void Promise.resolve(
                        active
                            ? document.exitFullscreen()
                            : document.documentElement.requestFullscreen(),
                    ).catch(() => undefined);
                } catch {
                    /** Refused by the browser (e.g. no user gesture): the page simply stays as it is. */
                }
            }}
            className="inline-flex size-11 shrink-0 items-center justify-center rounded-xl border border-white/15 bg-white/5 text-white/80 hover:bg-white/10"
        >
            {active ? (
                <Minimize className="size-4" />
            ) : (
                <Maximize className="size-4" />
            )}
        </button>
    );
}
