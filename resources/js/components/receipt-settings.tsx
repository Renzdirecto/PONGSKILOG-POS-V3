import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Check, ImagePlus, Plus, X } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    controlClass,
    primaryActionClass,
} from '@/components/catalog-ui';
import { ownerPanelClass } from '@/components/owner-ui';
import { ReceiptDocument } from '@/components/receipt-document';
import { qrError, qrRequest } from '@/lib/qr-http';
import {
    DEFAULT_RECEIPT_FOOTER,
    RECEIPT_BLOCK_LABELS,
    RECEIPT_MAX_CUSTOM_ROWS,
    RECEIPT_MAX_TEXT,
    moveReceiptBlock,
    receiptLayoutPayload,
    receiptPreview,
} from '@/lib/receipt';
import type {
    ReceiptSettingsDraft,
    ReceiptPreviewBranch,
} from '@/lib/receipt';
import { update as saveQrSettings } from '@/routes/branches/qr-settings';
import type {
    ReceiptBlock,
    ReceiptLayoutSettings,
    ReceiptSeparator,
} from '@/types/receipt';

export type ReceiptSettingsBranch = ReceiptPreviewBranch & {
    id: string;
    receipt_name: string | null;
    receipt_address: string | null;
    receipt_contact: string | null;
    receipt_footer: string | null;
    receipt_show_logo: boolean;
    receipt_logo_url: string;
    receipt_layout: ReceiptLayoutSettings;
    facebook_url: string | null;
    website_url: string | null;
};

/** Optional blocks a Branch may turn off, in print order (the logo has its own switch). */
const OPTIONAL_BLOCKS: ReceiptBlock[] = [
    'address',
    'contact',
    'header_text',
    'date',
    'cashier',
    'customer',
    'payments',
    'custom_rows',
    'footer',
    'order_qr',
];
const SEPARATORS: [ReceiptSeparator, string][] = [
    ['dashed', 'Dashed'],
    ['solid', 'Solid'],
    ['none', 'None'],
];
const DEFAULT_LOGO = '/images/branding/logo.png';

/**
 * Receipt Settings: the one configuration authority of every receipt (POS print, reprint, receipt link, Customer QR,
 * Pickup). Only controlled options — no HTML: store texts, the logo, which optional blocks print, the order inside the
 * Details and Footer zones, a header line, up to five custom rows and the separator. Blank store texts follow the
 * Branch details instead of freezing a copy of them. The preview renders the real receipt component.
 */
