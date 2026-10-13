import { useEffect, useRef, useState } from 'react';
import type { FormEvent, MouseEvent } from 'react';
import { FB } from '@/lib/landing';
import {
    SHIPPY_GREETING,
    SHIPPY_MAX_MESSAGE,
    shippyAppend,
    shippyHistory,
    shippyQuestion,
    shippyReply,
    shippyUnavailable,
} from '@/lib/landing-shippy';
import type {
    ShippyAction,
    ShippyMessage,
    ShippyReply,
} from '@/lib/landing-shippy';
import { qrError, qrRequest } from '@/lib/qr-http';
import { store as askShippy } from '@/routes/shippy/messages';

/** The mascot, resized from `public/images/shippy.png` for the web. */
const MASCOT = '/images/landing-v130-6e8c6348.webp';
/** Remembers for this browser tab only that the invitation was seen; nothing of the conversation is ever stored. */
const TEASED = 'pongskilog.shippyTeaser';
const NARROW = '(max-width: 699px)';
/** A reply appears after at least this long, so the typing dots never just flash. */
const TYPING_MS = 500;

function seenTeaser(): boolean {
    try {
        return sessionStorage.getItem(TEASED) !== null;
    } catch {
        return false;
    }
}
function rememberTeaser(): void {
    try {
        sessionStorage.setItem(TEASED, '1');
    } catch {
        /* Private browsing may refuse storage; the invitation then simply returns on the next load. */
    }
}

const CLOSE = 'M18 6L6 18M6 6l12 12';

/**
 * Shippy (Landing V6): the chat at the bottom-right that answers questions about the menu, prices, hours and
 * location. A small launcher with an inviting bubble opens a floating panel on a desktop and a full-screen one on a
 * phone. Every answer comes from the server, which decides the text, the product cards and the buttons; this
 * component renders them as plain text and plain links and keeps the conversation in memory only.
 */
