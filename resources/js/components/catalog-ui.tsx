import { Head, Link } from '@inertiajs/react';
import { FolderPlus, Layers3, PackagePlus } from 'lucide-react';
import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
import {
    OwnerPage,
    OwnerStatusBadge,
    ownerControlClass,
    ownerPanelClass,
    ownerPrimaryActionClass,
    ownerSecondaryActionClass,
} from '@/components/owner-ui';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as categoriesIndex } from '@/routes/categories';
import { index as modifiersIndex } from '@/routes/modifier-groups';
import { index as productsIndex } from '@/routes/products';

export const controlClass = `${ownerControlClass} w-full`;
export const panelClass = `${ownerPanelClass} p-4`;
export const actionClass = ownerSecondaryActionClass;
export const primaryActionClass = ownerPrimaryActionClass;
export const money = (value: string) =>
    `₱${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

type CatalogTab = 'Products' | 'Categories' | 'Groups';

export function CatalogPage({
    tab,
    children,
    action,
    counts,
}: {
    tab: CatalogTab;
    children: ReactNode;
    action?: ReactNode;
    counts?: Partial<Record<CatalogTab, number>>;
}) {
    const tabs: {
        label: CatalogTab;
        href: ReturnType<typeof productsIndex>;
    }[] = [
        { label: 'Products', href: productsIndex() },
        { label: 'Categories', href: categoriesIndex() },
        { label: 'Groups', href: modifiersIndex() },
    ];

    return (
        <>
            <Head title={`${tab} · Product management`} />
            <OwnerPage
                title="Products"
                description="Products, categories and the options offered in the POS and customer QR menu."
                action={<CatalogQuickActions />}
            >
                <nav
                    aria-label="Product management"
                    className="owner-hide-scrollbar flex w-fit max-w-full gap-0.5 overflow-x-auto rounded-[11px] bg-[#f2f2f2] p-[3px]"
                >
                    {tabs.map(({ label, href }) => (
                        <Link
                            key={label}
                            href={href}
                            aria-current={label === tab ? 'page' : undefined}
                            className={`inline-flex min-h-10 shrink-0 items-center gap-2 rounded-[9px] px-[15px] text-[13.5px] font-semibold transition focus-visible:ring-2 focus-visible:ring-[#111111] focus-visible:outline-none ${label === tab ? 'bg-[#111111] text-white' : 'text-[#666] hover:bg-white/70'}`}
                        >
                            <span>{label}</span>
                            {counts?.[label] !== undefined && (
                                <span
                                    className={`inline-flex h-5 min-w-[22px] items-center justify-center rounded-full px-1.5 text-[11px] font-bold tabular-nums ${label === tab ? 'bg-white/18 text-white' : 'bg-white text-[#666]'}`}
                                >
                                    {counts[label]}
                                </span>
                            )}
                        </Link>
                    ))}
                </nav>
                {action}
                {children}
            </OwnerPage>
        </>
    );
}

function CatalogQuickActions() {
    const actions = [
        {
            label: 'Add product',
            icon: PackagePlus,
            href: productsIndex({ query: { create: 'product' } }),
        },
        {
            label: 'Add category',
            icon: FolderPlus,
            href: categoriesIndex({ query: { create: 'category' } }),
        },
        {
            label: 'Add group',
            icon: Layers3,
            href: modifiersIndex({ query: { create: 'group' } }),
        },
    ];

    return (
        <div className="grid w-full grid-cols-3 gap-1.5 md:flex md:w-auto">
            {actions.map(({ label, icon: Icon, href }, index) => (
                <Link
                    key={label}
                    href={href}
                    className={`${index === 0 ? ownerPrimaryActionClass : ownerSecondaryActionClass} inline-flex min-w-0 items-center justify-center gap-1.5 px-2 md:px-3`}
                >
                    <Icon className="size-4 shrink-0" />
                    <span className="truncate">{label}</span>
                </Link>
            ))}
        </div>
    );
}

