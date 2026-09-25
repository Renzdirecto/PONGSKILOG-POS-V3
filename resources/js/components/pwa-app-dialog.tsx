import {
    Bell,
    BellOff,
    CheckCircle2,
    Download,
    RefreshCw,
    Smartphone,
    Wifi,
    WifiOff,
} from 'lucide-react';
import { useEffect } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    openPwaAppDialog,
    usePwaConnectivity,
    usePwaInstall,
    usePwaPush,
    usePwaUi,
    usePwaUpdate,
} from '@/hooks/use-pwa';
import { connectivityCopy, lastSyncedText } from '@/lib/pwa-connectivity';
import { installGuidance } from '@/lib/pwa-install';
import { pwaRuntime } from '@/lib/pwa-runtime';
import type { PushState } from '@/lib/pwa-push';

const ACTION =
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 text-[13px] font-semibold focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50';

const PUSH_COPY: Record<PushState, string> = {
    on: 'Notifications are on for this device: New Kitchen Order, Order Ready and important alerts.',
    off: 'Get New Kitchen Order, Order Ready and important alerts on this device, even when PONGSKILOG is in the background.',
    blocked:
        "Notifications are blocked for PONGSKILOG in this browser. Allow them in the browser's site settings, then come back here.",
    'install-first':
        'On iPhone and iPad, notifications work only after PONGSKILOG is added to the Home Screen. Add it first, then open it from the Home Screen and enable notifications there.',
    unsupported: 'This browser does not support notifications for PONGSKILOG.',
    insecure:
        'Notifications need the secure https:// address of PONGSKILOG.',
    unavailable: 'Notifications are not set up on this server yet.',
};

/** Account menus open the app panel; it is rendered once at the app root. */
export function PwaAppMenuItem({ className = 'min-h-10' }: { className?: string }) {
    const ui = usePwaUi();

    if (!ui.active) {
        return null;
    }

    return (
        <DropdownMenuItem
            onSelect={() => openPwaAppDialog()}
            className={className}
        >
            <Smartphone className="size-4" aria-hidden="true" />
            App & notifications
        </DropdownMenuItem>
    );
}

function Section({
    title,
    children,
}: {
    title: string;
    children: React.ReactNode;
}) {
    return (
        <section className="border-t border-neutral-200 pt-4 first:border-t-0 first:pt-0">
            <h3 className="text-[13px] font-bold tracking-tight">{title}</h3>
            <div className="mt-2 text-[12.5px] leading-5 text-neutral-600">
                {children}
            </div>
        </section>
    );
}

/**
 * Install PONGSKILOG, turn device notifications on or off, and see the connection and version status. Nothing here
 * grants access: the server authorizes every page and every notification recipient.
 */
