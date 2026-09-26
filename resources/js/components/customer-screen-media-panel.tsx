import {
    ArrowDown,
    ArrowUp,
    Film,
    ImageIcon,
    ImagePlus,
    Trash2,
    Tv,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    controlClass,
    primaryActionClass,
} from '@/components/catalog-ui';
import { OwnerStatusBadge } from '@/components/owner-ui';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { qrError, qrRequest } from '@/lib/qr-http';
import {
    destroy as destroyMedia,
    index as mediaIndex,
    reorder as reorderMedia,
    store as storeMedia,
    update as updateMedia,
} from '@/routes/branches/customer-screen-media';

type Media = {
    id: string;
    type: 'image' | 'video';
    label: string;
    duration_seconds: number;
    is_active: boolean;
    size_bytes: number;
    preview_url: string | null;
};

type Limits = {
    max_items: number;
    image_max_mb: number;
    video_max_mb: number;
    video_max_seconds: number;
};

/**
 * Settings › Customer Screen (Phase 19.6A): one Branch's advertisements, played in this order on its paired customer
 * screens while neither MENU nor CUSTOMER DISPLAY is on. The server validates every file (type from content, size,
 * video length), stores it under a server-generated path and re-encodes images; only this Branch is ever touched.
 */
export function CustomerScreenMediaPanel({ branchId }: { branchId: string }) {
    const [media, setMedia] = useState<Media[] | null>(null);
    const [limits, setLimits] = useState<Limits | null>(null);
    const [loadError, setLoadError] = useState('');
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        try {
            const result = await qrRequest<{ media: Media[]; limits: Limits }>(
                mediaIndex(branchId),
            );
            setMedia(result.media);
            setLimits(result.limits);
            setLoadError('');
        } catch (reason) {
            setLoadError(qrError(reason).message);
        }
    }, [branchId]);
    useEffect(() => {
        void load();
    }, [load]);

    const run = async (task: () => Promise<unknown>, success?: string) => {
        if (busy) return;
        setBusy(true);
        try {
            await task();
            if (success) toast.success(success);
        } catch (reason) {
            toast.error(qrError(reason).message);
        } finally {
            setBusy(false);
            await load();
        }
    };

    const move = (index: number, offset: -1 | 1) => {
        if (media === null) return;
        const target = index + offset;
        if (target < 0 || target >= media.length) return;
        const ids = media.map((item) => item.id);
        [ids[index], ids[target]] = [ids[target], ids[index]];
        void run(() => qrRequest(reorderMedia(branchId), { ids }));
    };

    return (
        <div className="flex flex-col gap-4">
            <UploadForm
                branchId={branchId}
                limits={limits}
                full={
                    media !== null &&
                    limits !== null &&
                    media.length >= limits.max_items
                }
                onUploaded={load}
            />
            {loadError ? (
                <p role="alert" className="text-sm text-red-700">
                    {loadError}
                </p>
            ) : media === null ? (
                <p className="flex items-center gap-2 text-sm text-neutral-500">
                    <Spinner /> Loading advertisements…
                </p>
            ) : media.length === 0 ? (
                <div className="flex flex-col items-center gap-2 rounded-[14px] border border-dashed border-neutral-300 px-5 py-10 text-center">
                    <Tv className="size-7 text-neutral-400" />
                    <p className="text-sm font-semibold">
                        No advertisements yet
                    </p>
                    <p className="max-w-sm text-xs text-neutral-500">
                        The customer screen shows the PONGSKILOG welcome screen
                        until you add images or videos.
                    </p>
                </div>
            ) : (
                <ol
                    className="flex flex-col gap-2"
                    aria-label="Advertisement order"
                >
                    {media.map((item, index) => (
                        <MediaRow
                            key={item.id}
                            item={item}
                            position={index + 1}
                            first={index === 0}
                            last={index === media.length - 1}
                            busy={busy}
                            onMove={(offset) => move(index, offset)}
                            onSave={(changes, message) =>
                                void run(
                                    () =>
                                        qrRequest(
                                            updateMedia({
                                                branch: branchId,
                                                media: item.id,
                                            }),
                                            changes,
                                        ),
                                    message,
                                )
                            }
                            onDelete={() => {
                                if (
                                    window.confirm(
                                        `Delete “${item.label}” from this Branch's customer screen?`,
                                    )
                                ) {
                                    void run(
                                        () =>
                                            qrRequest(
                                                destroyMedia({
                                                    branch: branchId,
                                                    media: item.id,
                                                }),
                                            ),
                                        'Advertisement deleted',
                                    );
                                }
                            }}
                        />
                    ))}
                </ol>
            )}
        </div>
    );
}

