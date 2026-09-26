import {
    ExternalLink,
    LayoutList,
    Link2,
    Link2Off,
    MonitorSmartphone,
    UtensilsCrossed,
} from 'lucide-react';
import { useCallback, useEffect, useState, useSyncExternalStore } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import type { CustomerScreenControl as Control } from '@/lib/customer-screen';
import { qrError } from '@/lib/qr-http';
import {
    stationRequest,
    stationScreen,
    StationUnavailableError,
    type StationScreenStatus,
} from '@/lib/pos-station';
import {
    mode as modeRoute,
    pair as pairRoute,
    status as statusRoute,
    unpair as unpairRoute,
} from '@/routes/pos/customer-screen';
import { show as customerScreenRoute } from '@/routes/customer-screen';

const MODE_LABEL = {
    ads: 'Ads',
    menu: 'Menu',
    customer_display: 'Customer Display',
} as const;

/**
 * The customer screen control of this POS station, in the shared Store Operations header (Phase 19.6A). It pairs a
 * screen with this station (by the code the screen shows), unpairs it, and presses the two mutually exclusive
 * controls — MENU and CUSTOMER DISPLAY; with both off the screen plays advertisements. Every press is decided by the
 * server, so the buttons always show the stored mode.
 */
export function CustomerScreenControl({ branchId }: { branchId: string }) {
    const status = useSyncExternalStore(
        stationScreen.subscribe,
        stationScreen.get,
        stationScreen.get,
    );
    const [busy, setBusy] = useState(false);
    const [pairing, setPairing] = useState(false);
    /** The server decides who may run this Branch's POS; without that the control is not shown at all. */
    const [denied, setDenied] = useState(false);

    const load = useCallback(() => {
        stationRequest<StationScreenStatus>(statusRoute())
            .then((next) => {
                setDenied(false);
                stationScreen.set(next);
            })
            .catch((reason: unknown) => {
                if (
                    !(reason instanceof StationUnavailableError) &&
                    [401, 403].includes(qrError(reason).status)
                ) {
                    setDenied(true);
                }
            });
    }, []);
    useEffect(() => {
        stationScreen.set(null);
        load();
    }, [branchId, load]);

    const press = async (control: Control) => {
        if (busy) return;
        setBusy(true);
        try {
            stationScreen.set(
                await stationRequest<StationScreenStatus>(modeRoute(), {
                    control,
                }),
            );
        } catch (reason) {
            toast.error(failureMessage(reason));
            load();
        } finally {
            setBusy(false);
        }
    };

    const unpair = async () => {
        if (
            busy ||
            !window.confirm(
                'Unpair the customer screen from this POS station? It will show a new pairing code.',
            )
        ) {
            return;
        }
        setBusy(true);
        try {
            stationScreen.set(
                await stationRequest<StationScreenStatus>(unpairRoute()),
            );
            toast.success('Customer screen unpaired');
        } catch (reason) {
            toast.error(failureMessage(reason));
        } finally {
            setBusy(false);
        }
    };

    const paired = status?.paired === true;
    const mode = status?.mode ?? 'ads';

    if (denied) {
        return null;
    }

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <button
                        type="button"
                        aria-label={
                            paired
                                ? `Customer screen: ${MODE_LABEL[mode]}`
                                : 'Customer screen: not paired'
                        }
                        className="relative inline-flex size-11 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-neutral-200 bg-white text-[12px] font-semibold text-neutral-700 hover:border-neutral-400 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none min-[1180px]:w-auto min-[1180px]:px-3"
                    >
                        <MonitorSmartphone className="size-[18px]" />
                        <span className="hidden min-[1180px]:inline">
                            {paired ? MODE_LABEL[mode] : 'Screen'}
                        </span>
                        <span
                            aria-hidden="true"
                            className={`absolute top-1.5 right-1.5 size-2 rounded-full ${paired ? 'bg-green-600' : 'bg-neutral-300'}`}
                        />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    sideOffset={8}
                    className="pos-surface w-[320px] max-w-[calc(100vw-24px)] rounded-[14px] border-neutral-200 bg-white p-0 text-neutral-950 shadow-xl"
                >
                    <div className="border-b border-neutral-200 px-4 py-3">
                        <h2 className="text-[13px] font-semibold">
                            Customer screen
                        </h2>
                        <p className="text-[11px] text-neutral-500">
                            {status === null
                                ? 'Checking this POS station…'
                                : paired
                                  ? `Paired with this station · showing ${MODE_LABEL[mode]}`
                                  : 'No screen is paired with this POS station.'}
                        </p>
                    </div>
                    {paired && (
                        <div className="grid grid-cols-2 gap-2 p-3">
                            <ModeButton
                                label="MENU"
                                icon={UtensilsCrossed}
                                active={mode === 'menu'}
                                disabled={busy}
                                onPress={() => void press('menu')}
                            />
                            <ModeButton
                                label="CUSTOMER DISPLAY"
                                icon={LayoutList}
                                active={mode === 'customer_display'}
                                disabled={busy}
                                onPress={() => void press('customer_display')}
                            />
                            <p className="col-span-2 text-[11px] leading-4 text-neutral-500">
                                One can be on at a time. Tap the active one
                                again to turn it off — with both off, the screen
                                plays ads.
                            </p>
                        </div>
                    )}
                    <DropdownMenuSeparator className="my-0" />
                    <div className="p-1.5">
                        {!paired && status !== null && (
                            <DropdownMenuItem
                                className="min-h-11 gap-2 rounded-lg px-3 text-[12.5px] font-semibold"
                                onSelect={() => setPairing(true)}
                            >
                                <Link2 className="size-4" /> Pair customer
                                screen
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem
                            className="min-h-11 gap-2 rounded-lg px-3 text-[12.5px] font-semibold"
                            onSelect={() => {
                                window.open(
                                    customerScreenRoute.url(),
                                    'pongskilog-customer-screen',
                                    'noopener',
                                );
                            }}
                        >
                            <ExternalLink className="size-4" /> Open customer
                            screen on this device
                        </DropdownMenuItem>
                        {paired && (
                            <DropdownMenuItem
                                className="min-h-11 gap-2 rounded-lg px-3 text-[12.5px] font-semibold text-red-700 focus:text-red-700"
                                onSelect={() => void unpair()}
                            >
                                <Link2Off className="size-4" /> Unpair this
                                screen
                            </DropdownMenuItem>
                        )}
                    </div>
                </DropdownMenuContent>
            </DropdownMenu>
            <PairDialog
                open={pairing}
                onOpenChange={setPairing}
                onPaired={(next) => {
                    stationScreen.set(next);
                    setPairing(false);
                    toast.success('Customer screen paired with this station');
                }}
            />
        </>
    );
}

function ModeButton({
    label,
    icon: Icon,
    active,
    disabled,
    onPress,
}: {
    label: string;
    icon: typeof LayoutList;
    active: boolean;
    disabled: boolean;
    onPress: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            disabled={disabled}
            onClick={onPress}
            className={`flex min-h-14 flex-col items-center justify-center gap-1 rounded-xl border px-2 text-[11px] font-black tracking-wide transition disabled:opacity-60 ${active ? 'border-neutral-950 bg-neutral-950 text-white' : 'border-neutral-200 bg-white text-neutral-700 hover:border-neutral-400'}`}
        >
            <Icon className="size-4" />
            {label}
            <span
                className={`text-[9px] font-bold ${active ? 'text-green-300' : 'text-neutral-400'}`}
            >
                {active ? 'ON' : 'OFF'}
            </span>
        </button>
    );
}

function PairDialog({
    open,
    onOpenChange,
    onPaired,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onPaired: (status: StationScreenStatus) => void;
}) {
    const [code, setCode] = useState('');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            onPaired(
                await stationRequest<StationScreenStatus>(pairRoute(), {
                    code,
                }),
            );
            setCode('');
        } catch (reason) {
            setError(failureMessage(reason));
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    setCode('');
                    setError('');
                }
                onOpenChange(next);
            }}
        >
            <DialogContent className="pos-surface rounded-2xl sm:max-w-[420px]">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Pair customer screen</DialogTitle>
                        <DialogDescription>
                            Open the customer screen on the counter display (
                            {customerScreenRoute.url()}), then enter the
                            6-character code it shows. The screen will follow
                            this POS station, whoever is signed in.
                        </DialogDescription>
                    </DialogHeader>
                    <Input
                        value={code}
                        onChange={(event) =>
                            setCode(event.target.value.toUpperCase())
                        }
                        autoFocus
                        autoComplete="off"
                        autoCapitalize="characters"
                        spellCheck={false}
                        maxLength={9}
                        placeholder="ABC 123"
                        aria-label="Pairing code"
                        aria-invalid={error !== '' || undefined}
                        className="h-14 text-center font-mono text-2xl font-black tracking-[0.3em]"
                    />
                    {error && (
                        <p role="alert" className="text-sm text-red-700">
                            {error}
                        </p>
                    )}
                    <DialogFooter>
                        <Button
                            type="submit"
                            disabled={
                                busy || code.replace(/\s|-/g, '').length !== 6
                            }
                            className="min-h-11 w-full sm:w-auto"
                        >
                            {busy ? 'Pairing…' : 'Pair screen'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function failureMessage(reason: unknown): string {
    if (reason instanceof StationUnavailableError) {
        return reason.message;
    }

    return qrError(reason).message;
}
