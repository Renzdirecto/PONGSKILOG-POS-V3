import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { CustomerScreenPlaylist } from '@/lib/customer-screen';

/**
 * The default customer screen: the Branch's active advertisements in their sequence. Images stay for their duration,
 * videos play muted to the end (bounded by their duration plus a margin); a file that fails to load is skipped. With
 * no playable media the screen shows the clean PONGSKILOG idle state — never placeholder content.
 */
export function CustomerScreenAds({
    playlist,
    branchName,
}: {
    playlist: CustomerScreenPlaylist | null;
    branchName: string | null;
}) {
    const [failed, setFailed] = useState<ReadonlySet<string>>(new Set());
    const playable = useMemo(
        () => (playlist?.items ?? []).filter((item) => !failed.has(item.key)),
        [playlist, failed],
    );
    const [currentKey, setCurrentKey] = useState<string | null>(null);
    const index = Math.max(
        0,
        playable.findIndex((item) => item.key === currentKey),
    );
    const current = playable[index] ?? null;
    const next =
        playable.length > 1 ? playable[(index + 1) % playable.length] : null;
    const playableRef = useRef(playable);
    playableRef.current = playable;

    /** Moves to the slide after the current one; a renewed playlist keeps its place when that slide still exists. */
    const advance = useCallback(() => {
        const list = playableRef.current;
        if (list.length === 0) return;
        setCurrentKey((key) => {
            const position = Math.max(
                0,
                list.findIndex((item) => item.key === key),
            );

            return list[(position + 1) % list.length].key;
        });
    }, []);

    const currentType = current?.type ?? null;
    const currentDuration = current?.duration_ms ?? 0;
    const single = playable.length === 1;
    useEffect(() => {
        if (currentType === null || (currentType === 'video' && single)) {
            return;
        }
        const bound =
            currentType === 'image' ? currentDuration : currentDuration + 3000;
        const timer = window.setTimeout(advance, Math.max(2000, bound));

        return () => window.clearTimeout(timer);
    }, [current?.key, currentType, currentDuration, single, advance]);

    /** Warm the browser cache for the next image so slides change without a blank frame. */
    useEffect(() => {
        if (next?.type !== 'image') return;
        const image = new Image();
        image.decoding = 'async';
        image.src = next.url;
    }, [next?.url, next?.type]);

    const skip = (key: string) =>
        setFailed((previous) => new Set([...previous, key]));

    if (!current) {
        return <IdleBrand branchName={branchName} />;
    }

    return (
        <div className="relative flex min-h-0 flex-1 items-center justify-center overflow-hidden bg-black">
            {current.type === 'image' ? (
                <img
                    key={current.key}
                    src={current.url}
                    alt=""
                    decoding="async"
                    onError={() => skip(current.key)}
                    className="animate-in fade-in absolute inset-0 size-full object-contain duration-700"
                />
            ) : (
                <video
                    key={current.key}
                    src={current.url}
                    autoPlay
                    muted
                    playsInline
                    loop={single}
                    preload="auto"
                    onEnded={advance}
                    onError={() => skip(current.key)}
                    className="animate-in fade-in absolute inset-0 size-full object-contain duration-700"
                />
            )}
        </div>
    );
}

export function IdleBrand({ branchName }: { branchName: string | null }) {
    return (
        <div className="flex min-h-0 flex-1 flex-col items-center justify-center gap-5 bg-[radial-gradient(circle_at_center,#1f2020_0%,#0c0d0d_70%)] px-6 text-center">
            <img
                src="/images/branding/pongskilog-emblem.png"
                alt=""
                className="size-[clamp(120px,22vmin,220px)] rounded-full"
            />
            <div>
                <p className="text-[clamp(28px,6vmin,64px)] font-black tracking-[0.16em]">
                    PONGSKILOG
                </p>
                <p className="mt-1 text-[clamp(13px,2.2vmin,20px)] font-semibold tracking-[0.3em] text-[#f5c542] uppercase">
                    Est. 2022
                </p>
            </div>
            {branchName && (
                <p className="text-[clamp(14px,2.4vmin,22px)] font-semibold text-white/55">
                    Welcome to {branchName}
                </p>
            )}
        </div>
    );
}