export function CatalogDialog({
    open,
    onClose,
    title,
    description,
    children,
    wide = false,
    standalone = false,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description: string;
    children: ReactNode;
    wide?: boolean;
    standalone?: boolean;
}) {
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) onClose();
            }}
        >
            <DialogContent
                className={`owner-surface top-auto bottom-0 max-h-[96dvh] w-full max-w-none translate-y-0 rounded-t-[20px] rounded-b-none border-[#e5e5e5] bg-white text-[#111111] sm:top-1/2 sm:bottom-auto sm:max-h-[92dvh] sm:-translate-y-1/2 sm:rounded-[20px] ${standalone ? 'flex flex-col gap-0 overflow-hidden p-0' : 'gap-4 overflow-y-auto p-4 sm:p-6'} ${wide ? 'sm:max-w-3xl' : 'sm:max-w-xl'} [&>button]:top-2 [&>button]:right-2 [&>button]:flex [&>button]:min-h-11 [&>button]:min-w-11 [&>button]:items-center [&>button]:justify-center`}
            >
                <DialogHeader
                    className={`pr-12 text-left ${standalone ? 'shrink-0 gap-0.5 border-b border-[#e5e5e5] px-4 py-3' : 'pr-7'}`}
                >
                    <DialogTitle className="text-[17px] font-bold">
                        {title}
                    </DialogTitle>
                    <DialogDescription
                        className={`${standalone ? 'order-first text-[10px] font-semibold tracking-[0.08em] uppercase' : 'text-[12.5px] leading-5'} text-[#666]`}
                    >
                        {description}
                    </DialogDescription>
                </DialogHeader>
                {children}
            </DialogContent>
        </Dialog>
    );
}

export function Field({
    id,
    label,
    error,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="space-y-2">
            <Label
                htmlFor={id}
                className="text-[11px] font-semibold tracking-[0.06em] text-[#777] uppercase"
            >
                {label}
            </Label>
            {children}
            {error && (
                <p
                    id={`${id}-error`}
                    role="alert"
                    className="text-xs text-red-700"
                >
                    {error}
                </p>
            )}
        </div>
    );
}

export function TextField({
    id,
    label,
    value,
    onChange,
    error,
    type = 'text',
    required = true,
    maxLength = 255,
}: {
    id: string;
    label: string;
    value: string | number;
    onChange: (value: string) => void;
    error?: string;
    type?: string;
    required?: boolean;
    maxLength?: number;
}) {
    return (
        <Field id={id} label={label} error={error}>
            <Input
                id={id}
                name={id}
                type={type}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                required={required}
                maxLength={maxLength}
                min={type === 'number' ? 0 : undefined}
                aria-invalid={!!error}
                aria-describedby={error ? `${id}-error` : undefined}
                className={controlClass}
            />
        </Field>
    );
}

export function ActiveField({
    value,
    onChange,
    label = 'Active',
}: {
    value: boolean;
    onChange: (value: boolean) => void;
    label?: string;
}) {
    return (
        <label className="flex min-h-11 cursor-pointer items-center gap-3 rounded-[11px] border border-[#e5e5e5] px-3 text-[12.5px] font-medium">
            <input
                type="checkbox"
                checked={value}
                onChange={(event) => onChange(event.target.checked)}
                className="size-5 accent-[#111111]"
            />
            <span className="min-w-0 flex-1 break-words">{label}</span>
        </label>
    );
}

export function SaveButton({
    processing,
    label = 'Save changes',
}: {
    processing: boolean;
    label?: string;
}) {
    return (
        <Button
            type="submit"
            disabled={processing}
            className={primaryActionClass}
        >
            {processing ? 'Saving…' : label}
        </Button>
    );
}

export function Status({ active }: { active: boolean }) {
    return (
        <OwnerStatusBadge tone={active ? 'green' : 'outline'}>
            {active ? 'Active' : 'Disabled'}
        </OwnerStatusBadge>
    );
}

export function FormErrors({ errors }: { errors: Record<string, string> }) {
    const summary = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (Object.keys(errors).length > 0) summary.current?.focus();
    }, [errors]);
    return (
        Object.keys(errors).length > 0 && (
            <div
                ref={summary}
                tabIndex={-1}
                role="alert"
                className="rounded-[11px] border border-red-200 bg-red-50 p-3 text-xs text-red-700"
            >
                {Object.entries(errors).map(([key, message]) => (
                    <p key={key}>{message}</p>
                ))}
            </div>
        )
    );
}
