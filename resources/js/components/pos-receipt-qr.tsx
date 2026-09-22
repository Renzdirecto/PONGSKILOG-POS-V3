import { useEffect, useRef, useState } from 'react';
import { ArrowLeft, Clock3, LoaderCircle } from 'lucide-react';
import { store } from '@/actions/App/Http/Controllers/ReceiptShareController';
import { qrError, qrRequest } from '@/lib/qr-http';
import type { PaidReceipt } from '@/types/pos';

type ReceiptShare = { url: string; expires_at: string; qr_image: string };

export function PosReceiptQr({
    receipt,
    onBack,
}: {
    receipt: PaidReceipt;
    onBack: () => void;
}) {
    const [share, setShare] = useState<ReceiptShare | null>(null);
    const [error, setError] = useState('');
    const [attempt, setAttempt] = useState(0);
    const [expired, setExpired] = useState(false);
    const request = useRef<Promise<ReceiptShare> | null>(null);
    useEffect(() => {
        let current = true;
        request.current ??= qrRequest<ReceiptShare>(store(receipt.id));
        void request.current
            .then((result) => {
                if (current) setShare(result);
            })
            .catch((reason) => {
                if (current) {
                    const failure = qrError(reason);
                    setExpired(failure.status === 410);
                    setError(
                        failure.status === 410
                            ? 'Digital receipt has expired'
                            : 'Unable to load the receipt QR. Please try again.',
                    );
                }
            });
        return () => {
            current = false;
        };
    }, [receipt.id, attempt]);
    useEffect(() => {
        if (!share) return;
        const timer = window.setTimeout(
            () => setExpired(true),
            Math.max(0, new Date(share.expires_at).getTime() - Date.now()),
        );
        return () => window.clearTimeout(timer);
    }, [share]);
    return (
        <>
            <header className="flex shrink-0 items-center gap-3 border-b border-neutral-200 px-4 py-3">
                <button
                    type="button"
                    onClick={onBack}
                    className="flex h-10 items-center gap-2 rounded-lg px-2 text-xs font-semibold"
                >
                    <ArrowLeft size={16} />
                    Back
                </button>
                <h2 className="text-sm font-bold">Digital receipt</h2>
            </header>
            <div className="flex min-h-0 flex-1 flex-col items-center gap-4 overflow-y-auto px-5 py-6 text-center">
                <h3 className="text-base font-bold">
                    Scan to view your digital receipt
                </h3>
                <p className="font-bold">Order #{receipt.order_number}</p>
                <p className="text-xs text-neutral-500">
                    REF: {receipt.reference_number}
                </p>
                {expired ? (
                    <p role="alert">Digital receipt has expired</p>
                ) : error ? (
                    <>
                        <p role="alert" className="text-sm text-red-700">
                            {error}
                        </p>
                        <button
                            type="button"
                            className="rounded-xl border px-5 py-3 font-semibold"
                            onClick={() => {
                                request.current = null;
                                setError('');
                                setAttempt((value) => value + 1);
                            }}
                        >
                            Retry
                        </button>
                    </>
                ) : share ? (
                    <>
                        <img
                            src={share.qr_image}
                            alt={`Digital receipt QR for Order ${receipt.order_number}`}
                            className="w-full max-w-80 rounded-xl border border-neutral-200 bg-white p-3"
                        />
                        <div className="flex max-w-96 items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-3 text-left text-xs leading-5 text-amber-800">
                            <Clock3 className="mt-0.5 size-4 shrink-0" />
                            <span>
                                Available for 24 hours after payment. Expires{' '}
                                {new Date(share.expires_at).toLocaleString(
                                    'en-PH',
                                    { timeZone: 'Asia/Manila' },
                                )}{' '}
                                (Manila time).
                            </span>
                        </div>
                    </>
                ) : (
                    <p
                        role="status"
                        className="flex items-center gap-2 py-12 text-sm"
                    >
                        <LoaderCircle className="size-5 animate-spin" />
                        Loading receipt QR…
                    </p>
                )}
            </div>
        </>
    );
}
