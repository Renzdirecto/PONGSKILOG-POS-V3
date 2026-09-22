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

const MAX_INVOICE_EDGE = 1600;
const MAX_INVOICE_UPLOAD_BYTES = 1_500_000;

function loadInvoiceImage(url: string): Promise<HTMLImageElement> {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () =>
            reject(new Error('The selected image could not be read.'));
        image.src = url;
    });
}

function invoiceCanvasBlob(
    canvas: HTMLCanvasElement,
    quality: number,
): Promise<Blob> {
    return new Promise((resolve, reject) => {
        canvas.toBlob(
            (blob) => {
                if (blob) {
                    resolve(blob);
                    return;
                }

                reject(new Error('The selected image could not be prepared.'));
            },
            'image/jpeg',
            quality,
        );
    });
}

async function prepareInvoiceImage(file: File): Promise<File> {
    const sourceUrl = URL.createObjectURL(file);

    try {
        const image = await loadInvoiceImage(sourceUrl);
        const scale = Math.min(
            1,
            MAX_INVOICE_EDGE /
                Math.max(image.naturalWidth, image.naturalHeight),
        );
        const canvas = document.createElement('canvas');
        canvas.width = Math.max(1, Math.round(image.naturalWidth * scale));
        canvas.height = Math.max(1, Math.round(image.naturalHeight * scale));
        const context = canvas.getContext('2d');

        if (!context) {
            throw new Error('The selected image could not be prepared.');
        }

        context.drawImage(image, 0, 0, canvas.width, canvas.height);

        let blob = await invoiceCanvasBlob(canvas, 0.82);

        for (const quality of [0.68, 0.54]) {
            if (blob.size <= MAX_INVOICE_UPLOAD_BYTES) {
                break;
            }

            blob = await invoiceCanvasBlob(canvas, quality);
        }

        if (blob.size > MAX_INVOICE_UPLOAD_BYTES) {
            throw new Error(
                'The image is still too large. Try taking another photo.',
            );
        }

        return new File([blob], `invoice-${Date.now()}.jpg`, {
            type: 'image/jpeg',
            lastModified: Date.now(),
        });
    } finally {
        URL.revokeObjectURL(sourceUrl);
    }
}

function invoiceUploadError(error: unknown): string {
    if (typeof error === 'object' && error !== null && 'response' in error) {
        const response = (error as { response?: { data?: unknown } }).response;
        let data = response?.data;

        if (typeof data === 'string') {
            try {
                data = JSON.parse(data) as unknown;
            } catch {
                data = null;
            }
        }

        if (typeof data === 'object' && data !== null && 'errors' in data) {
            const errors = (data as { errors?: { invoice?: unknown } }).errors;
            const message = errors?.invoice;

            if (Array.isArray(message) && typeof message[0] === 'string') {
                return message[0];
            }
        }
    }

    return 'The invoice proof could not be saved. Please try again.';
}

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
    const [viewingInvoice, setViewingInvoice] = useState(false);

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

    async function choosePreview(file: File) {
        stopCamera();
        setProcessing(true);

        try {
            const preparedFile = await prepareInvoiceImage(file);
            setPreview({
                file: preparedFile,
                url: URL.createObjectURL(preparedFile),
            });
        } catch (error) {
            toast.error(
                error instanceof Error
                    ? error.message
                    : 'The selected image could not be prepared.',
            );
        } finally {
            setProcessing(false);
        }
    }

    function retry() {
        setPreview(null);
        if (input.current) input.current.value = '';
    }

    function closeDialog() {
        stopCamera();
        retry();
        setViewingInvoice(false);
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
            onChanged(
                (JSON.parse(response.data) as { invoice: Invoice }).invoice,
            );
            closeDialog();
        } catch (error) {
            toast.error(invoiceUploadError(error));
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
        const scale = Math.min(
            1,
            MAX_INVOICE_EDGE /
                Math.max(video.current.videoWidth, video.current.videoHeight),
        );
        canvas.width = Math.max(
            1,
            Math.round(video.current.videoWidth * scale),
        );
        canvas.height = Math.max(
            1,
            Math.round(video.current.videoHeight * scale),
        );
        canvas
            .getContext('2d')
            ?.drawImage(video.current, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(
            (blob) => {
                if (!blob) return;
                void choosePreview(
                    new File([blob], `invoice-${Date.now()}.jpg`, {
                        type: 'image/jpeg',
                    }),
                );
            },
            'image/jpeg',
            0.82,
        );
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
                    <DialogTitle className="text-sm">
                        Capture invoice
                    </DialogTitle>
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
                                <button
                                    type="button"
                                    onClick={() => setViewingInvoice(true)}
                                    className="inline-flex h-10 items-center justify-center gap-1.5 rounded-[10px] border text-xs font-semibold"
                                >
                                    <Eye className="size-3.5" /> View
                                </button>
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
                            if (file) void choosePreview(file);
                        }}
                    />
                </div>
            </DialogContent>
            {invoice && (
                <Dialog open={viewingInvoice} onOpenChange={setViewingInvoice}>
                    <DialogContent className="pos-surface flex max-h-[92dvh] max-w-[calc(100%-2rem)] flex-col gap-0 overflow-hidden p-0 sm:max-w-3xl">
                        <header className="border-b border-neutral-200 px-5 py-4 pr-12">
                            <DialogTitle className="text-base">
                                Invoice receipt
                            </DialogTitle>
                            <DialogDescription className="mt-1 truncate text-xs">
                                {invoice.name}
                            </DialogDescription>
                        </header>
                        <div className="flex min-h-0 flex-1 items-center justify-center overflow-auto bg-neutral-100 p-3 sm:p-5">
                            <img
                                src={invoice.url}
                                alt="Invoice receipt"
                                className="max-h-[calc(92dvh-7rem)] max-w-full rounded-[12px] bg-white object-contain shadow-sm"
                            />
                        </div>
                    </DialogContent>
                </Dialog>
            )}
        </Dialog>
    );
}
