import { useEffect, useRef, useState } from 'react';
import { createClientUuid } from '@/lib/client-uuid';
import { createLoveQueue } from '@/lib/landing-love';
import { qrError, qrRequest } from '@/lib/qr-http';
import { store as storeLandingLove } from '@/routes/landing-loves';

const HEART =
    'M12 21s-7.5-4.6-9.6-9.2C.9 8.4 3 4.5 6.7 4.5c2.1 0 3.6 1.1 4.3 2.4h2c.7-1.3 2.2-2.4 4.3-2.4 3.7 0 5.8 3.9 4.3 7.3C19.5 16.4 12 21 12 21z';
const EASE = 'cubic-bezier(.2,.8,.2,1)';

/** One or two hearts float up from the chef and remove themselves; they live on <body> so nothing clips them. */
function floatHearts(from: HTMLElement): void {
    const box = from.getBoundingClientRect();
    const count = 1 + (Math.random() < 0.35 ? 1 : 0);
    for (let index = 0; index < count; index++) {
        const heart = document.createElement('span');
        const size = 16 + Math.random() * 14;
        const drift = (Math.random() - 0.3) * 80;
        const turn = (Math.random() - 0.5) * 50;
        heart.setAttribute('aria-hidden', 'true');
        heart.dataset.loveHeart = '';
        heart.style.cssText = `position:fixed;left:${box.left + box.width / 2 - size / 2}px;top:${box.top + box.height / 2 - size / 2}px;width:${size}px;height:${size}px;z-index:76;pointer-events:none`;
        heart.innerHTML = `<svg width="100%" height="100%" viewBox="0 0 24 24"><path d="${HEART}" fill="${Math.random() < 0.7 ? '#B0281F' : '#C89332'}"/></svg>`;
        document.body.appendChild(heart);
        heart.animate(
            [
                {
                    transform: 'translate(0,0) scale(.4) rotate(0deg)',
                    opacity: 0,
                },
                {
                    transform: `translate(${drift * 0.3}px,-30px) scale(1.1) rotate(${turn / 2}deg)`,
                    opacity: 1,
                    offset: 0.2,
                },
                {
                    transform: `translate(${drift}px,-${120 + Math.random() * 70}px) scale(.9) rotate(${turn}deg)`,
                    opacity: 0,
                },
            ],
            {
                duration: 900 + Math.random() * 300,
                delay: index * 70,
                easing: 'cubic-bezier(.2,.6,.3,1)',
                fill: 'both',
            },
        ).onfinish = () => heart.remove();
    }
}

/**
 * Tap-to-love (Landing V6): the chef at the bottom-left. A tap floats a heart and adds to one total every visitor
 * shares; the server gives the total with the page and with each saved batch of taps, so nothing polls. The
 * "Tap tap!" invitation returns on every visit until the chef is tapped or it is dismissed.
 */
export function LandingLove({ total }: { total: number }) {
    const [shown, setShown] = useState(total);
    const [invited, setInvited] = useState(false);
    const chef = useRef<HTMLButtonElement>(null);
    const count = useRef<HTMLSpanElement>(null);
    const queue = useRef<ReturnType<typeof createLoveQueue> | null>(null);

    useEffect(() => {
        const taps = createLoveQueue(
            total,
            (batch) =>
                qrRequest<{ total: number }>(storeLandingLove(), batch).catch(
                    (failure) => ({ failed: qrError(failure).status }),
                ),
            createClientUuid,
            setShown,
        );
        queue.current = taps;
        const invitation = setTimeout(() => setInvited(true), 1400);
        return () => {
            taps.dispose();
            clearTimeout(invitation);
            document
                .querySelectorAll('[data-love-heart]')
                .forEach((heart) => heart.remove());
        };
    }, [total]);

    const love = () => {
        setInvited(false);
        queue.current?.tap();
        navigator.vibrate?.(8);
        const button = chef.current;
        if (
            !button?.animate ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        )
            return;
        button.animate(
            [
                { transform: 'scale(1)' },
                { transform: 'scale(.86)' },
                { transform: 'scale(1.08)' },
                { transform: 'scale(1)' },
            ],
            { duration: 320, easing: EASE },
        );
        count.current?.animate(
            [
                { transform: 'scale(1)' },
                { transform: 'scale(1.14)' },
                { transform: 'scale(1)' },
            ],
            { duration: 260, easing: EASE },
        );
        floatHearts(button);
    };
    const label = shown.toLocaleString('en-US');

    return (
        <div className="love">
            {invited && (
                <div className="love-invite">
                    <button type="button" onClick={love}>
                        <b>Tap tap!</b> I-tap si chef para i-love ang
                        PONGSKILOG.
                    </button>
                    <button
                        type="button"
                        onClick={() => setInvited(false)}
                        aria-label="Dismiss"
                    >
                        <svg
                            width="14"
                            height="14"
                            viewBox="0 0 24 24"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth="2.4"
                            strokeLinecap="round"
                            aria-hidden="true"
                        >
                            <path d="M18 6L6 18M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            )}
            <div className="love-row">
                <button
                    ref={chef}
                    type="button"
                    onClick={love}
                    aria-label="Tap to love PONGSKILOG"
                    title="Tap to love PONGSKILOG"
                    className="love-chef"
                >
                    <img
                        src="/images/landing-v130-255a7f40.webp"
                        alt=""
                        width="60"
                        height="60"
                        draggable={false}
                        decoding="async"
                    />
                    <span aria-hidden="true">
                        <svg width="12" height="12" viewBox="0 0 24 24">
                            <path d={HEART} fill="#FFFFFF" />
                        </svg>
                    </span>
                </button>
                <span ref={count} aria-live="polite" className="love-count">
                    <svg
                        width="15"
                        height="15"
                        viewBox="0 0 24 24"
                        aria-hidden="true"
                    >
                        <path d={HEART} fill="#B0281F" />
                    </svg>
                    <span aria-hidden="true">{label}</span>
                    <span className="sr-only">{label} taps of love</span>
                </span>
            </div>
        </div>
    );
}
