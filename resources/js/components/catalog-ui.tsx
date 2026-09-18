import { Head, Link } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';
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
import { workspace } from '@/routes';
import { index as categoriesIndex } from '@/routes/categories';
import { index as modifiersIndex } from '@/routes/modifier-groups';
import { index as productsIndex } from '@/routes/products';

export const controlClass =
    'h-11 w-full rounded-md border border-neutral-300 bg-white px-3 text-base text-neutral-950 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none';
export const panelClass =
    'rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm';
export const actionClass =
    'min-h-11 rounded-xl border-neutral-200 bg-white text-neutral-950 hover:bg-neutral-100 hover:text-neutral-950';
export const primaryActionClass =
    'min-h-11 rounded-xl bg-neutral-950 text-white hover:bg-neutral-800';
export const money = (value: string) =>
    `₱${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;

export function CatalogPage({
    tab,
    children,
    action,
}: {
    tab: 'Products' | 'Categories' | 'Modifiers';
    children: ReactNode;
    action: ReactNode;
}) {
    return (
        <>
            <Head title={`${tab} · Product management`} />
            <div className="mx-auto flex max-w-6xl flex-col gap-6">
                <Link
                    href={workspace()}
                    className="w-fit py-2 text-sm font-semibold underline underline-offset-4"
                >
                    Back to workspace
                </Link>
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <div className="space-y-2">
                        <p className="text-xs font-bold tracking-[0.18em] text-[#8c671e] uppercase">
                            Business Operations
                        </p>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Product management
                        </h1>
                        <p className="text-sm text-neutral-600">
                            One catalog for every branch. Fine-tune prices where
                            needed.
                        </p>
                    </div>
                    {action}
                </div>
                <nav
                    aria-label="Product management"
                    className="flex gap-2 border-b border-neutral-200 pb-3"
                >
                    {[
                        { label: 'Products', href: productsIndex() },
                        { label: 'Categories', href: categoriesIndex() },
                        { label: 'Modifiers', href: modifiersIndex() },
                    ].map(({ label, href }) => (
                        <Link
                            key={label}
                            href={href}
                            aria-current={label === tab ? 'page' : undefined}
                            className={`min-h-11 rounded-xl px-4 py-3 text-sm font-semibold ${label === tab ? 'bg-neutral-950 text-white' : 'bg-white text-neutral-600 hover:bg-neutral-100'}`}
                        >
                            {label}
                        </Link>
                    ))}
                </nav>
                {children}
            </div>
        </>
    );
}

export function CatalogDialog({
    open,
    onClose,
    title,
    description,
    children,
}: {
    open: boolean;
    onClose: () => void;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) onClose();
            }}
        >
            <DialogContent className="max-h-[90svh] overflow-y-auto bg-white text-neutral-950 sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription className="text-neutral-600">
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
            <Label htmlFor={id}>{label}</Label>
            {children}
            {error && (
                <p
                    id={`${id}-error`}
                    role="alert"
                    className="text-sm text-red-700"
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
        <label className="flex min-h-11 cursor-pointer items-center gap-3 text-sm font-medium">
            <input
                type="checkbox"
                checked={value}
                onChange={(event) => onChange(event.target.checked)}
                className="size-5 accent-neutral-950"
            />
            {label}
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
            className="min-h-11 rounded-xl bg-neutral-950 text-white hover:bg-neutral-800"
        >
            {processing ? 'Saving…' : label}
        </Button>
    );
}

export function Status({ active }: { active: boolean }) {
    return (
        <span
            className={`rounded-full px-2.5 py-1 text-xs font-semibold ${active ? 'bg-emerald-50 text-emerald-800' : 'bg-neutral-100 text-neutral-600'}`}
        >
            {active ? 'Active' : 'Inactive'}
        </span>
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
                className="rounded-lg bg-red-50 p-3 text-sm text-red-700"
            >
                {Object.entries(errors).map(([key, message]) => (
                    <p key={key}>{message}</p>
                ))}
            </div>
        )
    );
}
