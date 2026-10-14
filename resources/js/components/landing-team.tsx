import { useEffect, useRef } from 'react';
import { advanceTeamScroll } from '@/lib/landing';
import type { TeamCard } from '@/lib/landing';

/** How long the rail rests after a visitor moved it, and after it returned to the first card. */
const RESUME_AFTER_MS = 2500;
const RESTART_AFTER_MS = 1800;

const prefersReducedMotion = () =>
    window.matchMedia('(prefers-reduced-motion: reduce)').matches;
/** The distance from one card to the next, including the gap between them. */
function cardStride(rail: HTMLElement): number {
    const cards = rail.querySelectorAll<HTMLElement>('[data-team]');
    return cards.length > 1 ? cards[1].offsetLeft - cards[0].offsetLeft : 300;
}
const glide = (rail: HTMLElement, left: number) =>
    rail.scrollTo({
        left,
        behavior: prefersReducedMotion() ? 'auto' : 'smooth',
    });

/**
 * Meet the Team (Landing V6): portrait cards that slide slowly on their own while the section is on screen. Hovering,
 * touching or keyboard-focusing the rail pauses it and it resumes afterward; a mouse drags it and a finger swipes it
 * natively. Reduced motion keeps it still and leaves the arrows, swipe and keyboard. The cards come from the server
 * (Website → Team), and the whole section is left out while every card is hidden.
 */
