import { Head, Link, usePage } from '@inertiajs/react';
import { Fragment, useEffect, useRef, useState } from 'react';
import type { MouseEvent } from 'react';
import { BEST, CAT_ORDER, COPY_ADDRESS, HOURS, MENU } from '@/lib/landing';
import { dashboard, login } from '@/routes';
import '../../css/landing.css';

export default function Welcome() {
    const { auth } = usePage().props;
    const [isMobile, setIsMobile] = useState(false);
    const [menuOpen, setMenuOpen] = useState(false);
    const [category, setCategory] = useState<keyof typeof MENU>('silog');
    const [toast, setToast] = useState<string | null>(null);
    const pageRef = useRef<HTMLDivElement>(null);
    const isDesktop = !isMobile;
    const bestsellers = BEST;
    const items = MENU[category].items;
    const catNote = MENU[category].note;
    const copyLabel = toast === 'Address copied' ? 'Copied' : 'Copy address';
    const cats = CAT_ORDER.map((id) => ({
        label: MENU[id].label,
        sel: category === id,
        off: category !== id,
        onClick: () => setCategory(id),
    }));
    const hours = HOURS.map((h) => ({
        ...h,
        fg:
            h.s === 1
                ? 'rgba(247,240,222,.82)'
                : h.s === 0.5
                  ? 'rgba(226,182,90,.78)'
                  : 'rgba(247,240,222,.36)',
    }));

    useEffect(() => {
        const media = window.matchMedia('(max-width: 1099px)');
        const resize = () => {
            setIsMobile(media.matches);
            if (!media.matches) setMenuOpen(false);
        };
        resize();
        media.addEventListener('change', resize);
        return () => media.removeEventListener('change', resize);
    }, []);

    useEffect(() => {
        if (!toast) return;
        const timer = window.setTimeout(() => setToast(null), 2600);
        return () => window.clearTimeout(timer);
    }, [toast]);

    useEffect(() => {
        if (!menuOpen) return;
        const previousFocus = document.activeElement as HTMLElement | null;
        const dialog =
            pageRef.current?.querySelector<HTMLElement>('[role="dialog"]');
        const controls =
            dialog?.querySelectorAll<HTMLElement>('a[href], button');
        controls?.[0]?.focus();
        const oldOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setMenuOpen(false);
            if (event.key !== 'Tab' || !controls?.length) return;
            const first = controls[0];
            const last = controls[controls.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => {
            window.removeEventListener('keydown', onKey);
            document.body.style.overflow = oldOverflow;
            previousFocus?.focus();
        };
    }, [menuOpen]);

    const scrollTo = (id: string, event: MouseEvent) => {
        event.preventDefault();
        setMenuOpen(false);
        const behavior = window.matchMedia('(prefers-reduced-motion: reduce)')
            .matches
            ? 'auto'
            : 'smooth';
        if (id === 'top') window.scrollTo({ top: 0, behavior });
        else
            document
                .getElementById(id)
                ?.scrollIntoView({ behavior, block: 'start' });
    };
    const goTop = (event: MouseEvent) => scrollTo('top', event);
    const goMenu = (event: MouseEvent) => scrollTo('menu', event);
    const goAbout = (event: MouseEvent) => scrollTo('about', event);
    const goLocation = (event: MouseEvent) => scrollTo('location', event);
    const toggleMenu = () => setMenuOpen((open) => !open);
    const closeMenu = () => setMenuOpen(false);
    const stop = (event: MouseEvent) => event.stopPropagation();
    const copyAddress = async () => {
        try {
            if (navigator.clipboard?.writeText)
                await navigator.clipboard.writeText(COPY_ADDRESS);
            else {
                const field = document.createElement('textarea');
                field.value = COPY_ADDRESS;
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                const copied = document.execCommand('copy');
                field.remove();
                if (!copied) throw new Error('Copy unavailable');
            }
            setToast('Address copied');
        } catch {
            setToast('Copy failed - select the address manually');
        }
    };

    return (
        <div ref={pageRef} className="pong-landing">
            <Head title="Filipino Silog & Comfort Food in Quezon City">
                <meta
                    name="description"
                    content="PONGSKILOG serves flavorful Filipino silog and comfort food in Quezon City. EST. 2022. Visit us, check the menu, and find us on the map."
                />
            </Head>
            <div
                style={{
                    background: '#0B0B0B',
                    color: '#F7F0DE',
                    fontFamily: 'Archivo,system-ui,sans-serif',
                    overflowX: 'clip',
                    position: 'relative',
                }}
            >
                <div
                    style={{
                        background: '#C89332',
                        color: '#0B0B0B',
                        display: 'flex',
                        justifyContent: 'center',
                        alignItems: 'center',
                        gap: '14px',
                        padding: '9px 20px',
                        fontSize: '11px',
                        fontWeight: '800',
                        letterSpacing: '.2em',
                        textTransform: 'uppercase',
                        textAlign: 'center',
                    }}
                >
                    <span style={{ opacity: '.5' }}>{'◆'}</span>
                    <span>{'Authentic Pinoy Comfort Food • Est. 2022'}</span>
                    <span style={{ opacity: '.5' }}>{'◆'}</span>
                </div>
                <header
                    style={{
                        position: 'sticky',
                        top: '0',
                        zIndex: '60',
                        background: 'rgba(11,11,11,.93)',
                        backdropFilter: 'blur(14px)',
                        WebkitBackdropFilter: 'blur(14px)',
                        transition:
                            'padding .22s cubic-bezier(.2,.8,.2,1),border-color .22s,box-shadow .22s',
                        borderBottom: '1px solid rgba(247,240,222,.08)',
                        padding: '14px 0',
                    }}
                    data-landing-header=""
                >
                    <nav
                        aria-label="Primary"
                        style={{
                            maxWidth: '1280px',
                            margin: '0 auto',
                            padding: '0 clamp(18px,4vw,44px)',
                            display: 'flex',
                            alignItems: 'center',
                            gap: '20px',
                        }}
                    >
                        <Link
                            href={auth.user ? dashboard() : login()}
                            className="landing-login"
                        >
                            {auth.user ? 'Open workspace' : 'Login'}
                        </Link>
                        <a
                            href="#top"
                            onClick={goTop}
                            aria-label="PONGSKILOG home"
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                flex: '0 0 auto',
                            }}
                        >
                            <img
                                src="/images/landing-logo.png"
                                alt="PONGSKILOG logo"
                                style={{
                                    height: '48px',
                                    width: 'auto',
                                    transition:
                                        'height .22s cubic-bezier(.2,.8,.2,1)',
                                }}
                            />
                        </a>
                        <div style={{ flex: '1' }}></div>
                        {isDesktop && (
                            <>
                                <div
                                    style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: 'clamp(14px,2vw,30px)',
                                    }}
                                >
                                    <a
                                        className="landing-interaction-1"
                                        href="#top"
                                        onClick={goTop}
                                        style={{
                                            color: '#F7F0DE',
                                            fontSize: '12.5px',
                                            fontWeight: '600',
                                            letterSpacing: '.16em',
                                            textTransform: 'uppercase',
                                            padding: '6px 0',
                                            borderBottom:
                                                '1px solid transparent',
                                            transition:
                                                'color .15s,border-color .15s',
                                        }}
                                    >
                                        {'Home'}
                                    </a>
                                    <a
                                        className="landing-interaction-2"
                                        href="#menu"
                                        onClick={goMenu}
                                        style={{
                                            color: '#F7F0DE',
                                            fontSize: '12.5px',
                                            fontWeight: '600',
                                            letterSpacing: '.16em',
                                            textTransform: 'uppercase',
                                            padding: '6px 0',
                                            borderBottom:
                                                '1px solid transparent',
                                            transition:
                                                'color .15s,border-color .15s',
                                        }}
                                    >
                                        {'Menu'}
                                    </a>
                                    <a
                                        className="landing-interaction-3"
                                        href="#about"
                                        onClick={goAbout}
                                        style={{
                                            color: '#F7F0DE',
                                            fontSize: '12.5px',
                                            fontWeight: '600',
                                            letterSpacing: '.16em',
                                            textTransform: 'uppercase',
                                            padding: '6px 0',
                                            borderBottom:
                                                '1px solid transparent',
                                            transition:
                                                'color .15s,border-color .15s',
                                        }}
                                    >
                                        {'About'}
                                    </a>
                                    <a
                                        className="landing-interaction-4"
                                        href="#location"
                                        onClick={goLocation}
                                        style={{
                                            color: '#F7F0DE',
                                            fontSize: '12.5px',
                                            fontWeight: '600',
                                            letterSpacing: '.16em',
                                            textTransform: 'uppercase',
                                            padding: '6px 0',
                                            borderBottom:
                                                '1px solid transparent',
                                            transition:
                                                'color .15s,border-color .15s',
                                        }}
                                    >
                                        {'Location'}
                                    </a>
                                    <a
                                        className="landing-interaction-5"
                                        href="https://www.facebook.com/people/Pongski-log/100094575655003/"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        style={{
                                            color: '#F7F0DE',
                                            fontSize: '12.5px',
                                            fontWeight: '600',
                                            letterSpacing: '.16em',
                                            textTransform: 'uppercase',
                                            padding: '6px 0',
                                            borderBottom:
                                                '1px solid transparent',
                                            transition:
                                                'color .15s,border-color .15s',
                                        }}
                                    >
                                        {'Facebook'}
                                    </a>
                                    <a
                                        className="landing-interaction-6"
                                        href="https://www.facebook.com/people/Pongski-log/100094575655003/"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            background: '#C89332',
                                            color: '#0B0B0B',
                                            border: '1px solid #C89332',
                                            borderRadius: '2px',
                                            padding: '12px 22px',
                                            fontSize: '12px',
                                            fontWeight: '800',
                                            letterSpacing: '.16em',
                                            textTransform: 'uppercase',
                                            transition:
                                                'transform .18s cubic-bezier(.2,.8,.2,1),background .18s,box-shadow .18s',
                                        }}
                                    >
                                        {'Order / Inquire'}
                                    </a>
                                </div>
                            </>
                        )}
                        {isMobile && (
                            <>
                                <div
                                    style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: '10px',
                                    }}
                                >
                                    <a
                                        className="landing-mobile-order"
                                        href="https://www.facebook.com/people/Pongski-log/100094575655003/"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            background: '#C89332',
                                            color: '#0B0B0B',
                                            borderRadius: '2px',
                                            padding: '0 16px',
                                            height: '44px',
                                            fontSize: '11px',
                                            fontWeight: '800',
                                            letterSpacing: '.14em',
                                            textTransform: 'uppercase',
                                        }}
                                    >
                                        {'Order'}
                                    </a>
                                    <button
                                        type="button"
                                        onClick={toggleMenu}
                                        aria-label="Open navigation menu"
                                        aria-expanded={menuOpen}
                                        style={{
                                            width: '44px',
                                            height: '44px',
                                            display: 'flex',
                                            flexDirection: 'column',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            gap: '5px',
                                            background: 'transparent',
                                            border: '1px solid rgba(247,240,222,.22)',
                                            borderRadius: '2px',
                                            cursor: 'pointer',
                                        }}
                                    >
                                        <span
                                            style={{
                                                display: 'block',
                                                width: '20px',
                                                height: '1.5px',
                                                background: '#F7F0DE',
                                            }}
                                        ></span>
                                        <span
                                            style={{
                                                display: 'block',
                                                width: '20px',
                                                height: '1.5px',
                                                background: '#F7F0DE',
                                            }}
                                        ></span>
                                        <span
                                            style={{
                                                display: 'block',
                                                width: '20px',
                                                height: '1.5px',
                                                background: '#F7F0DE',
                                            }}
                                        ></span>
                                    </button>
                                </div>
                            </>
                        )}
                    </nav>
                </header>
                {menuOpen && (
                    <>
                        <div
                            style={{
                                position: 'fixed',
                                inset: '0',
                                zIndex: '70',
                                background: 'rgba(11,11,11,.66)',
                                animation: 'psFade .18s ease',
                            }}
                            onClick={closeMenu}
                        >
                            <div
                                role="dialog"
                                aria-modal="true"
                                aria-label="Navigation"
                                onClick={stop}
                                style={{
                                    position: 'absolute',
                                    top: '0',
                                    right: '0',
                                    width: 'min(320px,88vw)',
                                    height: '100%',
                                    background: '#0B0B0B',
                                    borderLeft:
                                        '1px solid rgba(200,147,50,.32)',
                                    padding: '22px',
                                    display: 'flex',
                                    flexDirection: 'column',
                                    animation:
                                        'psSlide .26s cubic-bezier(.2,.8,.2,1)',
                                    overflowY: 'auto',
                                }}
                            >
                                <div
                                    style={{
                                        display: 'flex',
                                        justifyContent: 'space-between',
                                        alignItems: 'center',
                                        marginBottom: '16px',
                                    }}
                                >
                                    <img
                                        src="/images/landing-logo.png"
                                        alt=""
                                        style={{
                                            height: '34px',
                                            width: 'auto',
                                        }}
                                    />
                                    <button
                                        type="button"
                                        onClick={closeMenu}
                                        aria-label="Close navigation"
                                        style={{
                                            width: '44px',
                                            height: '44px',
                                            background: 'transparent',
                                            border: '1px solid rgba(247,240,222,.2)',
                                            borderRadius: '2px',
                                            color: '#F7F0DE',
                                            fontSize: '17px',
                                            cursor: 'pointer',
                                        }}
                                    >
                                        {'✕'}
                                    </button>
                                </div>
                                <a
                                    href="#top"
                                    onClick={goTop}
                                    style={{
                                        color: '#F7F0DE',
                                        padding: '16px 0',
                                        borderBottom:
                                            '1px solid rgba(247,240,222,.09)',
                                        fontSize: '15px',
                                        fontWeight: '700',
                                        letterSpacing: '.13em',
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    {'Home'}
                                </a>
                                <a
                                    href="#menu"
                                    onClick={goMenu}
                                    style={{
                                        color: '#F7F0DE',
                                        padding: '16px 0',
                                        borderBottom:
                                            '1px solid rgba(247,240,222,.09)',
                                        fontSize: '15px',
                                        fontWeight: '700',
                                        letterSpacing: '.13em',
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    {'Menu'}
                                </a>
                                <a
                                    href="#about"
                                    onClick={goAbout}
                                    style={{
                                        color: '#F7F0DE',
                                        padding: '16px 0',
                                        borderBottom:
                                            '1px solid rgba(247,240,222,.09)',
                                        fontSize: '15px',
                                        fontWeight: '700',
                                        letterSpacing: '.13em',
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    {'About'}
                                </a>
                                <a
                                    href="#location"
                                    onClick={goLocation}
                                    style={{
                                        color: '#F7F0DE',
                                        padding: '16px 0',
                                        borderBottom:
                                            '1px solid rgba(247,240,222,.09)',
                                        fontSize: '15px',
                                        fontWeight: '700',
                                        letterSpacing: '.13em',
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    {'Location'}
                                </a>
                                <a
                                    href="https://www.facebook.com/people/Pongski-log/100094575655003/"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    style={{
                                        color: '#F7F0DE',
                                        padding: '16px 0',
                                        borderBottom:
                                            '1px solid rgba(247,240,222,.09)',
                                        fontSize: '15px',
                                        fontWeight: '700',
                                        letterSpacing: '.13em',
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    {'Facebook'}
                                </a>
                                <a
                                    href="https://www.facebook.com/people/Pongski-log/100094575655003/"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    style={{
                                        marginTop: '22px',
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        background: '#C89332',
                                        color: '#0B0B0B',
                                        borderRadius: '2px',
                                        height: '52px',
                                        fontSize: '13px',
                                        fontWeight: '800',
                                        letterSpacing: '.16em',
                                        textTransform: 'uppercase',
                                    }}
                                >
                                    {'Order / Inquire'}
                                </a>
                                <p
                                    style={{
                                        margin: '22px 0 0',
                                        fontSize: '12px',
                                        lineHeight: '1.75',
                                        color: 'rgba(247,240,222,.5)',
                                    }}
                                >
                                    {'PONGSKILOG Adarna Street'}
                                    <br />
                                    {'Quezon City, Metro Manila'}
                                    <br />
                                    <span style={{ color: '#C89332' }}>
                                        {
                                            'Mon, Tue, Thu–Sat · 6:00 PM – 12:00 AM'
                                        }
                                    </span>
                                </p>
                            </div>
                        </div>
                    </>
                )}
                <main id="top">
                    <section
                        style={{
                            position: 'relative',
                            borderBottom: '1px solid rgba(200,147,50,.24)',
                            background:
                                'radial-gradient(115% 85% at 10% 0%,#1B1611 0%,#0B0B0B 60%)',
                        }}
                    >
                        <div
                            style={{
                                maxWidth: '1280px',
                                margin: '0 auto',
                                padding:
                                    'clamp(40px,6vw,88px) clamp(18px,4vw,44px) clamp(46px,6.5vw,96px)',
                                display: 'grid',
                                gridTemplateColumns:
                                    'repeat(auto-fit,minmax(min(100%,320px),1fr))',
                                gap: 'clamp(30px,4.5vw,64px)',
                                alignItems: 'center',
                            }}
                        >
                            <div data-reveal="" style={{ minWidth: '0' }}>
                                <div
                                    style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: '12px',
                                        marginBottom: '22px',
                                    }}
                                >
                                    <span
                                        style={{
                                            width: '34px',
                                            height: '1px',
                                            background: '#C89332',
                                        }}
                                    ></span>
                                    <span
                                        style={{
                                            fontSize: '11px',
                                            fontWeight: '800',
                                            letterSpacing: '.26em',
                                            textTransform: 'uppercase',
                                            color: '#C89332',
                                        }}
                                    >
                                        {'Est. 2022 · Quezon City'}
                                    </span>
                                </div>
                                <h1
                                    style={{
                                        margin: '0',
                                        fontSize: 'clamp(42px,7vw,84px)',
                                        lineHeight: '.94',
                                        fontWeight: '800',
                                        fontStretch: '86%',
                                        letterSpacing: '-.025em',
                                        textTransform: 'uppercase',
                                        color: '#F7F0DE',
                                        textWrap: 'balance',
                                    }}
                                >
                                    {'Sarap na'}
                                    <br />
                                    <span style={{ color: '#E2B65A' }}>
                                        {'babalik-balikan.'}
                                    </span>
                                </h1>
                                <p
                                    style={{
                                        margin: '26px 0 0',
                                        maxWidth: '46ch',
                                        fontSize: 'clamp(15px,1.5vw,18px)',
                                        lineHeight: '1.65',
                                        color: 'rgba(247,240,222,.74)',
                                        textWrap: 'pretty',
                                    }}
                                >
                                    {
                                        'Classic Pinoy comfort food, generous servings, and flavors made for every craving.'
                                    }
                                </p>
                                <div
                                    style={{
                                        display: 'flex',
                                        flexWrap: 'wrap',
                                        gap: '12px',
                                        marginTop: '34px',
                                    }}
                                >
                                    <button
                                        className="landing-interaction-7"
                                        type="button"
                                        onClick={goMenu}
                                        style={{
                                            background: '#C89332',
                                            color: '#0B0B0B',
                                            border: '1px solid #C89332',
                                            borderRadius: '2px',
                                            minHeight: '52px',
                                            padding: '0 30px',
                                            fontSize: '12.5px',
                                            fontWeight: '800',
                                            letterSpacing: '.17em',
                                            textTransform: 'uppercase',
                                            cursor: 'pointer',
                                            transition:
                                                'transform .18s cubic-bezier(.2,.8,.2,1),background .18s,box-shadow .18s',
                                        }}
                                    >
                                        {'View Menu'}
                                    </button>
                                    <a
                                        className="landing-interaction-8"
                                        href="https://www.google.com/maps/search/?api=1&query=PONGSKILOG%20Adarna%20Street%20Quezon%20City"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            background: 'transparent',
                                            color: '#F7F0DE',
                                            border: '1px solid rgba(247,240,222,.34)',
                                            borderRadius: '2px',
                                            minHeight: '52px',
                                            padding: '0 30px',
                                            fontSize: '12.5px',
                                            fontWeight: '800',
                                            letterSpacing: '.17em',
                                            textTransform: 'uppercase',
                                            transition:
                                                'border-color .18s,background .18s',
                                        }}
                                    >
                                        {'Find Us'}
                                    </a>
                                </div>
                                <div
                                    style={{
                                        display: 'flex',
                                        flexWrap: 'wrap',
                                        gap: '10px 26px',
                                        marginTop: '38px',
                                        paddingTop: '24px',
                                        borderTop:
                                            '1px solid rgba(247,240,222,.1)',
                                    }}
                                >
                                    <span
                                        style={{
                                            fontSize: '11.5px',
                                            fontWeight: '700',
                                            letterSpacing: '.18em',
                                            textTransform: 'uppercase',
                                            color: 'rgba(247,240,222,.58)',
                                        }}
                                    >
                                        {'Dine-in'}
                                    </span>
                                    <span
                                        style={{
                                            fontSize: '11.5px',
                                            fontWeight: '700',
                                            letterSpacing: '.18em',
                                            textTransform: 'uppercase',
                                            color: 'rgba(247,240,222,.58)',
                                        }}
                                    >
                                        {'Takeout'}
                                    </span>
                                    <span
                                        style={{
                                            fontSize: '11.5px',
                                            fontWeight: '700',
                                            letterSpacing: '.18em',
                                            textTransform: 'uppercase',
                                            color: 'rgba(247,240,222,.58)',
                                        }}
                                    >
                                        {'Open 6:00 PM – 12:00 AM'}
                                    </span>
                                </div>
                            </div>
                            <div
                                data-reveal=""
                                style={{ position: 'relative', minWidth: '0' }}
                            >
                                <div
                                    style={{
                                        position: 'absolute',
                                        inset: '-16px -16px 20px 20px',
                                        border: '1px solid rgba(200,147,50,.4)',
                                        borderRadius: '3px',
                                        pointerEvents: 'none',
                                    }}
                                ></div>
                                <img
                                    src="/images/landing-keyart.png"
                                    alt="PONGSKILOG chef tossing garlic rice in a wok outside the store"
                                    style={{
                                        position: 'relative',
                                        width: '100%',
                                        height: 'auto',
                                        borderRadius: '3px',
                                        display: 'block',
                                    }}
                                />
                            </div>
                        </div>
                    </section>
                    <section
                        style={{
                            background: '#0B0B0B',
                            borderBottom: '1px solid rgba(247,240,222,.08)',
                        }}
                    >
                        <div
                            style={{
                                maxWidth: '1080px',
                                margin: '0 auto',
                                padding:
                                    'clamp(52px,7vw,100px) clamp(18px,4vw,44px)',
                                textAlign: 'center',
                            }}
                            data-reveal=""
                        >
                            <div
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    gap: '14px',
                                    marginBottom: '26px',
                                }}
                            >
                                <span
                                    style={{
                                        width: 'clamp(30px,8vw,80px)',
                                        height: '1px',
                                        background: 'rgba(200,147,50,.5)',
                                    }}
                                ></span>
                                <span
                                    style={{
                                        width: '6px',
                                        height: '6px',
                                        background: '#C89332',
                                        transform: 'rotate(45deg)',
                                    }}
                                ></span>
                                <span
                                    style={{
                                        width: 'clamp(30px,8vw,80px)',
                                        height: '1px',
                                        background: 'rgba(200,147,50,.5)',
                                    }}
                                ></span>
                            </div>
                            <h2
                                style={{
                                    margin: '0',
                                    fontSize: 'clamp(28px,4.3vw,54px)',
                                    lineHeight: '1.06',
                                    fontWeight: '800',
                                    fontStretch: '88%',
                                    letterSpacing: '-.02em',
                                    textTransform: 'uppercase',
                                    color: '#F7F0DE',
                                    textWrap: 'balance',
                                }}
                            >
                                {'Good food. Good value.'}
                                <br />
                                {'Good vibes.'}
                            </h2>
                            <p
                                style={{
                                    margin: '28px auto 0',
                                    maxWidth: '62ch',
                                    fontSize: 'clamp(15px,1.5vw,17.5px)',
                                    lineHeight: '1.75',
                                    color: 'rgba(247,240,222,.72)',
                                    textWrap: 'pretty',
                                }}
                            >
                                {
                                    'PONGSKILOG serves Filipino comfort food built around generous, flavorful meals that feel familiar and satisfying. Silog plates cooked to order, pares and short orders through the night, and the kind of cooking you order again without thinking about it.'
                                }
                            </p>
                        </div>
                    </section>
                    <section
                        id="bestsellers"
                        style={{
                            background: '#141414',
                            borderBottom: '1px solid rgba(247,240,222,.08)',
                        }}
                    >
                        <div
                            style={{
                                maxWidth: '1280px',
                                margin: '0 auto',
                                padding:
                                    'clamp(52px,7vw,100px) clamp(18px,4vw,44px)',
                            }}
                        >
                            <div
                                style={{
                                    display: 'flex',
                                    flexWrap: 'wrap',
                                    alignItems: 'flex-end',
                                    justifyContent: 'space-between',
                                    gap: '20px',
                                    marginBottom: 'clamp(28px,3.6vw,46px)',
                                }}
                                data-reveal=""
                            >
                                <div>
                                    <div
                                        style={{
                                            fontSize: '11px',
                                            fontWeight: '800',
                                            letterSpacing: '.26em',
                                            textTransform: 'uppercase',
                                            color: '#C89332',
                                            marginBottom: '14px',
                                        }}
                                    >
                                        {'The regulars order these'}
                                    </div>
                                    <h2
                                        style={{
                                            margin: '0',
                                            fontSize: 'clamp(30px,4.3vw,52px)',
                                            lineHeight: '1.02',
                                            fontWeight: '800',
                                            fontStretch: '88%',
                                            letterSpacing: '-.02em',
                                            textTransform: 'uppercase',
                                            color: '#F7F0DE',
                                        }}
                                    >
                                        {'Best sellers'}
                                    </h2>
                                </div>
                                <button
                                    className="landing-interaction-9"
                                    type="button"
                                    onClick={goMenu}
                                    style={{
                                        background: 'transparent',
                                        color: '#E2B65A',
                                        border: '1px solid rgba(200,147,50,.45)',
                                        borderRadius: '2px',
                                        minHeight: '48px',
                                        padding: '0 24px',
                                        fontSize: '11.5px',
                                        fontWeight: '800',
                                        letterSpacing: '.17em',
                                        textTransform: 'uppercase',
                                        cursor: 'pointer',
                                        transition:
                                            'background .18s,color .18s',
                                    }}
                                >
                                    {'See full menu'}
                                </button>
                            </div>
                            <div
                                style={{
                                    display: 'grid',
                                    gridTemplateColumns:
                                        'repeat(auto-fill,minmax(min(100%,250px),1fr))',
                                    gap: 'clamp(16px,2vw,24px)',
                                }}
                            >
                                {bestsellers.map((b) => (
                                    <Fragment key={b.n}>
                                        <article
                                            className="landing-interaction-10"
                                            style={{
                                                background: '#0B0B0B',
                                                border: '1px solid rgba(247,240,222,.1)',
                                                borderRadius: '3px',
                                                overflow: 'hidden',
                                                display: 'flex',
                                                flexDirection: 'column',
                                                transition:
                                                    'border-color .22s,transform .22s cubic-bezier(.2,.8,.2,1),box-shadow .22s',
                                            }}
                                        >
                                            <div
                                                style={{
                                                    aspectRatio: '16/9',
                                                    background: '#111',
                                                    overflow: 'hidden',
                                                }}
                                            >
                                                <img
                                                    src={b.img}
                                                    alt={b.alt}
                                                    loading="lazy"
                                                    width="1000"
                                                    height="562"
                                                    style={{
                                                        width: '100%',
                                                        height: '100%',
                                                        objectFit: 'cover',
                                                        display: 'block',
                                                    }}
                                                />
                                            </div>
                                            <div
                                                style={{
                                                    padding: '18px 20px 20px',
                                                    display: 'flex',
                                                    flexDirection: 'column',
                                                    gap: '8px',
                                                    flex: '1',
                                                }}
                                            >
                                                <div
                                                    style={{
                                                        display: 'flex',
                                                        alignItems: 'baseline',
                                                        justifyContent:
                                                            'space-between',
                                                        gap: '12px',
                                                    }}
                                                >
                                                    <h3
                                                        style={{
                                                            margin: '0',
                                                            fontSize: '18px',
                                                            fontWeight: '800',
                                                            fontStretch: '92%',
                                                            letterSpacing:
                                                                '-.01em',
                                                            textTransform:
                                                                'uppercase',
                                                            color: '#F7F0DE',
                                                        }}
                                                    >
                                                        {b.n}
                                                    </h3>
                                                    <span
                                                        style={{
                                                            fontSize: '17px',
                                                            fontWeight: '800',
                                                            color: '#E2B65A',
                                                            fontVariantNumeric:
                                                                'tabular-nums',
                                                            flex: '0 0 auto',
                                                        }}
                                                    >
                                                        {b.p}
                                                    </span>
                                                </div>
                                                <p
                                                    style={{
                                                        margin: '0',
                                                        fontSize: '13.5px',
                                                        lineHeight: '1.6',
                                                        color: 'rgba(247,240,222,.64)',
                                                        flex: '1',
                                                    }}
                                                >
                                                    {b.d}
                                                </p>
                                                <a
                                                    className="landing-interaction-11"
                                                    href="https://www.facebook.com/people/Pongski-log/100094575655003/"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    style={{
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        justifyContent:
                                                            'center',
                                                        marginTop: '8px',
                                                        border: '1px solid rgba(247,240,222,.22)',
                                                        borderRadius: '2px',
                                                        color: '#F7F0DE',
                                                        height: '42px',
                                                        fontSize: '10.5px',
                                                        fontWeight: '800',
                                                        letterSpacing: '.14em',
                                                        textTransform:
                                                            'uppercase',
                                                        transition:
                                                            'border-color .18s,background .18s',
                                                    }}
                                                >
                                                    {'Inquire on Facebook'}
                                                </a>
                                            </div>
                                        </article>
                                    </Fragment>
                                ))}
                            </div>
                            <p
                                style={{
                                    margin: 'clamp(22px,3vw,32px) 0 0',
                                    fontSize: '13px',
                                    lineHeight: '1.7',
                                    color: 'rgba(247,240,222,.5)',
                                }}
                            >
                                {
                                    'Prices may vary. Posted rates are the in-store list as of the current menu board.'
                                }
                            </p>
                        </div>
                    </section>
                    <section
                        id="menu"
                        style={{
                            background: '#0B0B0B',
                            borderBottom: '1px solid rgba(247,240,222,.08)',
                            scrollMarginTop: '92px',
                        }}
                    >
                        <div
                            style={{
                                maxWidth: '1280px',
                                margin: '0 auto',
                                padding:
                                    'clamp(52px,7vw,100px) clamp(18px,4vw,44px)',
                            }}
                        >
                            <div
                                style={{ marginBottom: 'clamp(24px,3vw,38px)' }}
                                data-reveal=""
                            >
                                <div
                                    style={{
                                        fontSize: '11px',
                                        fontWeight: '800',
                                        letterSpacing: '.26em',
                                        textTransform: 'uppercase',
                                        color: '#C89332',
                                        marginBottom: '14px',
                                    }}
                                >
                                    {'The full board'}
                                </div>
                                <h2
                                    style={{
                                        margin: '0',
                                        fontSize: 'clamp(30px,4.3vw,52px)',
                                        lineHeight: '1.02',
                                        fontWeight: '800',
                                        fontStretch: '88%',
                                        letterSpacing: '-.02em',
                                        textTransform: 'uppercase',
                                        color: '#F7F0DE',
                                    }}
                                >
                                    {'Menu'}
                                </h2>
                            </div>
                            <div
                                role="tablist"
                                aria-label="Menu categories"
                                style={{
                                    display: 'flex',
                                    flexWrap: 'wrap',
                                    gap: '8px',
                                    paddingBottom: 'clamp(22px,3vw,32px)',
                                    borderBottom:
                                        '1px solid rgba(247,240,222,.1)',
                                }}
                            >
                                {cats.map((c) => (
                                    <Fragment key={c.label}>
                                        {c.sel && (
                                            <>
                                                <button
                                                    type="button"
                                                    role="tab"
                                                    aria-selected="true"
                                                    onClick={c.onClick}
                                                    style={{
                                                        minHeight: '46px',
                                                        padding: '0 20px',
                                                        borderRadius: '2px',
                                                        fontSize: '11.5px',
                                                        fontWeight: '800',
                                                        letterSpacing: '.16em',
                                                        textTransform:
                                                            'uppercase',
                                                        cursor: 'pointer',
                                                        transition:
                                                            'background .18s,color .18s,border-color .18s',
                                                        border: '1px solid #C89332',
                                                        background: '#C89332',
                                                        color: '#0B0B0B',
                                                    }}
                                                >
                                                    {c.label}
                                                </button>
                                            </>
                                        )}
                                        {c.off && (
                                            <>
                                                <button
                                                    className="landing-interaction-12"
                                                    type="button"
                                                    role="tab"
                                                    aria-selected="false"
                                                    onClick={c.onClick}
                                                    style={{
                                                        minHeight: '46px',
                                                        padding: '0 20px',
                                                        borderRadius: '2px',
                                                        fontSize: '11.5px',
                                                        fontWeight: '800',
                                                        letterSpacing: '.16em',
                                                        textTransform:
                                                            'uppercase',
                                                        cursor: 'pointer',
                                                        transition:
                                                            'background .18s,color .18s,border-color .18s',
                                                        border: '1px solid rgba(247,240,222,.2)',
                                                        background:
                                                            'transparent',
                                                        color: 'rgba(247,240,222,.78)',
                                                    }}
                                                >
                                                    {c.label}
                                                </button>
                                            </>
                                        )}
                                    </Fragment>
                                ))}
                            </div>
                            <p
                                style={{
                                    margin: '18px 0 0',
                                    fontSize: '12.5px',
                                    lineHeight: '1.7',
                                    color: 'rgba(247,240,222,.5)',
                                }}
                            >
                                {catNote}
                            </p>
                            <div
                                role="tabpanel"
                                aria-live="polite"
                                style={{
                                    display: 'grid',
                                    gridTemplateColumns:
                                        'repeat(auto-fill,minmax(min(100%,320px),1fr))',
                                    gap: '0 clamp(24px,4vw,56px)',
                                    marginTop: 'clamp(12px,2vw,20px)',
                                }}
                            >
                                {items.map((it) => (
                                    <Fragment key={it.n}>
                                        <div
                                            style={{
                                                display: 'flex',
                                                alignItems: 'baseline',
                                                gap: '14px',
                                                padding: '15px 0',
                                                borderBottom:
                                                    '1px solid rgba(247,240,222,.08)',
                                            }}
                                        >
                                            <span
                                                style={{
                                                    fontSize: '15.5px',
                                                    fontWeight: '700',
                                                    fontStretch: '96%',
                                                    color: '#F7F0DE',
                                                    flex: '0 1 auto',
                                                }}
                                            >
                                                {it.n}
                                            </span>
                                            <span
                                                style={{
                                                    flex: '1',
                                                    minWidth: '14px',
                                                    borderBottom:
                                                        '1px dotted rgba(247,240,222,.22)',
                                                    transform:
                                                        'translateY(-4px)',
                                                }}
                                            ></span>
                                            <span
                                                style={{
                                                    fontSize: '15.5px',
                                                    fontWeight: '800',
                                                    color: '#E2B65A',
                                                    flex: '0 0 auto',
                                                    fontVariantNumeric:
                                                        'tabular-nums',
                                                }}
                                            >
                                                {it.p}
                                            </span>
                                        </div>
                                    </Fragment>
                                ))}
                            </div>
                            <p
                                style={{
                                    margin: 'clamp(22px,3vw,32px) 0 0',
                                    fontSize: '13px',
                                    lineHeight: '1.7',
                                    color: 'rgba(247,240,222,.5)',
                                }}
                            >
                                {
                                    "Prices may vary and are subject to change without notice. Message us on Facebook for today's availability."
                                }
                            </p>
                        </div>
                    </section>
                    <section
                        id="about"
                        style={{
                            background: '#141414',
                            borderBottom: '1px solid rgba(247,240,222,.08)',
                            scrollMarginTop: '92px',
                        }}
                    >
                        <div
                            style={{
                                maxWidth: '1280px',
                                margin: '0 auto',
                                padding:
                                    'clamp(52px,7vw,100px) clamp(18px,4vw,44px)',
                                display: 'grid',
                                gridTemplateColumns:
                                    'repeat(auto-fit,minmax(min(100%,300px),1fr))',
                                gap: 'clamp(30px,4.5vw,64px)',
                                alignItems: 'center',
                            }}
                        >
                            <div data-reveal="" style={{ minWidth: '0' }}>
                                <div
                                    style={{
                                        fontSize: '11px',
                                        fontWeight: '800',
                                        letterSpacing: '.26em',
                                        textTransform: 'uppercase',
                                        color: '#C89332',
                                        marginBottom: '16px',
                                    }}
                                >
                                    {'Our story'}
                                </div>
                                <h2
                                    style={{
                                        margin: '0',
                                        fontSize: 'clamp(30px,4.3vw,52px)',
                                        lineHeight: '1.02',
                                        fontWeight: '800',
                                        fontStretch: '88%',
                                        letterSpacing: '-.02em',
                                        textTransform: 'uppercase',
                                        color: '#F7F0DE',
                                        textWrap: 'balance',
                                    }}
                                >
                                    {'Built on Filipino comfort.'}
                                </h2>
                                <p
                                    style={{
                                        margin: '26px 0 0',
                                        fontSize: 'clamp(15px,1.4vw,17px)',
                                        lineHeight: '1.75',
                                        color: 'rgba(247,240,222,.72)',
                                        maxWidth: '56ch',
                                        textWrap: 'pretty',
                                    }}
                                >
                                    {
                                        'PONGSKILOG was established in 2022 with a simple idea: serve familiar Filipino food that is satisfying, flavorful, and sulit.'
                                    }
                                </p>
                                <p
                                    style={{
                                        margin: '18px 0 0',
                                        fontSize: 'clamp(15px,1.4vw,17px)',
                                        lineHeight: '1.75',
                                        color: 'rgba(247,240,222,.72)',
                                        maxWidth: '56ch',
                                        textWrap: 'pretty',
                                    }}
                                >
                                    {
                                        'No reinvention, no shortcuts. Silog plates, pares and short orders cooked the way people on Adarna Street already know and want them, at a price that makes coming back easy.'
                                    }
                                </p>
                                <div
                                    style={{
                                        display: 'flex',
                                        flexWrap: 'wrap',
                                        gap: '0 40px',
                                        marginTop: '36px',
                                        paddingTop: '26px',
                                        borderTop:
                                            '1px solid rgba(200,147,50,.3)',
                                    }}
                                >
                                    <div style={{ padding: '6px 0' }}>
                                        <div
                                            style={{
                                                fontSize:
                                                    'clamp(26px,3vw,34px)',
                                                fontWeight: '800',
                                                fontStretch: '88%',
                                                letterSpacing: '-.02em',
                                                color: '#E2B65A',
                                                lineHeight: '1',
                                            }}
                                        >
                                            {'Est. 2022'}
                                        </div>
                                        <div
                                            style={{
                                                fontSize: '11px',
                                                fontWeight: '700',
                                                letterSpacing: '.2em',
                                                textTransform: 'uppercase',
                                                color: 'rgba(247,240,222,.55)',
                                                marginTop: '8px',
                                            }}
                                        >
                                            {'Adarna St., Quezon City'}
                                        </div>
                                    </div>
                                    <div style={{ padding: '6px 0' }}>
                                        <div
                                            style={{
                                                fontSize:
                                                    'clamp(26px,3vw,34px)',
                                                fontWeight: '800',
                                                fontStretch: '88%',
                                                letterSpacing: '-.02em',
                                                color: '#E2B65A',
                                                lineHeight: '1',
                                            }}
                                        >
                                            {'6 PM – 12 AM'}
                                        </div>
                                        <div
                                            style={{
                                                fontSize: '11px',
                                                fontWeight: '700',
                                                letterSpacing: '.2em',
                                                textTransform: 'uppercase',
                                                color: 'rgba(247,240,222,.55)',
                                                marginTop: '8px',
                                            }}
                                        >
                                            {'Five nights a week'}
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div
                                data-reveal=""
                                style={{ position: 'relative', minWidth: '0' }}
                            >
                                <img
                                    src="/images/landing-food.png"
                                    alt="The PONGSKILOG storefront at dusk with string lights and outdoor tables"
                                    loading="lazy"
                                    width="2048"
                                    height="1536"
                                    style={{
                                        width: '100%',
                                        height: 'auto',
                                        border: '1px solid rgba(247,240,222,.1)',
                                        borderRadius: '3px',
                                        display: 'block',
                                    }}
                                />
                                <div
                                    style={{
                                        position: 'absolute',
                                        right: '-8px',
                                        bottom: '-16px',
                                        background: '#C89332',
                                        color: '#0B0B0B',
                                        padding: '14px 20px',
                                        borderRadius: '2px',
                                        pointerEvents: 'none',
                                    }}
                                >
                                    <span
                                        style={{
                                            fontSize: '11px',
                                            fontWeight: '800',
                                            letterSpacing: '.2em',
                                            textTransform: 'uppercase',
                                        }}
                                    >
                                        {'Sulit. Solb. Sarap.'}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </section>
                    <section
                        style={{
                            background: '#0B0B0B',
                            borderBottom: '1px solid rgba(247,240,222,.08)',
                        }}
                    >
                        <div
                            style={{
                                maxWidth: '1280px',
                                margin: '0 auto',
                                padding:
                                    'clamp(52px,7vw,100px) clamp(18px,4vw,44px)',
                            }}
                        >
                            <h2
                                data-reveal=""
                                style={{
                                    margin: '0 0 clamp(28px,3.6vw,46px)',
                                    fontSize: 'clamp(30px,4.3vw,52px)',
                                    lineHeight: '1.02',
                                    fontWeight: '800',
                                    fontStretch: '88%',
                                    letterSpacing: '-.02em',
                                    textTransform: 'uppercase',
                                    color: '#F7F0DE',
                                }}
                            >
                                {'Why Pongskilog'}
                            </h2>
                            <div
                                style={{
                                    display: 'grid',
                                    gridTemplateColumns:
                                        'repeat(auto-fit,minmax(min(100%,250px),1fr))',
                                    gap: '1px',
                                    background: 'rgba(247,240,222,.1)',
                                    border: '1px solid rgba(247,240,222,.1)',
                                    borderRadius: '3px',
                                    overflow: 'hidden',
                                }}
                            >
                                <div
                                    className="landing-interaction-13"
                                    data-reveal=""
                                    style={{
                                        background: '#0B0B0B',
                                        padding: 'clamp(26px,3vw,38px)',
                                        transition: 'background .22s',
                                    }}
                                >
                                    <svg
                                        width="30"
                                        height="30"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="#C89332"
                                        strokeWidth="1.4"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        aria-hidden="true"
                                    >
                                        <path d="M12 3c3.2 2.4 4.8 5 4.8 7.6A4.8 4.8 0 0 1 12 15.4a4.8 4.8 0 0 1-4.8-4.8C7.2 8 8.8 5.4 12 3Z"></path>
                                        <path d="M5 20.5h14"></path>
                                    </svg>
                                    <h3
                                        style={{
                                            margin: '22px 0 10px',
                                            fontSize: '17px',
                                            fontWeight: '800',
                                            fontStretch: '92%',
                                            letterSpacing: '.02em',
                                            textTransform: 'uppercase',
                                            color: '#F7F0DE',
                                        }}
                                    >
                                        {'Pinoy flavor'}
                                    </h3>
                                    <p
                                        style={{
                                            margin: '0',
                                            fontSize: '14px',
                                            lineHeight: '1.7',
                                            color: 'rgba(247,240,222,.66)',
                                        }}
                                    >
                                        {
                                            'Familiar Filipino flavors prepared for everyday cravings.'
                                        }
                                    </p>
                                </div>
                                <div
                                    className="landing-interaction-14"
                                    data-reveal=""
                                    style={{
                                        background: '#0B0B0B',
                                        padding: 'clamp(26px,3vw,38px)',
                                        transition: 'background .22s',
                                    }}
                                >
                                    <svg
                                        width="30"
                                        height="30"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="#C89332"
                                        strokeWidth="1.4"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        aria-hidden="true"
                                    >
                                        <path d="M3 13.5h18"></path>
                                        <path d="M4.5 13.5a7.5 7.5 0 0 1 15 0"></path>
                                        <path d="M2 17.5h20"></path>
                                        <path d="M12 6V4"></path>
                                    </svg>
                                    <h3
                                        style={{
                                            margin: '22px 0 10px',
                                            fontSize: '17px',
                                            fontWeight: '800',
                                            fontStretch: '92%',
                                            letterSpacing: '.02em',
                                            textTransform: 'uppercase',
                                            color: '#F7F0DE',
                                        }}
                                    >
                                        {'Sulit servings'}
                                    </h3>
                                    <p
                                        style={{
                                            margin: '0',
                                            fontSize: '14px',
                                            lineHeight: '1.7',
                                            color: 'rgba(247,240,222,.66)',
                                        }}
                                    >
                                        {
                                            'Meals designed to leave customers satisfied.'
                                        }
                                    </p>
                                </div>
                                <div
                                    className="landing-interaction-15"
                                    data-reveal=""
                                    style={{
                                        background: '#0B0B0B',
                                        padding: 'clamp(26px,3vw,38px)',
                                        transition: 'background .22s',
                                    }}
                                >
                                    <svg
                                        width="30"
                                        height="30"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="#C89332"
                                        strokeWidth="1.4"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        aria-hidden="true"
                                    >
                                        <path d="M12 20.5s-7.5-4.3-7.5-9.6A4.1 4.1 0 0 1 12 8.4a4.1 4.1 0 0 1 7.5 2.5c0 5.3-7.5 9.6-7.5 9.6Z"></path>
                                    </svg>
                                    <h3
                                        style={{
                                            margin: '22px 0 10px',
                                            fontSize: '17px',
                                            fontWeight: '800',
                                            fontStretch: '92%',
                                            letterSpacing: '.02em',
                                            textTransform: 'uppercase',
                                            color: '#F7F0DE',
                                        }}
                                    >
                                        {'Made for cravings'}
                                    </h3>
                                    <p
                                        style={{
                                            margin: '0',
                                            fontSize: '14px',
                                            lineHeight: '1.7',
                                            color: 'rgba(247,240,222,.66)',
                                        }}
                                    >
                                        {'Comfort food worth coming back for.'}
                                    </p>
                                </div>
                                <div
                                    className="landing-interaction-16"
                                    data-reveal=""
                                    style={{
                                        background: '#0B0B0B',
                                        padding: 'clamp(26px,3vw,38px)',
                                        transition: 'background .22s',
                                    }}
                                >
                                    <svg
                                        width="30"
                                        height="30"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="#C89332"
                                        strokeWidth="1.4"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        aria-hidden="true"
                                    >
                                        <circle
                                            cx="12"
                                            cy="9.5"
                                            r="5.5"
                                        ></circle>
                                        <path d="M8.5 14.5 7 21l5-2.4L17 21l-1.5-6.5"></path>
                                    </svg>
                                    <h3
                                        style={{
                                            margin: '22px 0 10px',
                                            fontSize: '17px',
                                            fontWeight: '800',
                                            fontStretch: '92%',
                                            letterSpacing: '.02em',
                                            textTransform: 'uppercase',
                                            color: '#F7F0DE',
                                        }}
                                    >
                                        {'Est. 2022'}
                                    </h3>
                                    <p
                                        style={{
                                            margin: '0',
                                            fontSize: '14px',
                                            lineHeight: '1.7',
                                            color: 'rgba(247,240,222,.66)',
                                        }}
                                    >
                                        {
                                            'Built with consistency and love for Filipino food.'
                                        }
                                    </p>
                                </div>
                            </div>
                        </div>
                    </section>
                    <section
                        id="location"
                        style={{
                            background: '#141414',
                            borderBottom: '1px solid rgba(247,240,222,.08)',
                            scrollMarginTop: '92px',
                        }}
                    >
                        <div
                            style={{
                                maxWidth: '1280px',
                                margin: '0 auto',
                                padding:
                                    'clamp(52px,7vw,100px) clamp(18px,4vw,44px)',
                                display: 'grid',
                                gridTemplateColumns:
                                    'repeat(auto-fit,minmax(min(100%,300px),1fr))',
                                gap: 'clamp(28px,4vw,56px)',
                                alignItems: 'start',
                            }}
                        >
                            <div data-reveal="" style={{ minWidth: '0' }}>
                                <div
                                    style={{
                                        fontSize: '11px',
                                        fontWeight: '800',
                                        letterSpacing: '.26em',
                                        textTransform: 'uppercase',
                                        color: '#C89332',
                                        marginBottom: '16px',
                                    }}
                                >
                                    {'Find us'}
                                </div>
                                <h2
                                    style={{
                                        margin: '0',
                                        fontSize: 'clamp(30px,4.3vw,52px)',
                                        lineHeight: '1.02',
                                        fontWeight: '800',
                                        fontStretch: '88%',
                                        letterSpacing: '-.02em',
                                        textTransform: 'uppercase',
                                        color: '#F7F0DE',
                                    }}
                                >
                                    {'Visit the store'}
                                </h2>
                                <address
                                    style={{
                                        display: 'block',
                                        fontStyle: 'normal',
                                        margin: '28px 0 0',
                                        fontSize: 'clamp(16px,1.6vw,19px)',
                                        lineHeight: '1.6',
                                        color: '#F7F0DE',
                                    }}
                                >
                                    {'1511, Unit V, Purok 8 Adarna St.'}
                                    <br />
                                    {'Quezon City, Metro Manila'}
                                    <br />
                                    {'Philippines'}
                                </address>
                                <div
                                    style={{
                                        marginTop: '30px',
                                        paddingTop: '24px',
                                        borderTop:
                                            '1px solid rgba(247,240,222,.1)',
                                    }}
                                >
                                    <div
                                        style={{
                                            fontSize: '11px',
                                            fontWeight: '800',
                                            letterSpacing: '.22em',
                                            textTransform: 'uppercase',
                                            color: '#C89332',
                                            marginBottom: '16px',
                                        }}
                                    >
                                        {'Store hours'}
                                    </div>
                                    {hours.map((h) => (
                                        <Fragment key={h.day}>
                                            <div
                                                style={{
                                                    display: 'flex',
                                                    justifyContent:
                                                        'space-between',
                                                    gap: '16px',
                                                    padding: '9px 0',
                                                    borderBottom:
                                                        '1px solid rgba(247,240,222,.06)',
                                                }}
                                            >
                                                <span
                                                    style={{
                                                        fontSize: '14px',
                                                        fontWeight: '600',
                                                        color: h.fg,
                                                    }}
                                                >
                                                    {h.day}
                                                </span>
                                                <span
                                                    style={{
                                                        fontSize: '14px',
                                                        fontVariantNumeric:
                                                            'tabular-nums',
                                                        color: h.fg,
                                                    }}
                                                >
                                                    {h.time}
                                                </span>
                                            </div>
                                        </Fragment>
                                    ))}
                                </div>
                                <div
                                    style={{
                                        display: 'flex',
                                        flexWrap: 'wrap',
                                        gap: '10px',
                                        marginTop: '30px',
                                    }}
                                >
                                    <a
                                        className="landing-interaction-17"
                                        href="https://www.google.com/maps/search/?api=1&query=PONGSKILOG%20Adarna%20Street%20Quezon%20City"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            background: '#C89332',
                                            color: '#0B0B0B',
                                            border: '1px solid #C89332',
                                            borderRadius: '2px',
                                            minHeight: '50px',
                                            padding: '0 24px',
                                            fontSize: '11.5px',
                                            fontWeight: '800',
                                            letterSpacing: '.16em',
                                            textTransform: 'uppercase',
                                            transition:
                                                'background .18s,transform .18s,box-shadow .18s',
                                        }}
                                    >
                                        {'Get directions'}
                                    </a>
                                    <a
                                        className="landing-interaction-18"
                                        href="https://www.facebook.com/people/Pongski-log/100094575655003/"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        style={{
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            background: 'transparent',
                                            color: '#F7F0DE',
                                            border: '1px solid rgba(247,240,222,.34)',
                                            borderRadius: '2px',
                                            minHeight: '50px',
                                            padding: '0 24px',
                                            fontSize: '11.5px',
                                            fontWeight: '800',
                                            letterSpacing: '.16em',
                                            textTransform: 'uppercase',
                                            transition:
                                                'border-color .18s,background .18s',
                                        }}
                                    >
                                        {'Message / inquire'}
                                    </a>
                                    <button
                                        className="landing-interaction-19"
                                        type="button"
                                        onClick={copyAddress}
                                        style={{
                                            background: 'transparent',
                                            color: '#F7F0DE',
                                            border: '1px solid rgba(247,240,222,.34)',
                                            borderRadius: '2px',
                                            minHeight: '50px',
                                            padding: '0 24px',
                                            fontSize: '11.5px',
                                            fontWeight: '800',
                                            letterSpacing: '.16em',
                                            textTransform: 'uppercase',
                                            cursor: 'pointer',
                                            transition:
                                                'border-color .18s,background .18s',
                                        }}
                                    >
                                        {copyLabel}
                                    </button>
                                </div>
                            </div>
                            <div data-reveal="" style={{ minWidth: '0' }}>
                                <a
                                    className="landing-interaction-20"
                                    href="https://www.google.com/maps/search/?api=1&query=PONGSKILOG%20Adarna%20Street%20Quezon%20City"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    aria-label="Open PONGSKILOG on Google Maps"
                                    style={{
                                        display: 'block',
                                        position: 'relative',
                                        aspectRatio: '4/3',
                                        border: '1px solid rgba(200,147,50,.34)',
                                        borderRadius: '3px',
                                        overflow: 'hidden',
                                        background: '#0B0B0B',
                                        transition:
                                            'border-color .22s,box-shadow .22s',
                                    }}
                                >
                                    <div
                                        style={{
                                            position: 'absolute',
                                            inset: '0',
                                            background:
                                                'repeating-linear-gradient(0deg,rgba(247,240,222,.05) 0 1px,transparent 1px 46px),repeating-linear-gradient(90deg,rgba(247,240,222,.05) 0 1px,transparent 1px 46px)',
                                        }}
                                    ></div>
                                    <div
                                        style={{
                                            position: 'absolute',
                                            inset: '0',
                                            background:
                                                'linear-gradient(122deg,transparent 0 44%,rgba(200,147,50,.22) 44% 46%,transparent 46%),linear-gradient(28deg,transparent 0 62%,rgba(200,147,50,.14) 62% 63.4%,transparent 63.4%)',
                                        }}
                                    ></div>
                                    <div
                                        style={{
                                            position: 'absolute',
                                            inset: '0',
                                            display: 'flex',
                                            flexDirection: 'column',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            gap: '14px',
                                            textAlign: 'center',
                                            padding: '24px',
                                        }}
                                    >
                                        <svg
                                            width="40"
                                            height="40"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="#E2B65A"
                                            strokeWidth="1.5"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            aria-hidden="true"
                                        >
                                            <path d="M20 10.5c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path>
                                            <circle
                                                cx="12"
                                                cy="10.3"
                                                r="2.9"
                                            ></circle>
                                        </svg>
                                        <div
                                            style={{
                                                fontSize:
                                                    'clamp(18px,2.2vw,24px)',
                                                fontWeight: '800',
                                                fontStretch: '90%',
                                                letterSpacing: '.03em',
                                                textTransform: 'uppercase',
                                                color: '#F7F0DE',
                                            }}
                                        >
                                            {'Pongskilog'}
                                        </div>
                                        <div
                                            style={{
                                                fontSize: '13px',
                                                lineHeight: '1.6',
                                                color: 'rgba(247,240,222,.66)',
                                                maxWidth: '30ch',
                                            }}
                                        >
                                            {'Adarna Street, Quezon City'}
                                        </div>
                                        <span
                                            style={{
                                                marginTop: '6px',
                                                fontSize: '11px',
                                                fontWeight: '800',
                                                letterSpacing: '.18em',
                                                textTransform: 'uppercase',
                                                color: '#C89332',
                                                borderBottom:
                                                    '1px solid rgba(200,147,50,.5)',
                                                paddingBottom: '4px',
                                            }}
                                        >
                                            {'Open in Google Maps →'}
                                        </span>
                                    </div>
                                </a>
                                <p
                                    style={{
                                        margin: '14px 0 0',
                                        fontSize: '12.5px',
                                        lineHeight: '1.7',
                                        color: 'rgba(247,240,222,.48)',
                                    }}
                                >
                                    {
                                        "Opens the store's Google Maps pin in a new tab."
                                    }
                                </p>
                            </div>
                        </div>
                    </section>
                    <section
                        style={{
                            background: '#0B0B0B',
                            borderBottom: '1px solid rgba(247,240,222,.08)',
                        }}
                    >
                        <div
                            style={{
                                maxWidth: '1080px',
                                margin: '0 auto',
                                padding:
                                    'clamp(52px,7vw,96px) clamp(18px,4vw,44px)',
                                display: 'flex',
                                flexWrap: 'wrap',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                gap: '32px',
                            }}
                            data-reveal=""
                        >
                            <div
                                style={{ minWidth: '260px', flex: '1 1 340px' }}
                            >
                                <div
                                    style={{
                                        display: 'flex',
                                        alignItems: 'center',
                                        gap: '12px',
                                        marginBottom: '18px',
                                    }}
                                >
                                    <svg
                                        width="26"
                                        height="26"
                                        viewBox="0 0 24 24"
                                        fill="#C89332"
                                        aria-hidden="true"
                                    >
                                        <path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06C2 17.08 5.66 21.24 10.44 22v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.9 3.77-3.9 1.09 0 2.23.2 2.23.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.44 2.91h-2.34V22C18.34 21.24 22 17.08 22 12.06Z"></path>
                                    </svg>
                                    <span
                                        style={{
                                            fontSize: '11px',
                                            fontWeight: '800',
                                            letterSpacing: '.26em',
                                            textTransform: 'uppercase',
                                            color: '#C89332',
                                        }}
                                    >
                                        {'On Facebook'}
                                    </span>
                                </div>
                                <h2
                                    style={{
                                        margin: '0',
                                        fontSize: 'clamp(28px,4vw,48px)',
                                        lineHeight: '1.03',
                                        fontWeight: '800',
                                        fontStretch: '88%',
                                        letterSpacing: '-.02em',
                                        textTransform: 'uppercase',
                                        color: '#F7F0DE',
                                    }}
                                >
                                    {'Follow the craving.'}
                                </h2>
                                <p
                                    style={{
                                        margin: '20px 0 0',
                                        maxWidth: '48ch',
                                        fontSize: '15.5px',
                                        lineHeight: '1.7',
                                        color: 'rgba(247,240,222,.7)',
                                    }}
                                >
                                    {
                                        'Stay updated with our latest meals, announcements, promos, and more. Orders and inquiries go through our Facebook page.'
                                    }
                                </p>
                            </div>
                            <a
                                className="landing-interaction-21"
                                href="https://www.facebook.com/people/Pongski-log/100094575655003/"
                                target="_blank"
                                rel="noopener noreferrer"
                                style={{
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: '12px',
                                    background: '#C89332',
                                    color: '#0B0B0B',
                                    borderRadius: '2px',
                                    minHeight: '56px',
                                    padding: '0 30px',
                                    fontSize: '12.5px',
                                    fontWeight: '800',
                                    letterSpacing: '.16em',
                                    textTransform: 'uppercase',
                                    transition:
                                        'background .18s,transform .18s,box-shadow .18s',
                                }}
                            >
                                <svg
                                    width="20"
                                    height="20"
                                    viewBox="0 0 24 24"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path d="M22 12.06C22 6.5 17.52 2 12 2S2 6.5 2 12.06C2 17.08 5.66 21.24 10.44 22v-7.03H7.9v-2.91h2.54V9.85c0-2.51 1.49-3.9 3.77-3.9 1.09 0 2.23.2 2.23.2v2.46h-1.26c-1.24 0-1.63.78-1.63 1.57v1.88h2.78l-.44 2.91h-2.34V22C18.34 21.24 22 17.08 22 12.06Z"></path>
                                </svg>
                                {'\n      Visit Facebook\n    '}
                            </a>
                        </div>
                    </section>
                    <section
                        style={{ background: '#F7F0DE', color: '#0B0B0B' }}
                    >
                        <div
                            style={{
                                maxWidth: '1080px',
                                margin: '0 auto',
                                padding:
                                    'clamp(58px,8vw,116px) clamp(18px,4vw,44px)',
                                textAlign: 'center',
                            }}
                            data-reveal=""
                        >
                            <div
                                style={{
                                    display: 'flex',
                                    alignItems: 'center',
                                    justifyContent: 'center',
                                    gap: '14px',
                                    marginBottom: '26px',
                                }}
                            >
                                <span
                                    style={{
                                        width: 'clamp(30px,8vw,90px)',
                                        height: '1px',
                                        background: 'rgba(11,11,11,.28)',
                                    }}
                                ></span>
                                <span
                                    style={{
                                        width: '6px',
                                        height: '6px',
                                        background: '#0B0B0B',
                                        transform: 'rotate(45deg)',
                                    }}
                                ></span>
                                <span
                                    style={{
                                        width: 'clamp(30px,8vw,90px)',
                                        height: '1px',
                                        background: 'rgba(11,11,11,.28)',
                                    }}
                                ></span>
                            </div>
                            <h2
                                style={{
                                    margin: '0',
                                    fontSize: 'clamp(48px,9vw,110px)',
                                    lineHeight: '.9',
                                    fontWeight: '800',
                                    fontStretch: '84%',
                                    letterSpacing: '-.035em',
                                    textTransform: 'uppercase',
                                    color: '#0B0B0B',
                                }}
                            >
                                {'Hungry na?'}
                            </h2>
                            <p
                                style={{
                                    margin: '22px 0 0',
                                    fontSize: 'clamp(17px,2.2vw,26px)',
                                    fontWeight: '700',
                                    fontStretch: '92%',
                                    letterSpacing: '.01em',
                                    color: '#7A5A1E',
                                }}
                            >
                                {'Tara, PONGSKILOG na.'}
                            </p>
                            <div
                                style={{
                                    display: 'flex',
                                    flexWrap: 'wrap',
                                    gap: '12px',
                                    justifyContent: 'center',
                                    marginTop: '38px',
                                }}
                            >
                                <button
                                    className="landing-interaction-22"
                                    type="button"
                                    onClick={goMenu}
                                    style={{
                                        background: '#0B0B0B',
                                        color: '#F7F0DE',
                                        border: '1px solid #0B0B0B',
                                        borderRadius: '2px',
                                        minHeight: '54px',
                                        padding: '0 30px',
                                        fontSize: '12.5px',
                                        fontWeight: '800',
                                        letterSpacing: '.17em',
                                        textTransform: 'uppercase',
                                        cursor: 'pointer',
                                        transition:
                                            'transform .18s,background .18s,box-shadow .18s',
                                    }}
                                >
                                    {'View Menu'}
                                </button>
                                <a
                                    className="landing-interaction-23"
                                    href="https://www.google.com/maps/search/?api=1&query=PONGSKILOG%20Adarna%20Street%20Quezon%20City"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        background: 'transparent',
                                        color: '#0B0B0B',
                                        border: '1px solid rgba(11,11,11,.35)',
                                        borderRadius: '2px',
                                        minHeight: '54px',
                                        padding: '0 30px',
                                        fontSize: '12.5px',
                                        fontWeight: '800',
                                        letterSpacing: '.17em',
                                        textTransform: 'uppercase',
                                        transition:
                                            'background .18s,border-color .18s',
                                    }}
                                >
                                    {'Find Us'}
                                </a>
                                <a
                                    className="landing-interaction-24"
                                    href="https://www.facebook.com/people/Pongski-log/100094575655003/"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    style={{
                                        display: 'inline-flex',
                                        alignItems: 'center',
                                        justifyContent: 'center',
                                        background: 'transparent',
                                        color: '#0B0B0B',
                                        border: '1px solid rgba(11,11,11,.35)',
                                        borderRadius: '2px',
                                        minHeight: '54px',
                                        padding: '0 30px',
                                        fontSize: '12.5px',
                                        fontWeight: '800',
                                        letterSpacing: '.17em',
                                        textTransform: 'uppercase',
                                        transition:
                                            'background .18s,border-color .18s',
                                    }}
                                >
                                    {'Facebook'}
                                </a>
                            </div>
                        </div>
                    </section>
                </main>
                <footer
                    style={{
                        background: '#0B0B0B',
                        borderTop: '2px solid #C89332',
                    }}
                >
                    <div
                        style={{
                            maxWidth: '1280px',
                            margin: '0 auto',
                            padding:
                                'clamp(42px,5.5vw,72px) clamp(18px,4vw,44px)',
                            display: 'grid',
                            gridTemplateColumns:
                                'repeat(auto-fit,minmax(min(100%,210px),1fr))',
                            gap: 'clamp(28px,4vw,52px)',
                        }}
                    >
                        <div style={{ minWidth: '0' }}>
                            <img
                                src="/images/landing-logo.png"
                                alt="PONGSKILOG"
                                style={{
                                    height: '52px',
                                    width: 'auto',
                                    marginBottom: '18px',
                                }}
                            />
                            <p
                                style={{
                                    margin: '0',
                                    fontSize: '11px',
                                    fontWeight: '800',
                                    letterSpacing: '.24em',
                                    textTransform: 'uppercase',
                                    color: '#C89332',
                                }}
                            >
                                {'Est. 2022'}
                            </p>
                            <p
                                style={{
                                    margin: '14px 0 0',
                                    fontSize: '13.5px',
                                    lineHeight: '1.75',
                                    color: 'rgba(247,240,222,.6)',
                                    maxWidth: '34ch',
                                }}
                            >
                                {
                                    'Filipino silog, pares and short orders in Quezon City. Dine-in and takeout.'
                                }
                            </p>
                        </div>
                        <div style={{ minWidth: '0' }}>
                            <h3
                                style={{
                                    margin: '0 0 16px',
                                    fontSize: '11px',
                                    fontWeight: '800',
                                    letterSpacing: '.22em',
                                    textTransform: 'uppercase',
                                    color: '#C89332',
                                }}
                            >
                                {'Visit'}
                            </h3>
                            <p
                                style={{
                                    margin: '0',
                                    fontSize: '13.5px',
                                    lineHeight: '1.8',
                                    color: 'rgba(247,240,222,.72)',
                                }}
                            >
                                {'1511, Unit V, Purok 8 Adarna St.'}
                                <br />
                                {'Quezon City, Metro Manila'}
                                <br />
                                {'Philippines'}
                            </p>
                            <a
                                href="https://www.google.com/maps/search/?api=1&query=PONGSKILOG%20Adarna%20Street%20Quezon%20City"
                                target="_blank"
                                rel="noopener noreferrer"
                                style={{
                                    display: 'inline-block',
                                    marginTop: '14px',
                                    fontSize: '12px',
                                    fontWeight: '700',
                                    letterSpacing: '.14em',
                                    textTransform: 'uppercase',
                                    color: '#E2B65A',
                                    borderBottom:
                                        '1px solid rgba(200,147,50,.45)',
                                    paddingBottom: '3px',
                                }}
                            >
                                {'Google Maps'}
                            </a>
                        </div>
                        <div style={{ minWidth: '0' }}>
                            <h3
                                style={{
                                    margin: '0 0 16px',
                                    fontSize: '11px',
                                    fontWeight: '800',
                                    letterSpacing: '.22em',
                                    textTransform: 'uppercase',
                                    color: '#C89332',
                                }}
                            >
                                {'Hours'}
                            </h3>
                            <p
                                style={{
                                    margin: '0',
                                    fontSize: '13.5px',
                                    lineHeight: '1.8',
                                    color: 'rgba(247,240,222,.72)',
                                    fontVariantNumeric: 'tabular-nums',
                                }}
                            >
                                {'Mon, Tue, Thu, Fri, Sat'}
                                <br />
                                {'6:00 PM – 12:00 AM'}
                                <br />
                                <span
                                    style={{ color: 'rgba(247,240,222,.42)' }}
                                >
                                    {'Wed closed (sometimes open)'}
                                    <br />
                                    {'Sun always closed'}
                                </span>
                            </p>
                        </div>
                        <div style={{ minWidth: '0' }}>
                            <h3
                                style={{
                                    margin: '0 0 16px',
                                    fontSize: '11px',
                                    fontWeight: '800',
                                    letterSpacing: '.22em',
                                    textTransform: 'uppercase',
                                    color: '#C89332',
                                }}
                            >
                                {'Browse'}
                            </h3>
                            <div
                                style={{
                                    display: 'flex',
                                    flexDirection: 'column',
                                    gap: '11px',
                                    alignItems: 'flex-start',
                                }}
                            >
                                <a
                                    className="landing-interaction-25"
                                    href="#menu"
                                    onClick={goMenu}
                                    style={{
                                        fontSize: '13.5px',
                                        color: 'rgba(247,240,222,.72)',
                                    }}
                                >
                                    {'Menu'}
                                </a>
                                <a
                                    className="landing-interaction-26"
                                    href="#about"
                                    onClick={goAbout}
                                    style={{
                                        fontSize: '13.5px',
                                        color: 'rgba(247,240,222,.72)',
                                    }}
                                >
                                    {'About'}
                                </a>
                                <a
                                    className="landing-interaction-27"
                                    href="#location"
                                    onClick={goLocation}
                                    style={{
                                        fontSize: '13.5px',
                                        color: 'rgba(247,240,222,.72)',
                                    }}
                                >
                                    {'Location'}
                                </a>
                                <a
                                    className="landing-interaction-28"
                                    href="https://www.facebook.com/people/Pongski-log/100094575655003/"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    style={{
                                        fontSize: '13.5px',
                                        color: 'rgba(247,240,222,.72)',
                                    }}
                                >
                                    {'Facebook'}
                                </a>
                                <a
                                    className="landing-interaction-29"
                                    href="https://www.facebook.com/people/Pongski-log/100094575655003/"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    style={{
                                        fontSize: '13.5px',
                                        color: 'rgba(247,240,222,.72)',
                                    }}
                                >
                                    {'Order / Inquire'}
                                </a>
                            </div>
                        </div>
                    </div>
                    <div
                        style={{ borderTop: '1px solid rgba(200,147,50,.24)' }}
                    >
                        <div
                            style={{
                                maxWidth: '1280px',
                                margin: '0 auto',
                                padding: '22px clamp(18px,4vw,44px)',
                                display: 'flex',
                                flexWrap: 'wrap',
                                gap: '10px 24px',
                                justifyContent: 'space-between',
                                alignItems: 'center',
                            }}
                        >
                            <span
                                style={{
                                    fontSize: '11.5px',
                                    letterSpacing: '.1em',
                                    color: 'rgba(247,240,222,.45)',
                                }}
                            >
                                {'© 2026 PONGSKILOG. All rights reserved.'}
                            </span>
                            <span
                                style={{
                                    fontSize: '11.5px',
                                    fontWeight: '700',
                                    letterSpacing: '.2em',
                                    textTransform: 'uppercase',
                                    color: 'rgba(200,147,50,.75)',
                                }}
                            >
                                {'Sarap na babalik-balikan'}
                            </span>
                        </div>
                    </div>
                </footer>
                {toast && (
                    <>
                        <div
                            role="status"
                            style={{
                                position: 'fixed',
                                left: '50%',
                                bottom: '26px',
                                transform: 'translateX(-50%)',
                                zIndex: '95',
                                background: '#F7F0DE',
                                color: '#0B0B0B',
                                borderRadius: '2px',
                                padding: '14px 22px',
                                fontSize: '12px',
                                fontWeight: '800',
                                letterSpacing: '.14em',
                                textTransform: 'uppercase',
                                boxShadow: '0 16px 40px rgba(0,0,0,.5)',
                                animation:
                                    'psToast .24s cubic-bezier(.2,.8,.2,1)',
                                maxWidth: 'calc(100vw - 36px)',
                                textAlign: 'center',
                            }}
                        >
                            {toast}
                        </div>
                    </>
                )}
            </div>
        </div>
    );
}