function UploadForm({
    branchId,
    limits,
    full,
    onUploaded,
}: {
    branchId: string;
    limits: Limits | null;
    full: boolean;
    onUploaded: () => Promise<void>;
}) {
    const input = useRef<HTMLInputElement>(null);
    const [file, setFile] = useState<File | null>(null);
    const [label, setLabel] = useState('');
    const [duration, setDuration] = useState('8');
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const isVideo = file?.type.startsWith('video/') ?? false;

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        if (busy || file === null) return;
        setBusy(true);
        setError('');
        try {
            const payload = new FormData();
            payload.set('file', file);
            if (label.trim() !== '') payload.set('label', label.trim());
            if (!isVideo) payload.set('duration_seconds', duration);
            await qrRequest(storeMedia(branchId), payload);
            toast.success('Advertisement added');
            setFile(null);
            setLabel('');
            if (input.current) input.current.value = '';
            await onUploaded();
        } catch (reason) {
            setError(qrError(reason).message);
        } finally {
            setBusy(false);
        }
    };

    return (
        <form
            onSubmit={submit}
            className="grid gap-3 rounded-[14px] border border-neutral-200 bg-neutral-50 p-4 md:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_120px_auto] md:items-end"
        >
            <label className="flex min-w-0 flex-col gap-1.5 text-xs font-semibold">
                Image or video
                <input
                    ref={input}
                    type="file"
                    accept="image/jpeg,image/png,image/webp,video/mp4"
                    disabled={full || busy}
                    onChange={(event) => {
                        setFile(event.target.files?.[0] ?? null);
                        setError('');
                    }}
                    className="block min-h-11 w-full rounded-[11px] border border-neutral-200 bg-white px-3 py-2 text-xs file:mr-3 file:rounded-md file:border-0 file:bg-neutral-950 file:px-3 file:py-1.5 file:text-xs file:font-semibold file:text-white"
                />
            </label>
            <label className="flex min-w-0 flex-col gap-1.5 text-xs font-semibold">
                Label (optional)
                <input
                    value={label}
                    maxLength={80}
                    onChange={(event) => setLabel(event.target.value)}
                    className={controlClass}
                    placeholder="e.g. Silog promo"
                />
            </label>
            <label className="flex flex-col gap-1.5 text-xs font-semibold">
                Show for
                <select
                    value={duration}
                    disabled={isVideo}
                    onChange={(event) => setDuration(event.target.value)}
                    className={controlClass}
                >
                    {[5, 8, 10, 15, 20, 30, 45, 60].map((seconds) => (
                        <option key={seconds} value={seconds}>
                            {seconds} s
                        </option>
                    ))}
                </select>
            </label>
            <Button
                type="submit"
                disabled={file === null || busy || full}
                className={`${primaryActionClass} gap-2`}
            >
                {busy ? <Spinner /> : <ImagePlus className="size-4" />}
                Upload
            </Button>
            <p className="text-[11px] leading-5 text-neutral-500 md:col-span-4">
                {full
                    ? `This Branch has the maximum of ${limits?.max_items ?? 30} advertisements. Delete one to add another.`
                    : `Images: JPG, PNG or WebP up to ${limits?.image_max_mb ?? 10} MB (shown up to 1920 px). Videos: MP4 (H.264) up to ${limits?.video_max_mb ?? 50} MB and ${limits?.video_max_seconds ?? 60} seconds, played muted for their full length. Ads play while neither MENU nor CUSTOMER DISPLAY is on.`}
            </p>
            {error && (
                <p role="alert" className="text-xs text-red-700 md:col-span-4">
                    {error}
                </p>
            )}
        </form>
    );
}

