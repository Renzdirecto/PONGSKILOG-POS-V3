import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';
import { DigitalReceiptCard } from '@/components/digital-receipt-card';
import { qrPrimary } from '@/components/customer-qr-product';
import { qrError, qrRequest } from '@/lib/qr-http';
import { receiptPng } from '@/lib/receipt-png';
import type { PublicReceipt } from '@/types/qr';

export default function PublicReceiptPage({
    receipt: initial,
}: {
    receipt: PublicReceipt;
}) {
    const [receipt, setReceipt] = useState(initial);
    const [expired, setExpired] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState('');
    const card = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const timer = window.setTimeout(
            () => setExpired(true),
            Math.max(
                0,
                new Date(receipt.receipt_expires_at!).getTime() - Date.now(),
            ),
        );
        return () => window.clearTimeout(timer);
    }, [receipt.receipt_expires_at]);
    async function save() {
        if (saving || expired) return;
        setSaving(true);
        try {
            const fresh = await qrRequest<{ receipt: PublicReceipt }>({
                url: window.location.pathname + window.location.search,
                method: 'get',
            });
            flushSync(() => setReceipt(fresh.receipt));
            if (!card.current) throw new Error('Receipt unavailable');
            const url = URL.createObjectURL(await receiptPng(card.current));
            const link = document.createElement('a');
            link.href = url;
            link.download = `Pongskilog-${fresh.receipt.reference_number}.png`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.setTimeout(() => URL.revokeObjectURL(url), 60_000);
            setError('');
        } catch (reason) {
            if (qrError(reason).status === 410) setExpired(true);
            setError('Unable to save the PNG. Please try again.');
        } finally {
            setSaving(false);
        }
    }
    return (
        <div className="min-h-dvh bg-neutral-50 text-neutral-950">
            <Head title="Digital receipt" />
            <main className="mx-auto flex max-w-[560px] flex-col gap-4 px-4 py-6 pb-12">
                <h1 className="text-center text-sm font-bold">
                    Digital receipt
                </h1>
                {expired ? (
                    <p
                        role="alert"
                        className="rounded-2xl border bg-white p-6 text-center"
                    >
                        Digital receipt has expired
                    </p>
                ) : (
                    <>
                        <DigitalReceiptCard receipt={receipt} ref={card} />
                        <p className="text-center text-[11px] text-neutral-500">
                            Available for 24 hours after payment. Save a copy
                            before it expires.
                        </p>
                        {error && (
                            <p role="alert" className="text-sm text-red-700">
                                {error}
                            </p>
                        )}
                        <button
                            type="button"
                            className={qrPrimary}
                            disabled={saving}
                            onClick={save}
                        >
                            <Download size={16} />
                            {saving ? 'Saving…' : 'Save receipt as PNG'}
                        </button>
                    </>
                )}
            </main>
        </div>
    );
}