export function LandingShippy({
    presets,
    onSection,
}: {
    presets: string[];
    /** Scrolls the landing page to one of its sections (`board`, `reviews`). */
    onSection: (id: string) => void;
}) {
    const [open, setOpen] = useState(false);
    const [teaser, setTeaser] = useState(false);
    const [messages, setMessages] = useState<ShippyMessage[]>([]);
    const [input, setInput] = useState('');
    const [typing, setTyping] = useState(false);
    const panel = useRef<HTMLDivElement>(null);
    const log = useRef<HTMLDivElement>(null);
    const field = useRef<HTMLInputElement>(null);
    const launcher = useRef<HTMLButtonElement>(null);
    const alive = useRef(true);
    const returnFocus = useRef(false);

    useEffect(() => {
        alive.current = true;
        let timer: ReturnType<typeof setTimeout> | undefined;
        const invite = () => {
            /* On a phone the two invitations would overlap, so Shippy waits for the tap-to-love one to go. */
            if (
                window.matchMedia(NARROW).matches &&
                document.querySelector('.love-invite')
            ) {
                timer = setTimeout(invite, 3000);
                return;
            }
            setTeaser(true);
        };
        if (!seenTeaser()) timer = setTimeout(invite, 3200);
        return () => {
            alive.current = false;
            clearTimeout(timer);
        };
    }, []);

    useEffect(() => {
        if (!open) {
            if (returnFocus.current) launcher.current?.focus();
            returnFocus.current = false;
            return;
        }
        const element = panel.current;
        const narrow = window.matchMedia(NARROW);
        const root = document.documentElement;
        const scrolling = root.style.overflow;
        const fit = () => {
            if (!element) return;
            const viewport = window.visualViewport;
            /* Full screen on a phone, following the visible area so the on-screen keyboard never covers the input. */
            element.style.top = narrow.matches
                ? `${viewport?.offsetTop ?? 0}px`
                : '';
            element.style.height = narrow.matches
                ? `${viewport?.height ?? window.innerHeight}px`
                : '';
            root.style.overflow = narrow.matches ? 'hidden' : scrolling;
        };
        const onKey = (event: KeyboardEvent) => {
            /* Escape belongs to a dialog that is open over the chat (a review, the Privacy Notice) before the chat. */
            const dialog = document.activeElement?.closest('[role="dialog"]');
            if (event.key === 'Escape' && (!dialog || dialog === element)) {
                returnFocus.current = true;
                setOpen(false);
            }
        };
        fit();
        window.addEventListener('resize', fit);
        window.visualViewport?.addEventListener('resize', fit);
        window.visualViewport?.addEventListener('scroll', fit);
        document.addEventListener('keydown', onKey);
        if (!narrow.matches) field.current?.focus({ preventScroll: true });
        return () => {
            window.removeEventListener('resize', fit);
            window.visualViewport?.removeEventListener('resize', fit);
            window.visualViewport?.removeEventListener('scroll', fit);
            document.removeEventListener('keydown', onKey);
            root.style.overflow = scrolling;
        };
    }, [open]);

    useEffect(() => {
        const element = log.current;
        if (!open || !element) return;
        element.scrollTo?.({
            top: element.scrollHeight,
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                .matches
                ? 'auto'
                : 'smooth',
        });
    }, [open, messages, typing]);

    const show = () => {
        rememberTeaser();
        setTeaser(false);
        setOpen(true);
    };
    const hide = () => {
        returnFocus.current = true;
        setOpen(false);
    };
    /** A preset is the same question for everyone, so it travels without the conversation. */
    const ask = async (raw: string, preset = false) => {
        const question = shippyQuestion(raw);
        if (!question || typing) return;
        const history = preset ? [] : shippyHistory(messages);
        const asked = Date.now();
        setMessages((current) =>
            shippyAppend(current, { from: 'user', text: question }),
        );
        setInput('');
        setTyping(true);
        let reply: ShippyReply;
        try {
            const answer = await qrRequest<{ reply: unknown }>(askShippy(), {
                message: question,
                history,
            });
            reply = shippyReply(answer.reply) ?? shippyUnavailable(0, FB);
        } catch (failure) {
            reply = shippyUnavailable(qrError(failure).status, FB);
        }
        await new Promise((resolve) =>
            setTimeout(resolve, Math.max(0, TYPING_MS - (Date.now() - asked))),
        );
        if (!alive.current) return;
        setMessages((current) =>
            shippyAppend(current, { from: 'bot', ...reply }),
        );
        setTyping(false);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        void ask(input);
    };
    /** A button to a section of this page scrolls there; on a phone the full-screen chat closes first. */
    const follow = (action: ShippyAction) => (event: MouseEvent) => {
        if (action.external) return;
        event.preventDefault();
        if (window.matchMedia(NARROW).matches) setOpen(false);
        setTimeout(() => onSection(action.href.slice(1)), 40);
    };

    const conversation: ShippyMessage[] = [
        { from: 'bot', text: SHIPPY_GREETING, items: [], actions: [] },
        ...messages,
    ];
    const unsendable = typing || shippyQuestion(input) === '';

    if (!open)
        return (
            <div className="shippy">
                {teaser && (
                    <div className="shippy-teaser">
                        <button type="button" onClick={show}>
                            <b>Gutom ka na?</b> Tanong ka kay Shippy tungkol sa
                            menu at presyo.
                        </button>
                        <button
                            type="button"
                            aria-label="Dismiss"
                            onClick={() => {
                                rememberTeaser();
                                setTeaser(false);
                            }}
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
                                <path d={CLOSE} />
                            </svg>
                        </button>
                    </div>
                )}
                <button
                    ref={launcher}
                    type="button"
                    onClick={show}
                    aria-label="Chat with Shippy"
                    aria-haspopup="dialog"
                    title="Chat with Shippy"
                    className="shippy-launcher"
                >
                    <img
                        src={MASCOT}
                        alt=""
                        width="58"
                        height="58"
                        decoding="async"
                    />
                    <span aria-hidden="true" />
                </button>
            </div>
        );

    return (
        <div
            ref={panel}
            role="dialog"
            aria-label="Chat with Shippy"
            className="shippy-panel"
        >
            <div className="shippy-head">
                <span className="shippy-face">
                    <img src={MASCOT} alt="" width="44" height="44" />
                    <span aria-hidden="true" />
                </span>
                <div>
                    <strong>Shippy</strong>
                    <span>Menu, presyo, oras, mapa</span>
                </div>
                <button
                    type="button"
                    aria-label="Start over"
                    title="Start over"
                    onClick={() => {
                        setMessages([]);
                        setInput('');
                    }}
                    disabled={typing}
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
                        <path d="M3 12a9 9 0 1 0 3-6.7L3 8" />
                        <path d="M3 3v5h5" />
                    </svg>
                </button>
                <button
                    type="button"
                    aria-label="Close chat"
                    title="Close chat"
                    className="shippy-close"
                    onClick={hide}
                >
                    <svg
                        width="18"
                        height="18"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.2"
                        strokeLinecap="round"
                        aria-hidden="true"
                    >
                        <path d={CLOSE} />
                    </svg>
                </button>
            </div>
            <div
                ref={log}
                role="log"
                aria-live="polite"
                aria-label="Conversation with Shippy"
                className="shippy-log"
            >
                <div className="shippy-intro">
                    <img
                        src={MASCOT}
                        alt="Shippy, a small black dog with his tongue out"
                        width="92"
                        height="92"
                    />
                    <strong>Shippy</strong>
                    <p>
                        Tanong ka lang tungkol sa menu, presyo, oras at lokasyon
                        ng PONGSKILOG.
                    </p>
                    <small>
                        AI ang sumasagot mula sa public menu. Huwag mag-type ng
                        personal na impormasyon; hindi sine-save ang chat.
                    </small>
                </div>
                {conversation.map((message, index) =>
                    message.from === 'user' ? (
                        <div key={index} className="shippy-mine">
                            <p>{message.text}</p>
                        </div>
                    ) : (
                        <div key={index} className="shippy-theirs">
                            <img
                                src={MASCOT}
                                alt=""
                                width="30"
                                height="30"
                                style={{
                                    visibility:
                                        conversation[index + 1]?.from ===
                                            'bot' ||
                                        (index === conversation.length - 1 &&
                                            typing)
                                            ? 'hidden'
                                            : 'visible',
                                }}
                            />
                            <div>
                                <p>{message.text}</p>
                                {message.items.length > 0 && (
                                    <ul className="shippy-items">
                                        {message.items.map((item) => (
                                            <li key={item.name}>
                                                {item.img ? (
                                                    <img
                                                        src={item.img}
                                                        alt=""
                                                        width="40"
                                                        height="40"
                                                        loading="lazy"
                                                        decoding="async"
                                                    />
                                                ) : (
                                                    <i aria-hidden="true" />
                                                )}
                                                <span>{item.name}</span>
                                                <b>{item.price}</b>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {message.actions.length > 0 && (
                                    <div className="shippy-actions">
                                        {message.actions.map((action) => (
                                            <a
                                                key={action.key}
                                                href={action.href}
                                                target={
                                                    action.external
                                                        ? '_blank'
                                                        : undefined
                                                }
                                                rel="noopener noreferrer"
                                                onClick={follow(action)}
                                            >
                                                {action.label}
                                                <svg
                                                    width="14"
                                                    height="14"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="2.4"
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    aria-hidden="true"
                                                >
                                                    <path d="M5 12h14M13 6l6 6-6 6" />
                                                </svg>
                                            </a>
                                        ))}
                                    </div>
                                )}
                            </div>
                        </div>
                    ),
                )}
                {typing && (
                    <div className="shippy-theirs">
                        <img src={MASCOT} alt="" width="30" height="30" />
                        <p
                            role="status"
                            aria-label="Shippy is typing"
                            className="shippy-typing"
                        >
                            <i />
                            <i />
                            <i />
                        </p>
                    </div>
                )}
            </div>
            <div className="shippy-chips" aria-label="Suggested questions">
                {presets.map((preset) => (
                    <button
                        key={preset}
                        type="button"
                        disabled={typing}
                        onClick={() => void ask(preset, true)}
                    >
                        {preset}
                    </button>
                ))}
            </div>
            <form className="shippy-form" onSubmit={submit}>
                <label htmlFor="shippy-question" className="sr-only">
                    Message Shippy
                </label>
                <input
                    id="shippy-question"
                    ref={field}
                    type="text"
                    autoComplete="off"
                    enterKeyHint="send"
                    maxLength={SHIPPY_MAX_MESSAGE}
                    value={input}
                    onChange={(event) => setInput(event.target.value)}
                    placeholder="Magtanong kay Shippy"
                />
                <button type="submit" aria-label="Send" disabled={unsendable}>
                    <svg
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.4"
                        strokeLinecap="round"
                        strokeLinejoin="round"
                        aria-hidden="true"
                    >
                        <path d="M12 19V5M5 12l7-7 7 7" />
                    </svg>
                </button>
            </form>
        </div>
    );
}
