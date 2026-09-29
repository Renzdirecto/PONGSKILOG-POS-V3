import { router } from '@inertiajs/react';
import { Save } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { controlClass, primaryActionClass } from '@/components/catalog-ui';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { qrError, qrRequest } from '@/lib/qr-http';
import {
    show as settingsRoute,
    update as updateSettingsRoute,
} from '@/routes/branches/customer-screen-settings';

type Settings = {
    dine_in_success_seconds: number;
    take_out_success_seconds: number;
    facebook_url: string | null;
    website_url: string | null;
    maps_url: string | null;
    limits: { min_seconds: number; max_seconds: number };
};

type Form = {
    dine_in_success_seconds: string;
    take_out_success_seconds: string;
    facebook_url: string;
    website_url: string;
    maps_url: string;
};

const LINKS = [
    ['facebook_url', 'Facebook', 'https://facebook.com/…'],
    ['website_url', 'Website', 'https://…'],
    ['maps_url', 'Maps', 'https://maps.google.com/…'],
] as const;

function formOf(settings: Settings): Form {
    return {
        dine_in_success_seconds: String(settings.dine_in_success_seconds),
        take_out_success_seconds: String(settings.take_out_success_seconds),
        facebook_url: settings.facebook_url ?? '',
        website_url: settings.website_url ?? '',
        maps_url: settings.maps_url ?? '',
    };
}

/**
 * Settings › Customer Screen (Phase 19.6): how long the order confirmation stays on this Branch's customer screens
 * once it is showing (Dine In / Take Out, 3–15 s) and the optional customer links on the pickup page. Facebook and
 * Website are the same links as the Customer QR page. The server validates every value (http/https links only).
 */
export function CustomerScreenSettingsPanel({
    branchId,
}: {
    branchId: string;
}) {
    const [settings, setSettings] = useState<Settings | null>(null);
    const [form, setForm] = useState<Form | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            const result = await qrRequest<{ settings: Settings }>(
                settingsRoute(branchId),
            );
            setSettings(result.settings);
            setForm(formOf(result.settings));
            setError('');
        } catch (reason) {
            setError(qrError(reason).message);
        }
    }, [branchId]);
    useEffect(() => {
        void load();
    }, [load]);

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (busy || form === null) return;
        setBusy(true);
        setError('');
        try {
            const result = await qrRequest<{ settings: Settings }>(
                updateSettingsRoute(branchId),
                {
                    dine_in_success_seconds: Number(
                        form.dine_in_success_seconds,
                    ),
                    take_out_success_seconds: Number(
                        form.take_out_success_seconds,
                    ),
                    facebook_url: form.facebook_url.trim() || null,
                    website_url: form.website_url.trim() || null,
                    maps_url: form.maps_url.trim() || null,
                },
            );
            setSettings(result.settings);
            setForm(formOf(result.settings));
            toast.success('Customer screen settings saved');
            /** Facebook / Website are shared with the Receipt & QR tab: refresh the page's Branch data too. */
            router.reload({ only: ['branches'] });
        } catch (reason) {
            setError(qrError(reason).message);
        } finally {
            setBusy(false);
        }
    };

    if (form === null || settings === null) {
        return error ? (
            <p role="alert" className="text-sm text-red-700">
                {error}
            </p>
        ) : (
            <p className="flex items-center gap-2 text-sm text-neutral-500">
                <Spinner /> Loading settings…
            </p>
        );
    }
    const seconds = Array.from(
        {
            length:
                settings.limits.max_seconds - settings.limits.min_seconds + 1,
        },
        (_, index) => settings.limits.min_seconds + index,
    );
    const set = (key: keyof Form, value: string) =>
        setForm((current) =>
            current ? { ...current, [key]: value } : current,
        );

    return (
        <form
            onSubmit={submit}
            className="flex flex-col gap-4 rounded-[14px] border border-neutral-200 bg-neutral-50 p-4"
        >
            <div className="flex flex-col gap-0.5">
                <h3 className="text-[14px] font-bold">Order confirmation</h3>
                <p className="text-xs text-neutral-500">
                    After payment the screen shows the order number, queue
                    position and (Take Out) the pickup QR. The countdown starts
                    once it is fully on screen.
                </p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                {(
                    [
                        ['dine_in_success_seconds', 'Dine In shows for'],
                        ['take_out_success_seconds', 'Take Out shows for'],
                    ] as const
                ).map(([key, label]) => (
                    <label
                        key={key}
                        className="flex flex-col gap-1.5 text-xs font-semibold"
                    >
                        {label}
                        <select
                            value={form[key]}
                            onChange={(event) => set(key, event.target.value)}
                            className={controlClass}
                        >
                            {seconds.map((value) => (
                                <option key={value} value={value}>
                                    {value} seconds
                                </option>
                            ))}
                        </select>
                    </label>
                ))}
            </div>
            <div className="flex flex-col gap-0.5 border-t border-neutral-200 pt-4">
                <h3 className="text-[14px] font-bold">Customer links</h3>
                <p className="text-xs text-neutral-500">
                    Optional buttons on the customer’s pickup page. Leave blank
                    to hide. Facebook and Website are also shown on Customer QR.
                </p>
            </div>
            <div className="grid gap-3 md:grid-cols-3">
                {LINKS.map(([key, label, placeholder]) => (
                    <label
                        key={key}
                        className="flex min-w-0 flex-col gap-1.5 text-xs font-semibold"
                    >
                        {label}
                        <input
                            type="url"
                            inputMode="url"
                            value={form[key]}
                            maxLength={500}
                            placeholder={placeholder}
                            onChange={(event) => set(key, event.target.value)}
                            className={controlClass}
                        />
                    </label>
                ))}
            </div>
            {error && (
                <p role="alert" className="text-xs text-red-700">
                    {error}
                </p>
            )}
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={busy}
                    className={`${primaryActionClass} gap-2`}
                >
                    {busy ? <Spinner /> : <Save className="size-4" />}
                    Save settings
                </Button>
            </div>
        </form>
    );
}
