import { http } from '@inertiajs/react';
import {
    ArrowLeft,
    Camera,
    Check,
    Eye,
    FileImage,
    RefreshCw,
    Trash2,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    destroy,
    store,
} from '@/actions/App/Http/Controllers/PaymentInvoiceProofController';

type Invoice = { name: string; url: string };
type Preview = { file: File; url: string };

export function TransactionInvoiceDialog({
    paymentId,
    invoice,
    open,
    mutable,
    onClose,
    onChanged,
}: {
    paymentId: string;
    invoice: Invoice | null;
    open: boolean;
    mutable: boolean;
    onClose: () => void;
    onChanged: (invoice: Invoice | null) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const video = useRef<HTMLVideoElement>(null);
    const stream = useRef<MediaStream | null>(null);
    const [camera, setCamera] = useState(false);
    const [preview, setPreview] = useState<Preview | null>(null);
    const [processing, setProcessing] = useState(false);

    function stopCamera() {
        stream.current?.getTracks().forEach((track) => track.stop());
        stream.current = null;
        setCamera(false);
    }

    useEffect(
        () => () => {
            stream.current?.getTracks().forEach((track) => track.stop());
        },
        [],
    );

    useEffect(
        () => () => {
            if (preview) URL.revokeObjectURL(preview.url);
        },
        [preview],
    );

    function choosePreview(file: File) {
        stopCamera();
        setPreview({ file, url: URL.createObjectURL(file) });
    }

    function retry() {
        setPreview(null);
        if (input.current) input.current.value = '';
    }

    function closeDialog() {
        stopCamera();
        retry();
        onClose();
    }

    async function confirm() {
        if (!preview) return;
        setProcessing(true);
        const data = new FormData();
        data.append('invoice', preview.file);
        try {
            const response = await http.getClient().request({
                ...store(paymentId),
                data,
                headers: { Accept: 'application/json' },
            });
            toast.success('Invoice proof saved.');
            onChanged((JSON.parse(response.data) as { invoice: Invoice }).invoice);
            closeDialog();
        } catch {
            toast.error(
                'The invoice proof could not be saved. Check the image and try again.',
            );
        } finally {
            setProcessing(false);
        }
    }

    async function startCamera() {
        if (!navigator.mediaDevices?.getUserMedia) {
            input.current?.click();
            return;
        }
        try {
            stream.current = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: 'environment' },
            });
            setCamera(true);
            queueMicrotask(() => {
                if (video.current) video.current.srcObject = stream.current;
            });
        } catch {
            input.current?.click();
        }
    }

    function capture() {
        if (!video.current || video.current.videoWidth === 0) return;
        const canvas = document.createElement('canvas');
        canvas.width = video.current.videoWidth;
        canvas.height = video.current.videoHeight;
        canvas.getContext('2d')?.drawImage(video.current, 0, 0);
        canvas.toBlob((blob) => {
            if (!blob) return;
            choosePreview(
                new File([blob], `invoice-${Date.now()}.jpg`, {
                    type: 'image/jpeg',
                }),
            );
        }, 'image/jpeg', 0.9);
    }

    async function remove() {
        setProcessing(true);
        try {
            await http.getClient().request({
                ...destroy(paymentId),
                headers: { Accept: 'application/json' },
            });
            toast.success('Invoice proof removed.');
            onChanged(null);
            closeDialog();
        } catch {
            toast.error('The invoice proof could not be removed.');
        } finally {
            setProcessing(false);
        }
    }

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (!value) {
                    closeDialog();
                }
            }}
        >
            <DialogContent className="flex max-h-[92dvh] max-w-lg flex-col gap-0 overflow-hidden p-0">
                <header className="flex items-center justify-between gap-2 border-b border-neutral-200 px-3.5 py-3">
                    <button
                        type="button"
                        onClick={closeDialog}
                        className="inline-flex h-10 items-center gap-2 rounded-[10px] px-2 text-[13px] font-semibold hover:bg-neutral-100"
                    >
                        <ArrowLeft className="size-4" /> Back
                    </button>
                    <DialogTitle className="text-sm">Capture invoice</DialogTitle>
                    <span className="w-14" />
                </header>
                <DialogDescription className="px-4 pt-4 text-xs leading-5 text-neutral-600">
                    Select or capture the cashless receipt, review it locally,
                    then confirm to attach it to this transaction.
                </DialogDescription>
                <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                    {invoice && !preview && !camera && (
                        <div className="rounded-[13px] border border-neutral-200 p-3">
                            <div className="flex items-center gap-3">
                                <img
                                    src={invoice.url}
                                    alt="Invoice attached"
                                    className="size-16 rounded-[9px] bg-neutral-100 object-cover"
                                />
                                <div className="min-w-0 flex-1">
                                    <p className="text-[13px] font-semibold">
                                        Invoice attached
                                    </p>
                                    <p className="truncate text-[11px] text-neutral-500">
                                        {invoice.name}
                                    </p>
                                </div>
                                <Check className="size-4 text-green-700" />
                            </div>
                            <div className="mt-3 grid grid-cols-3 gap-2">
                                <a
                                    href={invoice.url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex h-10 items-center justify-center gap-1.5 rounded-[10px] border text-xs font-semibold"
                                >
                                    <Eye className="size-3.5" /> View
                                </a>
                                {mutable && (
                                    <Button
                                        variant="outline"
                                        className="h-10 rounded-[10px] text-xs"
                                        onClick={() => input.current?.click()}
                                    >
                                        <RefreshCw /> Replace
                                    </Button>
                                )}
                                {mutable && (
                                    <Button
                                        variant="outline"
                                        className="h-10 rounded-[10px] text-xs text-red-700"
                                        disabled={processing}
                                        onClick={() => void remove()}
                                    >
                                        <Trash2 /> Remove
                                    </Button>
                                )}
                            </div>
                        </div>
                    )}

                    {camera && !preview && (
                        <div className="space-y-3">
                            <video
                                ref={video}
                                autoPlay
                                playsInline
                                muted
                                className="aspect-4/3 w-full rounded-[14px] bg-black object-cover"
                            />
                            <Button
                                className="h-12 w-full rounded-xl bg-neutral-950"
                                onClick={capture}
                            >
                                <Camera /> Capture
                            </Button>
                        </div>
                    )}

                    {preview && (
                        <div className="space-y-3">
                            <img
                                src={preview.url}
                                alt="Local invoice preview"
                                className="aspect-4/3 w-full rounded-[14px] bg-neutral-950 object-contain"
                            />
                            <p className="text-center text-[11px] text-neutral-500">
                                Local preview — nothing is uploaded until you
                                confirm.
                            </p>
                            <div className="grid grid-cols-2 gap-2">
                                <Button
                                    variant="outline"
                                    className="h-12 rounded-xl"
                                    disabled={processing}
                                    onClick={retry}
                                >
                                    <RefreshCw /> Retry
                                </Button>
                                <Button
                                    className="h-12 rounded-xl bg-neutral-950"
                                    disabled={processing}
                                    onClick={() => void confirm()}
                                >
                                    <Check />
                                    {processing ? 'Uploading…' : 'Confirm'}
                                </Button>
                            </div>
                        </div>
                    )}

                    {mutable && !camera && !preview && (
                        <div className="grid gap-2 sm:grid-cols-2">
                            <Button
                                className="h-12 rounded-xl bg-neutral-950"
                                disabled={processing}
                                onClick={() => void startCamera()}
                            >
                                <Camera /> Use camera
                            </Button>
                            <Button
                                variant="outline"
                                className="h-12 rounded-xl"
                                disabled={processing}
                                onClick={() => input.current?.click()}
                            >
                                <FileImage /> Choose image
                            </Button>
                        </div>
                    )}
                    <input
                        ref={input}
                        hidden
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        capture="environment"
                        onChange={(event) => {
                            const file = event.target.files?.[0];
                            if (file) choosePreview(file);
                        }}
                    />
                </div>
            </DialogContent>
        </Dialog>
    );
}