function MediaRow({
    item,
    position,
    first,
    last,
    busy,
    onMove,
    onSave,
    onDelete,
}: {
    item: Media;
    position: number;
    first: boolean;
    last: boolean;
    busy: boolean;
    onMove: (offset: -1 | 1) => void;
    onSave: (
        changes: Partial<
            Pick<Media, 'label' | 'duration_seconds' | 'is_active'>
        >,
        message?: string,
    ) => void;
    onDelete: () => void;
}) {
    const [label, setLabel] = useState(item.label);

    return (
        <li className="grid grid-cols-[auto_minmax(0,1fr)] gap-3 rounded-[14px] border border-neutral-200 bg-white p-3 sm:grid-cols-[auto_96px_minmax(0,1fr)_auto] sm:items-center">
            <span className="flex size-8 items-center justify-center rounded-full bg-neutral-100 text-xs font-bold text-neutral-600">
                {position}
            </span>
            <div className="col-span-1 hidden aspect-video w-24 overflow-hidden rounded-lg bg-neutral-900 sm:block">
                {item.preview_url ? (
                    item.type === 'image' ? (
                        <img
                            src={item.preview_url}
                            alt=""
                            loading="lazy"
                            className="size-full object-cover"
                        />
                    ) : (
                        <video
                            src={item.preview_url}
                            muted
                            preload="metadata"
                            className="size-full object-cover"
                        />
                    )
                ) : null}
            </div>
            <div className="col-span-2 flex min-w-0 flex-col gap-2 sm:col-span-1">
                <div className="flex flex-wrap items-center gap-2">
                    <OwnerStatusBadge
                        tone={item.is_active ? 'green' : 'outline'}
                    >
                        {item.is_active ? 'Active' : 'Hidden'}
                    </OwnerStatusBadge>
                    <span className="inline-flex items-center gap-1 text-[11px] font-semibold text-neutral-500">
                        {item.type === 'image' ? (
                            <ImageIcon className="size-3.5" />
                        ) : (
                            <Film className="size-3.5" />
                        )}
                        {item.type === 'image' ? 'Image' : 'Video'} ·{' '}
                        {item.duration_seconds} s ·{' '}
                        {(item.size_bytes / 1024 / 1024).toFixed(1)} MB
                    </span>
                </div>
                <input
                    value={label}
                    maxLength={80}
                    aria-label={`Label of advertisement ${position}`}
                    onChange={(event) => setLabel(event.target.value)}
                    onBlur={() => {
                        const next = label.trim();
                        if (next !== '' && next !== item.label) {
                            onSave({ label: next }, 'Label saved');
                        } else {
                            setLabel(item.label);
                        }
                    }}
                    className={`${controlClass} h-10`}
                />
            </div>
            <div className="col-span-2 flex flex-wrap items-center gap-2 sm:col-span-1 sm:justify-end">
                {item.type === 'image' && (
                    <select
                        value={item.duration_seconds}
                        disabled={busy}
                        aria-label="Show for"
                        onChange={(event) =>
                            onSave(
                                {
                                    duration_seconds: Number(
                                        event.target.value,
                                    ),
                                },
                                'Duration saved',
                            )
                        }
                        className={`${controlClass} h-11 w-auto`}
                    >
                        {[
                            ...new Set([
                                5,
                                8,
                                10,
                                15,
                                20,
                                30,
                                45,
                                60,
                                item.duration_seconds,
                            ]),
                        ]
                            .sort((left, right) => left - right)
                            .map((seconds) => (
                                <option key={seconds} value={seconds}>
                                    {seconds} s
                                </option>
                            ))}
                    </select>
                )}
                <Button
                    type="button"
                    variant="outline"
                    className={actionClass}
                    disabled={busy}
                    onClick={() =>
                        onSave(
                            { is_active: !item.is_active },
                            item.is_active
                                ? 'Hidden from the screen'
                                : 'Showing on the screen',
                        )
                    }
                >
                    {item.is_active ? 'Hide' : 'Show'}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className={`${actionClass} size-11 p-0`}
                    disabled={busy || first}
                    onClick={() => onMove(-1)}
                    aria-label="Move up"
                >
                    <ArrowUp className="size-4" />
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className={`${actionClass} size-11 p-0`}
                    disabled={busy || last}
                    onClick={() => onMove(1)}
                    aria-label="Move down"
                >
                    <ArrowDown className="size-4" />
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className={`${actionClass} size-11 p-0 text-red-700`}
                    disabled={busy}
                    onClick={onDelete}
                    aria-label={`Delete ${item.label}`}
                >
                    <Trash2 className="size-4" />
                </Button>
            </div>
        </li>
    );
}
