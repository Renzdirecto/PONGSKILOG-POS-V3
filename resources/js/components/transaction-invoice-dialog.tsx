import { http } from '@inertiajs/react';
import { Camera, FileImage, Trash2 } from 'lucide-react';
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

export function TransactionInvoiceDialog({
    paymentId,
    invoice,
    open,
    mutable,
    onClose,
    onChanged,
}: {
    paymentId: string;
    invoice: { name: string; url: string } | null;
    open: boolean;
    mutable: boolean;
    onClose: () => void;
    onChanged: (invoice: { name: string; url: string } | null) => void;
}) {
    const input = useRef<HTMLInputElement>(null);
    const video = useRef<HTMLVideoElement>(null);
    const stream = useRef<MediaStream | null>(null);
    const [camera, setCamera] = useState(false);
    const [processing, setProcessing] = useState(false);

    useEffect(() => () => stream.current?.getTracks().forEach((track) => track.stop()), []);

    async function upload(file: File) {
        setProcessing(true);
        const data = new FormData();
        data.append('invoice', file);
        try {
            const response = await http.getClient().request({
                ...store(paymentId),
                data,
                headers: { Accept: 'application/json' },
            });
            toast.success('Invoice proof saved.');
            onChanged((JSON.parse(response.data) as { invoice: { name: string; url: string } }).invoice);
            onClose();
        } catch {
            toast.error('The invoice proof could not be saved. Check the image and try again.');
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
            stream.current = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
            setCamera(true);
            queueMicrotask(() => {
                if (video.current) video.current.srcObject = stream.current;
            });
        } catch {
            input.current?.click();
        }
    }

    function capture() {
        if (!video.current) return;
        const canvas = document.createElement('canvas');
        canvas.width = video.current.videoWidth;
        canvas.height = video.current.videoHeight;
        canvas.getContext('2d')?.drawImage(video.current, 0, 0);
        canvas.toBlob((blob) => {
            if (blob) void upload(new File([blob], `invoice-${Date.now()}.jpg`, { type: 'image/jpeg' }));
        }, 'image/jpeg', 0.9);
    }

    async function remove() {
        setProcessing(true);
        try {
            await http.getClient().request({ ...destroy(paymentId), headers: { Accept: 'application/json' } });
            toast.success('Invoice proof removed.');
            onChanged(null);
            onClose();
        } catch {
            toast.error('The invoice proof could not be removed.');
        } finally {
            setProcessing(false);
        }
    }

    return (
        <Dialog open={open} onOpenChange={(value) => !value && onClose()}>
            <DialogContent className="max-w-lg">
                <DialogTitle>Cashless invoice proof</DialogTitle>
                <DialogDescription>
                    One private image is stored for this payment row. JPG, PNG, or WebP up to 5 MB.
                </DialogDescription>
                {invoice && (
                    <a href={invoice.url} target="_blank" rel="noreferrer" className="rounded-xl border p-3 text-sm font-semibold text-red-700">
                        View {invoice.name}
                    </a>
                )}
                {camera && <video ref={video} autoPlay playsInline className="max-h-72 w-full rounded-xl bg-black" />}
                {mutable && (
                    <div className="grid gap-2 sm:grid-cols-2">
                        {camera ? (
                            <Button disabled={processing} onClick={capture}><Camera /> Capture</Button>
                        ) : (
                            <Button disabled={processing} onClick={startCamera}><Camera /> Use camera</Button>
                        )}
                        <Button variant="outline" disabled={processing} onClick={() => input.current?.click()}><FileImage /> Choose image</Button>
                        {invoice && <Button variant="destructive" disabled={processing} onClick={() => void remove()}><Trash2 /> Remove proof</Button>}
                        <input ref={input} hidden type="file" accept="image/jpeg,image/png,image/webp" capture="environment" onChange={(event) => event.target.files?.[0] && void upload(event.target.files[0])} />
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