export function LandingTeam({ members }: { members: TeamCard[] }) {
    const rail = useRef<HTMLDivElement>(null);
    const bar = useRef<HTMLDivElement>(null);
    const pause = useRef({
        hovered: false,
        held: false,
        focused: false,
        until: 0,
    });
    const drag = useRef<{
        x: number;
        scroll: number;
        moved: boolean;
        last: number;
        speed: number;
    } | null>(null);
    const rest = () => {
        pause.current.until = performance.now() + RESUME_AFTER_MS;
    };
    const step = (direction: number) => {
        const element = rail.current;
        if (!element) return;
        rest();
        const maximum = element.scrollWidth - element.clientWidth;
        const next = element.scrollLeft + direction * cardStride(element);
        glide(element, next > maximum + 4 ? 0 : next < -4 ? maximum : next);
    };
    const endDrag = () => {
        const current = drag.current,
            element = rail.current;
        drag.current = null;
        if (!current?.moved || !element) return;
        element.style.cursor = '';
        rest();
        const stride = cardStride(element);
        glide(
            element,
            Math.max(
                0,
                Math.round((element.scrollLeft - current.speed * 6) / stride) *
                    stride,
            ),
        );
    };

    useEffect(() => {
        const element = rail.current;
        if (!element) return;
        const media = window.matchMedia('(prefers-reduced-motion: reduce)');
        let frame = 0,
            previous = 0,
            offset = element.scrollLeft,
            visible = false;
        const progress = () => {
            if (bar.current)
                bar.current.style.transform = `scaleX(${Math.min(1, (element.scrollLeft + element.clientWidth) / (element.scrollWidth || 1)).toFixed(4)})`;
        };
        const tick = (time: number) => {
            frame = requestAnimationFrame(tick);
            const elapsed = previous ? time - previous : 0;
            previous = time;
            const held = pause.current;
            const maximum = element.scrollWidth - element.clientWidth;
            /* A visitor's own scroll, drag or arrow step is the new starting point. */
            if (Math.abs(element.scrollLeft - offset) > 2)
                offset = element.scrollLeft;
            if (
                maximum <= 0 ||
                drag.current ||
                held.hovered ||
                held.held ||
                held.focused ||
                time < held.until
            )
                return;
            const next = advanceTeamScroll(offset, maximum, elapsed);
            offset = next.position;
            if (next.restart) {
                held.until = time + RESTART_AFTER_MS;
                element.scrollTo({ left: 0, behavior: 'smooth' });
            } else element.scrollLeft = offset;
        };
        /* The loop only runs while the rail is on screen, the tab is visible and motion is allowed. */
        const start = () => {
            cancelAnimationFrame(frame);
            previous = 0;
            if (visible && !media.matches && !document.hidden)
                frame = requestAnimationFrame(tick);
        };
        const observer = new IntersectionObserver((entries) => {
            visible = entries.some((entry) => entry.isIntersecting);
            start();
        });
        observer.observe(element);
        media.addEventListener('change', start);
        document.addEventListener('visibilitychange', start);
        element.addEventListener('scroll', progress, { passive: true });
        window.addEventListener('resize', progress);
        progress();
        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
            media.removeEventListener('change', start);
            document.removeEventListener('visibilitychange', start);
            element.removeEventListener('scroll', progress);
            window.removeEventListener('resize', progress);
        };
    }, []);

    if (!members.length) return null;
    return (
        <section id="team" aria-label="Team" className="team-section">
            <div data-reveal="" className="team-head">
                <div className="team-intro">
                    <div className="team-eyebrow">Ang team</div>
                    <h2>
                        Sila ang nasa
                        <br />
                        likod ng silog.
                    </h2>
                    <p>
                        Mula sa kusina hanggang sa page na ito, ito ang mga
                        taong nagpapatakbo ng PONGSKILOG.
                    </p>
                </div>
                <div className="team-nav">
                    {[-1, 1].map((direction) => (
                        <button
                            key={direction}
                            type="button"
                            onClick={() => step(direction)}
                            aria-label={
                                direction < 0
                                    ? 'Previous team member'
                                    : 'Next team member'
                            }
                        >
                            <svg
                                width="18"
                                height="18"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                                aria-hidden="true"
                            >
                                <path
                                    d={
                                        direction < 0
                                            ? 'M15 18l-6-6 6-6'
                                            : 'M9 18l6-6-6-6'
                                    }
                                />
                            </svg>
                        </button>
                    ))}
                </div>
            </div>
            <div
                ref={rail}
                data-reveal=""
                className="team-rail v2-rail"
                role="region"
                aria-label="Team members. Swipe or drag to see more."
                tabIndex={0}
                onPointerEnter={(event) => {
                    if (event.pointerType === 'mouse')
                        pause.current.hovered = true;
                }}
                onPointerLeave={(event) => {
                    if (event.pointerType === 'mouse') {
                        pause.current.hovered = false;
                        endDrag();
                    }
                }}
                onPointerDown={(event) => {
                    if (event.pointerType === 'mouse' && event.button === 0)
                        drag.current = {
                            x: event.clientX,
                            scroll: event.currentTarget.scrollLeft,
                            moved: false,
                            last: event.clientX,
                            speed: 0,
                        };
                }}
                onPointerMove={(event) => {
                    const current = drag.current;
                    if (!current) return;
                    const distance = event.clientX - current.x;
                    if (!current.moved) {
                        if (Math.abs(distance) < 6) return;
                        current.moved = true;
                        event.currentTarget.style.cursor = 'grabbing';
                        event.currentTarget.setPointerCapture(event.pointerId);
                    }
                    event.currentTarget.scrollLeft = current.scroll - distance;
                    current.speed = event.clientX - current.last;
                    current.last = event.clientX;
                }}
                onPointerUp={endDrag}
                onPointerCancel={endDrag}
                onTouchStart={() => {
                    pause.current.held = true;
                }}
                onTouchEnd={() => {
                    pause.current.held = false;
                    rest();
                }}
                onTouchCancel={() => {
                    pause.current.held = false;
                    rest();
                }}
                onFocus={(event) => {
                    /* Keyboard focus only: a mouse press or a tap also focuses the rail but must not hold it still. */
                    pause.current.focused =
                        event.currentTarget.matches(':focus-visible');
                }}
                onBlur={() => {
                    pause.current.focused = false;
                }}
                onKeyDown={(event) => {
                    if (
                        event.key === 'ArrowRight' ||
                        event.key === 'ArrowLeft'
                    ) {
                        event.preventDefault();
                        step(event.key === 'ArrowRight' ? 1 : -1);
                    }
                }}
            >
                {members.map((member) => (
                    <article key={member.id} data-team="" className="team-card">
                        {member.img ? (
                            <img
                                src={member.img}
                                alt={`${member.name ?? member.title}, ${member.title}`}
                                loading="lazy"
                                decoding="async"
                                draggable={false}
                                className="team-photo"
                            />
                        ) : (
                            <div className="team-photo team-photo-missing">
                                <svg
                                    width="44"
                                    height="44"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="1.5"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    aria-hidden="true"
                                >
                                    <circle cx="12" cy="8" r="4" />
                                    <path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1" />
                                </svg>
                                Photo to follow
                            </div>
                        )}
                        <div aria-hidden="true" className="team-shade" />
                        <span className="team-code">{member.code}</span>
                        <div className="team-meta">
                            <h3>{member.name ?? 'Name to follow'}</h3>
                            <p>{member.bio ?? 'Details to follow.'}</p>
                            <div className="team-title">{member.title}</div>
                        </div>
                    </article>
                ))}
            </div>
            <div aria-hidden="true" className="team-progress">
                <div>
                    <div ref={bar} />
                </div>
            </div>
        </section>
    );
}
