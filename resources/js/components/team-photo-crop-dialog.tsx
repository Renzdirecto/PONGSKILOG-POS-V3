import { Minus, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { KeyboardEvent, PointerEvent } from 'react';
import { actionClass, primaryActionClass } from '@/components/catalog-ui';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    TEAM_PHOTO,
    TEAM_PHOTO_MAX_ZOOM,
    clampTeamCrop,
    movedTeamCrop,
    teamCropFrame,
    teamCropRect,
} from '@/lib/website-team';
import type { TeamPhotoCrop } from '@/lib/website-team';

type Photo = { url: string; width: number; height: number };

/** What the preview card says, as the landing page would show it. */
export type TeamCropCard = {
    code: string;
    title: string;
    name: string;
    bio: string;
};

/**
 * Crop, zoom and move a new team portrait inside the landing card's 3:4 frame before it is saved. The frame is drawn
 * like the public card (role tab, shade, name, bio and role), so what is framed here is what visitors see. The photo
 * is cut to that frame in the browser and handed back as one JPEG; the server still checks and re-encodes it to WebP
 * as before. A mouse drags, a finger drags or pinches, and the keyboard moves (arrows) and zooms (+ and -).
 */
export function TeamPhotoCropDialog({
    file,
    card,
    saveLabel,
    onCancel,
    onCropped,
}: {
    file: File;
    card: TeamCropCard;
    saveLabel: string;
    onCancel: () => void;
    onCropped: (photo: File) => void;
}) {
    const [photo, setPhoto] = useState<Photo | null>(null);
    const [crop, setCrop] = useState<TeamPhotoCrop>({
        zoom: 1,
        x: 0.5,
        y: 0.5,
    });
    const [error, setError] = useState('');
    const [saving, setSaving] = useState(false);
    const frame = useRef<HTMLDivElement>(null);
    const image = useRef<HTMLImageElement>(null);
    const pointers = useRef(new Map<number, { x: number; y: number }>());
    const pinch = useRef<{ distance: number; zoom: number } | null>(null);

    useEffect(() => {
        const url = URL.createObjectURL(file);
        const probe = new Image();
        probe.onload = () =>
            setPhoto({
                url,
                width: probe.naturalWidth,
                height: probe.naturalHeight,
            });
        probe.onerror = () =>
            setError(
                'This photo could not be opened. Choose a JPG, PNG or WebP photo.',
            );
        probe.src = url;
        return () => {
            probe.onload = null;
            probe.onerror = null;
            URL.revokeObjectURL(url);
        };
    }, [file]);

    const spread = () => {
        const [a, b] = [...pointers.current.values()];
        return a && b ? Math.hypot(a.x - b.x, a.y - b.y) : 0;
    };
    const zoomTo = (zoom: number) => {
        if (photo)
            setCrop((current) => clampTeamCrop({ ...current, zoom }, photo));
    };
    const release = (event: PointerEvent<HTMLDivElement>) => {
        pointers.current.delete(event.pointerId);
        if (pointers.current.size < 2) pinch.current = null;
    };
    const keys = (event: KeyboardEvent<HTMLDivElement>) => {
        if (!photo) return;
        const step = event.shiftKey ? 0.1 : 0.02;
        const moves: Record<string, [number, number]> = {
            ArrowLeft: [step, 0],
            ArrowRight: [-step, 0],
            ArrowUp: [0, step],
            ArrowDown: [0, -step],
        };
        if (moves[event.key]) {
            event.preventDefault();
            setCrop((current) =>
                movedTeamCrop(current, photo, ...moves[event.key]),
            );
        } else if (event.key === '+' || event.key === '=') {
            event.preventDefault();
            zoomTo(crop.zoom + 0.2);
        } else if (event.key === '-') {
            event.preventDefault();
            zoomTo(crop.zoom - 0.2);
        }
    };
    const save = async () => {
        if (!photo || !image.current || saving) return;
        setSaving(true);
        setError('');
        const rect = teamCropRect(crop, photo);
        /** Never larger than the server keeps, and never enlarged past the photo's own pixels. */
        const width = Math.max(
            1,
            Math.min(TEAM_PHOTO.width, Math.round(rect.width)),
        );
        const height = Math.round(
            (width * TEAM_PHOTO.height) / TEAM_PHOTO.width,
        );
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');
        const blob = context
            ? await new Promise<Blob | null>((resolve) => {
                  context.fillStyle = '#f4f0e8';
                  context.fillRect(0, 0, width, height);
                  context.imageSmoothingQuality = 'high';
                  context.drawImage(
                      image.current as HTMLImageElement,
                      rect.x,
                      rect.y,
                      rect.width,
                      rect.height,
                      0,
                      0,
                      width,
                      height,
                  );
                  canvas.toBlob(resolve, 'image/jpeg', 0.92);
              })
            : null;
        setSaving(false);
        if (!blob) {
            setError('This photo could not be cropped. Try another photo.');
            return;
        }
        onCropped(new File([blob], 'team-photo.jpg', { type: 'image/jpeg' }));
    };

    return (
        <Dialog
            open
            onOpenChange={(value) => {
                if (!value && !saving) onCancel();
            }}
        >
            <DialogContent className="max-h-[94svh] overflow-y-auto sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Frame the photo</DialogTitle>
                    <DialogDescription>
                        Drag the photo to move it and zoom to fit. The card
                        below is how the landing page shows it.
                    </DialogDescription>
                </DialogHeader>
                <div
                    ref={frame}
                    role="group"
                    tabIndex={0}
                    aria-label="Photo position. Drag it, or use the arrow keys to move it and plus or minus to zoom."
                    className="relative mx-auto aspect-3/4 w-[min(100%,260px)] cursor-grab touch-none overflow-hidden rounded-[22px] border border-neutral-200 bg-[#f4f0e8] outline-offset-4 select-none active:cursor-grabbing"
                    onKeyDown={keys}
                    onPointerDown={(event) => {
                        if (!photo) return;
                        event.currentTarget.setPointerCapture(event.pointerId);
                        pointers.current.set(event.pointerId, {
                            x: event.clientX,
                            y: event.clientY,
                        });
                        if (pointers.current.size === 2)
                            pinch.current = {
                                distance: spread(),
                                zoom: crop.zoom,
                            };
                    }}
                    onPointerMove={(event) => {
                        const last = pointers.current.get(event.pointerId);
                        const box = frame.current?.getBoundingClientRect();
                        if (!last || !photo || !box) return;
                        pointers.current.set(event.pointerId, {
                            x: event.clientX,
                            y: event.clientY,
                        });
                        const started = pinch.current;
                        if (started) {
                            if (started.distance > 0)
                                zoomTo(
                                    (started.zoom * spread()) /
                                        started.distance,
                                );
                            return;
                        }
                        setCrop((current) =>
                            movedTeamCrop(
                                current,
                                photo,
                                (event.clientX - last.x) / box.width,
                                (event.clientY - last.y) / box.height,
                            ),
                        );
                    }}
                    onPointerUp={release}
                    onPointerCancel={release}
                >
                    {photo ? (
                        <img
                            ref={image}
                            src={photo.url}
                            alt=""
                            draggable={false}
                            className="pointer-events-none absolute max-w-none"
                            style={teamCropFrame(crop, photo)}
                        />
                    ) : (
                        !error && (
                            <span className="absolute inset-0 flex items-center justify-center">
                                <Spinner />
                            </span>
                        )
                    )}
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-0 bg-[linear-gradient(180deg,rgba(18,17,16,0)_58%,rgba(18,17,16,0.7)_80%,rgba(18,17,16,0.92)_100%)]"
                    />
                    <span className="pointer-events-none absolute top-0 left-0 inline-flex h-9 items-center rounded-br-[14px] bg-white pr-3.5 pl-4 text-[13px] font-extrabold tracking-[0.16em] uppercase shadow-[0_4px_14px_rgba(18,17,16,0.16)]">
                        {card.code || 'ROLE'}
                    </span>
                    <div className="pointer-events-none absolute inset-x-0 bottom-0 flex flex-col gap-1 px-4 pt-3.5 pb-4 text-white">
                        <strong className="text-[19px] leading-none font-extrabold wrap-break-word uppercase">
                            {card.name.trim() || 'Name to follow'}
                        </strong>
                        <span className="text-[13px] leading-snug text-white/80">
                            {card.bio.trim() || 'Details to follow.'}
                        </span>
                        <span className="mt-1.5 border-t border-white/20 pt-2 text-[11px] font-bold tracking-[0.14em] text-[#e2b860] uppercase">
                            {card.title || 'Role'}
                        </span>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-11 shrink-0"
                        aria-label="Zoom out"
                        disabled={!photo || crop.zoom <= 1}
                        onClick={() => zoomTo(crop.zoom - 0.2)}
                    >
                        <Minus className="size-4" aria-hidden="true" />
                    </Button>
                    <input
                        type="range"
                        aria-label="Zoom"
                        min={1}
                        max={TEAM_PHOTO_MAX_ZOOM}
                        step={0.01}
                        value={crop.zoom}
                        disabled={!photo}
                        onChange={(event) => zoomTo(Number(event.target.value))}
                        className="h-11 min-w-0 flex-1 accent-neutral-900"
                    />
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className="size-11 shrink-0"
                        aria-label="Zoom in"
                        disabled={!photo || crop.zoom >= TEAM_PHOTO_MAX_ZOOM}
                        onClick={() => zoomTo(crop.zoom + 0.2)}
                    >
                        <Plus className="size-4" aria-hidden="true" />
                    </Button>
                </div>
                <p
                    role={error ? 'alert' : undefined}
                    className={`text-xs ${error ? 'font-semibold text-red-700' : 'text-neutral-600'}`}
                >
                    {error ||
                        'Saved as a 3:4 portrait, resized for the web. The original photo is not uploaded.'}
                </p>
                <div className="flex flex-wrap justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className={`${actionClass} min-h-11`}
                        disabled={saving}
                        onClick={onCancel}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        className={`${primaryActionClass} min-h-11`}
                        disabled={!photo || saving}
                        onClick={() => void save()}
                    >
                        {saving && <Spinner />} {saveLabel}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