export function ReceiptSettings({ branch }: { branch: ReceiptSettingsBranch }) {
    const [data, setData] = useState({
        receipt_name: branch.receipt_name ?? '',
        receipt_address: branch.receipt_address ?? '',
        receipt_contact: branch.receipt_contact ?? '',
        receipt_footer: branch.receipt_footer ?? '',
        receipt_show_logo: branch.receipt_show_logo,
        facebook_url: branch.facebook_url ?? '',
        website_url: branch.website_url ?? '',
    });
    const [layout, setLayout] = useState<ReceiptLayoutSettings>(
        branch.receipt_layout,
    );
    const [saved, setSaved] = useState(() =>
        JSON.stringify([data, receiptLayoutPayload(layout)]),
    );
    const [logo, setLogo] = useState<File | null>(null);
    const [preview, setPreview] = useState<string | null>(null);
    const [removeLogo, setRemoveLogo] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const fileInput = useRef<HTMLInputElement>(null);
    useEffect(() => {
        if (!logo) {
            setPreview(null);
            return;
        }
        const url = URL.createObjectURL(logo);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [logo]);
    const logoUrl =
        preview ?? (removeLogo ? DEFAULT_LOGO : branch.receipt_logo_url);
    const draft: ReceiptSettingsDraft = data;
    const sample = useMemo(
        () => receiptPreview(branch, draft, layout, logoUrl),
        [branch, draft, layout, logoUrl],
    );
    const dirty =
        JSON.stringify([data, receiptLayoutPayload(layout)]) !== saved ||
        !!logo ||
        removeLogo;
    const hidden = (block: ReceiptBlock) => layout.hidden.includes(block);
    const toggle = (block: ReceiptBlock, visible: boolean) =>
        setLayout({
            ...layout,
            hidden: visible
                ? layout.hidden.filter((item) => item !== block)
                : [...layout.hidden, block],
        });

    const submit = async () => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const payload = new FormData();
            payload.set('_method', 'PUT');
            Object.entries(data).forEach(([key, value]) =>
                payload.set(
                    key,
                    typeof value === 'boolean' ? (value ? '1' : '0') : value,
                ),
            );
            payload.set(
                'receipt_layout',
                JSON.stringify(receiptLayoutPayload(layout)),
            );
            if (logo) payload.set('receipt_logo', logo);
            payload.set('remove_receipt_logo', removeLogo ? '1' : '0');
            await qrRequest(
                { ...saveQrSettings(branch.id), method: 'post' },
                payload,
            );
            setSaved(JSON.stringify([data, receiptLayoutPayload(layout)]));
            toast.success('Receipt settings saved');
            router.reload({
                only: ['branches'],
                onSuccess: () => {
                    setLogo(null);
                    setRemoveLogo(false);
                },
                onFinish: () => setBusy(false),
            });
        } catch (reason) {
            setError(qrError(reason).message);
            setBusy(false);
        }
    };

    return (
        <div className="grid items-start gap-4 min-[1000px]:grid-cols-[1.15fr_1fr]">
            <form
                className={`${ownerPanelClass} flex flex-col gap-5 p-5`}
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit();
                }}
            >
                <div>
                    <h2 className="text-sm font-bold">Receipt</h2>
                    <p className="mt-1 text-xs text-neutral-600">
                        Every receipt — printed, reprinted, shared by link,
                        Customer QR and Pickup — follows these settings.
                    </p>
                </div>
                <fieldset
                    disabled={busy}
                    className="flex flex-col gap-5 disabled:opacity-60"
                >
                    <SettingsGroup title="Logo">
                        <div className="flex flex-wrap items-center gap-3 rounded-xl border border-neutral-200 bg-neutral-50 p-3">
                            <div className="flex h-14 w-20 items-center justify-center rounded-lg bg-white p-2 ring-1 ring-neutral-200">
                                <img
                                    src={logoUrl}
                                    alt="Receipt logo"
                                    className="max-h-full max-w-full object-contain"
                                />
                            </div>
                            <p className="min-w-0 flex-1 basis-40 text-[11px] leading-5 text-neutral-600">
                                Printed above the store name. Defaults to the
                                PONGSKILOG logo. PNG, JPG or WebP up to 2 MB.
                            </p>
                            <input
                                ref={fileInput}
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                className="hidden"
                                aria-label="Replace receipt logo"
                                onChange={(event) => {
                                    const file = event.target.files?.[0];
                                    if (file) {
                                        setLogo(file);
                                        setRemoveLogo(false);
                                        setData({
                                            ...data,
                                            receipt_show_logo: true,
                                        });
                                    }
                                    event.target.value = '';
                                }}
                            />
                            <button
                                type="button"
                                className={`${actionClass} inline-flex min-h-11 items-center gap-2`}
                                onClick={() => fileInput.current?.click()}
                            >
                                <ImagePlus size={14} /> Replace
                            </button>
                            {(logo !== null ||
                                (!removeLogo &&
                                    branch.receipt_logo_url !==
                                        DEFAULT_LOGO)) && (
                                <button
                                    type="button"
                                    className={`${actionClass} min-h-11`}
                                    onClick={() => {
                                        setLogo(null);
                                        setRemoveLogo(
                                            branch.receipt_logo_url !==
                                                DEFAULT_LOGO,
                                        );
                                    }}
                                >
                                    Use default
                                </button>
                            )}
                        </div>
                        <SettingSwitch
                            checked={data.receipt_show_logo}
                            label="Print the logo"
                            onChange={(enabled) =>
                                setData({ ...data, receipt_show_logo: enabled })
                            }
                        />
                    </SettingsGroup>

                    <SettingsGroup
                        title="Store details"
                        description="Leave a field blank to use the Branch details, so later Branch changes show on receipts."
                    >
                        {(
                            [
                                ['receipt_name', 'Store name', 150, branch.name],
                                [
                                    'receipt_address',
                                    'Address',
                                    500,
                                    branch.address ?? 'No Branch address',
                                ],
                                [
                                    'receipt_contact',
                                    'Contact',
                                    100,
                                    branch.contact ?? 'No Branch contact',
                                ],
                                [
                                    'receipt_footer',
                                    'Thank-you message',
                                    250,
                                    DEFAULT_RECEIPT_FOOTER,
                                ],
                            ] as const
                        ).map(([key, label, maxLength, placeholder]) => (
                            <label
                                key={key}
                                className="flex flex-col gap-1.5 text-xs font-semibold"
                            >
                                {label}
                                <input
                                    className={controlClass}
                                    maxLength={maxLength}
                                    placeholder={placeholder}
                                    value={data[key]}
                                    onChange={(event) =>
                                        setData({
                                            ...data,
                                            [key]: event.target.value,
                                        })
                                    }
                                />
                            </label>
                        ))}
                        <label className="flex flex-col gap-1.5 text-xs font-semibold">
                            Header text
                            <input
                                className={controlClass}
                                maxLength={RECEIPT_MAX_TEXT}
                                placeholder="e.g. Open daily 7 AM – 10 PM"
                                value={layout.header_text ?? ''}
                                onChange={(event) =>
                                    setLayout({
                                        ...layout,
                                        header_text: event.target.value,
                                    })
                                }
                            />
                        </label>
                    </SettingsGroup>

                    <SettingsGroup
                        title="Show on the receipt"
                        description="Store name, order number, items and totals always print."
                    >
                        <div className="grid gap-2 sm:grid-cols-2">
                            {OPTIONAL_BLOCKS.map((block) => (
                                <label
                                    key={block}
                                    className="flex min-h-11 cursor-pointer items-center gap-2.5 rounded-[11px] border border-neutral-200 bg-white px-3 py-2 text-xs font-semibold"
                                >
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-neutral-900"
                                        checked={!hidden(block)}
                                        onChange={(event) =>
                                            toggle(block, event.target.checked)
                                        }
                                    />
                                    {RECEIPT_BLOCK_LABELS[block]}
                                </label>
                            ))}
                        </div>
                        {!hidden('order_qr') && !branch.qr_ordering_enabled && (
                            <p className="text-[11px] text-amber-800">
                                The order-again QR prints only while Customer QR
                                ordering is on for this Branch.
                            </p>
                        )}
                    </SettingsGroup>

                    <SettingsGroup
                        title="Order"
                        description="The header, items, totals and payments keep their places."
                    >
                        <div className="grid gap-3 sm:grid-cols-2">
                            <BlockOrder
                                label="Details"
                                order={layout.details}
                                hidden={layout.hidden}
                                onChange={(details) =>
                                    setLayout({ ...layout, details })
                                }
                            />
                            <BlockOrder
                                label="Footer"
                                order={layout.footer}
                                hidden={layout.hidden}
                                onChange={(footer) =>
                                    setLayout({ ...layout, footer })
                                }
                            />
                        </div>
                    </SettingsGroup>

                    <SettingsGroup
                        title="Custom text rows"
                        description={`Up to ${RECEIPT_MAX_CUSTOM_ROWS} short plain-text lines printed in the footer (e.g. Wi-Fi password, promo).`}
                    >
                        {layout.custom_rows.map((row, index) => (
                            <div key={index} className="flex gap-2">
                                <input
                                    aria-label={`Custom text row ${index + 1}`}
                                    className={controlClass}
                                    maxLength={RECEIPT_MAX_TEXT}
                                    value={row}
                                    onChange={(event) =>
                                        setLayout({
                                            ...layout,
                                            custom_rows: layout.custom_rows.map(
                                                (value, position) =>
                                                    position === index
                                                        ? event.target.value
                                                        : value,
                                            ),
                                        })
                                    }
                                />
                                <button
                                    type="button"
                                    aria-label={`Remove custom text row ${index + 1}`}
                                    className={`${actionClass} min-h-11 shrink-0 px-3`}
                                    onClick={() =>
                                        setLayout({
                                            ...layout,
                                            custom_rows:
                                                layout.custom_rows.filter(
                                                    (_, position) =>
                                                        position !== index,
                                                ),
                                        })
                                    }
                                >
                                    <X size={14} />
                                </button>
                            </div>
                        ))}
                        {layout.custom_rows.length <
                            RECEIPT_MAX_CUSTOM_ROWS && (
                            <button
                                type="button"
                                className={`${actionClass} inline-flex min-h-11 items-center gap-2 self-start`}
                                onClick={() =>
                                    setLayout({
                                        ...layout,
                                        custom_rows: [...layout.custom_rows, ''],
                                    })
                                }
                            >
                                <Plus size={14} /> Add row
                            </button>
                        )}
                    </SettingsGroup>

                    <SettingsGroup title="Separators">
                        <div
                            role="radiogroup"
                            aria-label="Separator style"
                            className="inline-flex gap-1 self-start rounded-xl bg-neutral-100 p-1"
                        >
                            {SEPARATORS.map(([value, label]) => (
                                <button
                                    key={value}
                                    type="button"
                                    role="radio"
                                    aria-checked={layout.separator === value}
                                    className={`${layout.separator === value ? primaryActionClass : actionClass} min-h-10 px-4`}
                                    onClick={() =>
                                        setLayout({
                                            ...layout,
                                            separator: value,
                                        })
                                    }
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    </SettingsGroup>
                </fieldset>
                {error && (
                    <p role="alert" className="text-sm text-red-700">
                        {error}
                    </p>
                )}
                <button
                    disabled={busy || !dirty}
                    className={`${primaryActionClass} inline-flex min-h-11 items-center gap-2 self-start disabled:cursor-not-allowed disabled:opacity-40`}
                >
                    <Check size={15} /> {busy ? 'Saving…' : 'Save changes'}
                </button>
                <p className="text-[11px] text-neutral-600">
                    Printer selection stays with the terminal, not the business
                    record. Receipts always show the order's saved items and
                    prices, never today's menu.
                </p>
                <details className="border-t border-neutral-100 pt-3">
                    <summary className="cursor-pointer text-xs font-semibold">
                        Customer links
                    </summary>
                    <div className="mt-3 space-y-3">
                        {(['facebook_url', 'website_url'] as const).map(
                            (key) => (
                                <label
                                    key={key}
                                    className="flex flex-col gap-2 text-xs"
                                >
                                    {key === 'facebook_url'
                                        ? 'Facebook URL'
                                        : 'Website URL'}
                                    <input
                                        type="url"
                                        disabled={busy}
                                        className={controlClass}
                                        value={data[key]}
                                        onChange={(event) =>
                                            setData({
                                                ...data,
                                                [key]: event.target.value,
                                            })
                                        }
                                    />
                                </label>
                            ),
                        )}
                    </div>
                </details>
            </form>
            <section
                className={`${ownerPanelClass} p-5 min-[1000px]:sticky min-[1000px]:top-4`}
                aria-label="Receipt preview"
            >
                <h3 className="mb-4 text-[11px] font-bold tracking-wider text-neutral-600 uppercase">
                    Preview · Sample order
                </h3>
                <div className="rounded-xl bg-neutral-100 p-3">
                    <ReceiptDocument receipt={sample} variant="paper" />
                </div>
            </section>
        </div>
    );
}

function SettingsGroup({
    title,
    description,
    children,
}: {
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-2.5 border-t border-neutral-100 pt-4 first:border-0 first:pt-0">
            <div>
                <h3 className="text-[13px] font-bold">{title}</h3>
                {description && (
                    <p className="mt-0.5 text-[11.5px] leading-5 text-neutral-600">
                        {description}
                    </p>
                )}
            </div>
            {children}
        </section>
    );
}

function BlockOrder<T extends ReceiptBlock>({
    label,
    order,
    hidden,
    onChange,
}: {
    label: string;
    order: T[];
    hidden: ReceiptBlock[];
    onChange: (order: T[]) => void;
}) {
    return (
        <div className="flex flex-col gap-1.5">
            <p className="text-[11px] font-bold tracking-wider text-neutral-600 uppercase">
                {label}
            </p>
            <ol className="flex flex-col gap-1.5">
                {order.map((block, index) => (
                    <li
                        key={block}
                        className={`flex min-h-11 items-center gap-2 rounded-[11px] border border-neutral-200 bg-white py-1 pr-1 pl-3 text-xs font-semibold ${hidden.includes(block) ? 'text-neutral-400' : ''}`}
                    >
                        <span className="min-w-0 flex-1 wrap-anywhere">
                            {RECEIPT_BLOCK_LABELS[block]}
                            {hidden.includes(block) && ' (hidden)'}
                        </span>
                        <button
                            type="button"
                            aria-label={`Move ${RECEIPT_BLOCK_LABELS[block]} up`}
                            disabled={index === 0}
                            className="flex size-9 items-center justify-center rounded-lg hover:bg-neutral-100 disabled:opacity-30"
                            onClick={() =>
                                onChange(moveReceiptBlock(order, block, -1))
                            }
                        >
                            <ArrowUp size={14} />
                        </button>
                        <button
                            type="button"
                            aria-label={`Move ${RECEIPT_BLOCK_LABELS[block]} down`}
                            disabled={index === order.length - 1}
                            className="flex size-9 items-center justify-center rounded-lg hover:bg-neutral-100 disabled:opacity-30"
                            onClick={() =>
                                onChange(moveReceiptBlock(order, block, 1))
                            }
                        >
                            <ArrowDown size={14} />
                        </button>
                    </li>
                ))}
            </ol>
        </div>
    );
}

export function SettingSwitch({
    checked,
    disabled,
    onChange,
    label,
    description,
}: {
    checked: boolean;
    disabled?: boolean;
    onChange: (checked: boolean) => void;
    label: string;
    description?: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={`flex w-full items-center gap-3 rounded-[11px] border bg-white p-3 text-left disabled:opacity-50 ${checked ? 'border-[#111]' : 'border-neutral-200'}`}
        >
            <span
                className={`flex h-6 w-11 shrink-0 items-center rounded-full p-0.5 ${checked ? 'justify-end bg-[#111]' : 'justify-start bg-neutral-300'}`}
            >
                <span className="size-5 rounded-full bg-white" />
            </span>
            <span>
                <span className="block text-xs font-semibold">{label}</span>
                {description && (
                    <span className="mt-1 block text-[11px] text-neutral-600">
                        {description}
                    </span>
                )}
            </span>
        </button>
    );
}
