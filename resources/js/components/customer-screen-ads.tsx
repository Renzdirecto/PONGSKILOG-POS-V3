import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { PointerEvent as ReactPointerEvent } from 'react';
import {
    createSlideTimer,
    stepSlide,
    swipeDirection,
    type CustomerScreenPlaylist,
} from '@/lib/customer-screen';

/**
 * The default customer screen: the Branch's active advertisements in their sequence. Images stay for their duration,
 * videos play muted to the end (bounded by their duration plus a margin); a file that fails to load is skipped. With
 * no playable media the screen shows the clean PONGSKILOG idle state — never placeholder content.
 *
 * Customers may move through the ads: the arrows (desktop/tablet), a left/right swipe (touch) or the arrow keys start
 * the chosen slide with a fresh countdown; pressing and holding pauses the countdown (and a video) until released.
 * One slide timer exists for the whole slideshow, so navigation never leaves timers behind. While `suspended` (an
 * order summary or confirmation covers it) the slideshow is paused, not reset.
 */
export function CustomerScreenAds({
    playlist,
    branchName,
    suspended = false,
}: {
    playlist: CustomerScreenPlaylist | null;
    branchName: string | null;
    suspended?: boolean;
}) {
    /** Files that failed to load are skipped only until the playlist (with fresh signed links) is renewed. */
    const [failed, setFailed] = useState<{
        playlist: CustomerScreenPlaylist | null;
        keys: ReadonlySet<string>;
    }>({ playlist: null, keys: new Set() });
    const playable = useMemo(
        () =>
            (playlist?.items ?? []).filter(
                (item) =>
                    failed.playlist !== playlist || !failed.keys.has(item.key),
            ),
        [playlist, failed],
    );
    const [currentKey, setCurrentKey] = useState<string | null>(null);
    /** Bumped on every manual move so choosing the same (single) slide still restarts its countdown. */
    const [cycle, setCycle] = useState(0);
    const [holding, setHolding] = useState(false);
    const index = Math.max(
        0,
        playable.findIndex((item) => item.key === currentKey),
    );
    const current = playable[index] ?? null;
    const next =
        playable.length > 1 ? playable[(index + 1) % playable.length] : null;
    const playableRef = useRef(playable);
    playableRef.current = playable;
    const video = useRef<HTMLVideoElement>(null);
    const paused = holding || suspended;
    const pausedRef = useRef(paused);
    pausedRef.current = paused;

    /** Moves one slide forward or back; a renewed playlist keeps its place when that slide still exists. */
    const go = useCallback((direction: 1 | -1) => {
        const list = playableRef.current;
        if (list.length === 0) return;
        setCurrentKey((key) => {
            const position = Math.max(
                0,
                list.findIndex((item) => item.key === key),
            );

            return list[stepSlide(position, list.length, direction)].key;
        });
        setCycle((value) => value + 1);
    }, []);
    const timer = useMemo(() => createSlideTimer(() => go(1)), [go]);
    useEffect(() => () => timer.stop(), [timer]);

    const currentType = current?.type ?? null;
    const single = playable.length === 1;
    const durationMs =
        current === null
            ? 0
            : current.type === 'image'
              ? Math.max(2000, current.duration_ms)
              : current.duration_ms + 3000;
    const advances =
        currentType !== null && !(currentType === 'video' && single);

    /** Each slide (or manual move) gets one fresh countdown; a slide shown while paused starts paused. */
    useEffect(() => {
        if (!advances) {
            timer.stop();

            return;
        }
        timer.start(durationMs);
        if (pausedRef.current) {
            timer.pause();
            video.current?.pause();
        }

        return () => timer.stop();
    }, [current?.key, cycle, advances, durationMs, timer]);

    useEffect(() => {
        if (paused) {
            timer.pause();
            video.current?.pause();
        } else {
            timer.resume();
            void video.current?.play().catch(() => undefined);
        }
    }, [paused, timer]);

    /** Arrow keys on a desktop / second monitor. */
    useEffect(() => {
        if (suspended || playable.length < 2) return;
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'ArrowRight') go(1);
            if (event.key === 'ArrowLeft') go(-1);
        };
        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [suspended, playable.length, go]);

    /** Warm the browser cache for the next image so slides change without a blank frame. */
    useEffect(() => {
        if (next?.type !== 'image') return;
        const image = new Image();
        image.decoding = 'async';
        image.src = next.url;
    }, [next?.url, next?.type]);

    const pointer = useRef<{ id: number; x: number; y: number } | null>(null);
    const release = (event: ReactPointerEvent, swipe: boolean) => {
        const start = pointer.current;
        if (start === null || start.id !== event.pointerId) return;
        pointer.current = null;
        setHolding(false);
        const direction = swipe
            ? swipeDirection(event.clientX - start.x, event.clientY - start.y)
            : null;
        if (direction !== null && playableRef.current.length > 1) {
            go(direction === 'next' ? 1 : -1);
        }
    };

    const skip = (key: string) =>
        setFailed((previous) => ({
            playlist,
            keys: new Set([
                ...(previous.playlist === playlist ? previous.keys : []),
                key,
            ]),
        }));

    if (!current) {
        return suspended ? null : <IdleBrand branchName={branchName} />;
    }

    return (
        <div
            className={`relative flex min-h-0 flex-1 touch-none items-center justify-center overflow-hidden bg-black ${suspended ? 'hidden' : ''}`}
            onPointerDown={(event) => {
                pointer.current = {
                    id: event.pointerId,
                    x: event.clientX,
                    y: event.clientY,
                };
                setHolding(true);
            }}
            onPointerUp={(event) => release(event, true)}
            onPointerCancel={(event) => release(event, false)}
            onPointerLeave={(event) => release(event, false)}
            onContextMenu={(event) => event.preventDefault()}
        >
            {current.type === 'image' ? (
                <img
                    key={current.key}
                    src={current.url}
                    alt=""
                    decoding="async"
                    draggable={false}
                    onError={() => skip(current.key)}
                    className="animate-in fade-in absolute inset-0 size-full object-contain duration-700"
                />
            ) : (
                <video
                    ref={video}
                    key={current.key}
                    src={current.url}
                    autoPlay={!paused}
                    muted
                    playsInline
                    loop={single}
                    preload="auto"
                    onEnded={() => go(1)}
                    onError={() => skip(current.key)}
                    className="animate-in fade-in absolute inset-0 size-full object-contain duration-700"
                />
            )}
            {current.type === 'image' && advances && (
                <div
                    aria-hidden="true"
                    className="absolute inset-x-0 top-0 h-1 bg-white/10"
                >
                    <div
                        key={`${current.key}:${cycle}`}
                        className="h-full origin-left bg-[#f5c542]/85"
                        style={{
                            animation: `customer-screen-progress ${durationMs}ms linear forwards`,
                            animationPlayState: paused ? 'paused' : 'running',
                        }}
                    />
                </div>
            )}
            {playable.length > 1 && (
                <>
                    <SlideArrow direction={-1} onPress={() => go(-1)} />
                    <SlideArrow direction={1} onPress={() => go(1)} />
                </>
            )}
        </div>
    );
}

function SlideArrow({
    direction,
    onPress,
}: {
    direction: 1 | -1;
    onPress: () => void;
}) {
    const Icon = direction === 1 ? ChevronRight : ChevronLeft;

    return (
        <button
            type="button"
            aria-label={direction === 1 ? 'Next ad' : 'Previous ad'}
            onPointerDown={(event) => event.stopPropagation()}
            onPointerUp={(event) => event.stopPropagation()}
            onClick={onPress}
            className={`absolute top-1/2 z-10 flex size-12 -translate-y-1/2 items-center justify-center rounded-full bg-black/35 text-white/85 backdrop-blur-sm transition-opacity hover:bg-black/55 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none sm:size-14 ${direction === 1 ? 'right-3 sm:right-5' : 'left-3 sm:left-5'}`}
        >
            <Icon className="size-7" />
        </button>
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