export function PwaAppDialog() {
    const ui = usePwaUi();
    const install = usePwaInstall();
    const push = usePwaPush();
    const connectivity = usePwaConnectivity();
    const update = usePwaUpdate();
    const runtime = pwaRuntime();
    const open = ui.active && ui.appDialogOpen;
    const guidance = installGuidance(install.state);
    const online = connectivity.phase === 'online';
    const lastSynced = lastSyncedText(connectivity.lastSyncedAt);

    useEffect(() => {
        if (open) {
            void runtime?.refreshPush();
        }
    }, [open, runtime]);

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => runtime?.ui.set({ appDialogOpen: next })}
        >
            <DialogContent className="owner-surface max-h-[92dvh] gap-0 overflow-y-auto rounded-[20px] border-neutral-200 bg-white p-0 text-neutral-950 sm:max-w-[440px]">
                <DialogHeader className="border-b border-neutral-200 px-5 py-4 text-left">
                    <DialogTitle className="text-[16px] font-bold">
                        App & notifications
                    </DialogTitle>
                    <DialogDescription className="text-[12px] text-neutral-500">
                        PONGSKILOG on this device. Orders, payments and changes
                        always need internet.
                    </DialogDescription>
                </DialogHeader>
                <div className="flex flex-col gap-4 px-5 py-4">
                    <Section title="Install PONGSKILOG">
                        {install.state === 'installed' ? (
                            <p className="flex items-center gap-2 font-semibold text-green-800">
                                <CheckCircle2 className="size-4" aria-hidden="true" />
                                PONGSKILOG is installed and running as an app.
                            </p>
                        ) : install.state === 'available' ? (
                            <>
                                <p>
                                    Open PONGSKILOG from its own icon, in its
                                    own window.
                                </p>
                                <button
                                    type="button"
                                    onClick={() => void runtime?.promptInstall()}
                                    className={`${ACTION} mt-3 w-full bg-neutral-950 text-white hover:bg-black`}
                                >
                                    <Download className="size-4" aria-hidden="true" />
                                    Install PONGSKILOG
                                </button>
                            </>
                        ) : guidance ? (
                            <>
                                <p className="font-semibold text-neutral-800">
                                    {guidance.title}
                                </p>
                                <ol className="mt-1 list-decimal space-y-1 pl-5">
                                    {guidance.steps.map((step) => (
                                        <li key={step}>{step}</li>
                                    ))}
                                </ol>
                            </>
                        ) : null}
                    </Section>

                    <Section title="Notifications">
                        <p>{PUSH_COPY[push.state]}</p>
                        {(push.state === 'off' || push.state === 'on') && (
                            <button
                                type="button"
                                disabled={push.busy || !push.checked || !online}
                                onClick={() =>
                                    void (push.state === 'on'
                                        ? runtime?.disablePush()
                                        : runtime?.enablePush())
                                }
                                className={`${ACTION} mt-3 w-full ${push.state === 'on' ? 'border border-neutral-200 bg-white text-neutral-800 hover:bg-neutral-50' : 'bg-neutral-950 text-white hover:bg-black'}`}
                            >
                                {push.state === 'on' ? (
                                    <BellOff className="size-4" aria-hidden="true" />
                                ) : (
                                    <Bell className="size-4" aria-hidden="true" />
                                )}
                                {push.busy
                                    ? 'Please wait…'
                                    : push.state === 'on'
                                      ? 'Turn off notifications'
                                      : 'Enable Notifications'}
                            </button>
                        )}
                        {(push.state === 'off' || push.state === 'on') &&
                            !online && (
                                <p className="mt-2 text-[12px] text-amber-800">
                                    Reconnect to change notifications.
                                </p>
                            )}
                        <p className="mt-2 text-[11.5px] text-neutral-500">
                            Sound and vibration follow this device's
                            notification settings. The open Kitchen and POS
                            screens keep their own sounds.
                        </p>
                    </Section>

                    <Section title="Connection">
                        <p className="flex items-center gap-2 font-semibold text-neutral-800">
                            {online ? (
                                <Wifi className="size-4" aria-hidden="true" />
                            ) : (
                                <WifiOff className="size-4" aria-hidden="true" />
                            )}
                            {connectivityCopy(connectivity.phase).title}
                        </p>
                        {!online && (
                            <p className="mt-1">
                                {connectivityCopy(connectivity.phase).detail}
                                {lastSynced ? ` ${lastSynced}.` : ''}
                            </p>
                        )}
                    </Section>

                    <Section title="Version">
                        {update.available ? (
                            <>
                                <p>
                                    A new version of PONGSKILOG is ready.
                                    Updating reloads the app.
                                </p>
                                {update.blockers.length > 0 && (
                                    <p className="mt-2 rounded-lg border border-amber-200 bg-amber-50 p-2 text-[12px] text-amber-900">
                                        The update will wait:{' '}
                                        {update.blockers.join(' ')}
                                    </p>
                                )}
                                <button
                                    type="button"
                                    disabled={
                                        update.blockers.length > 0 ||
                                        update.applying
                                    }
                                    onClick={() => runtime?.requestUpdate()}
                                    className={`${ACTION} mt-3 w-full bg-neutral-950 text-white hover:bg-black`}
                                >
                                    <RefreshCw className="size-4" aria-hidden="true" />
                                    {update.applying ? 'Updating…' : 'Update now'}
                                </button>
                            </>
                        ) : (
                            <p>PONGSKILOG is up to date.</p>
                        )}
                    </Section>
                </div>
            </DialogContent>
        </Dialog>
    );
}
