import { Link, http } from '@inertiajs/react';
import React, { Fragment } from 'react';
import {
    CustomerReviews,
    ReviewLegalDialog,
} from '@/components/customer-reviews';
import { LandingMenuPrices } from '@/components/landing-menu-prices';
import {
    ULAM,
    CARDS,
    CUPS,
    MENU,
    HOURS,
    CAT_ORDER,
    BOARD_CATS,
    SHOP,
} from '@/lib/landing';
import { websiteEventDue, websiteLinkEvent } from '@/lib/website-analytics';
import { store as storeWebsiteEvent } from '@/routes/website-events';

/* Anonymous first-party counters for the Owner's Website Analytics: an event name only. A failed beacon never touches the page. */
const sentEvents = {};
const track = (event) => {
    if (!websiteEventDue(sentEvents, event, Date.now())) return;
    http.getClient()
        .request({ ...storeWebsiteEvent(), data: { event } })
        .catch(() => {});
};

let leafletPromise;
const loadLeaflet = () => {
    if (window.L) return Promise.resolve(window.L);
    if (!leafletPromise)
        leafletPromise = new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = '/landing-leaflet-1.9.4.js';
            script.async = true;
            script.onload = () => resolve(window.L);
            script.onerror = () => {
                leafletPromise = undefined;
                script.remove();
                reject(new Error('Map unavailable'));
            };
            document.head.appendChild(script);
        });
    return leafletPromise;
};

// Interaction timings and geometry follow the approved standalone reference.
const cssStyle = (value) =>
    Object.fromEntries(
        value
            .split(';')
            .filter((rule) => rule.includes(':'))
            .map((rule) => {
                const colon = rule.indexOf(':');
                const name = rule
                    .slice(0, colon)
                    .trim()
                    .replace(/^-webkit-/, 'Webkit-')
                    .replace(/-([a-z])/g, (_, char) => char.toUpperCase());
                return [name, rule.slice(colon + 1).trim()];
            }),
    );
function phtDay() {
    try {
        return new Intl.DateTimeFormat('en-US', {
            timeZone: 'Asia/Manila',
            weekday: 'short',
        }).format(new Date());
    } catch {
        return '';
    }
}
const PILL =
    'flex:0 0 auto;height:44px;padding:0 16px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;white-space:nowrap;transition:background .18s,color .18s,border-color .18s;';
const ON = 'background:#121110;color:#FFFFFF;border:1px solid #121110;';
const OFF =
    'background:#FFFFFF;color:rgba(18,17,16,.72);border:1px solid rgba(18,17,16,.16);';
const clamp = (v) => Math.max(0, Math.min(1, v));
const RES = (url) => url;
function openStatus() {
    if (phtDay() === 'Sun') {
        return { label: 'Closed today', short: 'Closed today', fg: '#B0281F' };
    }
    // Scheduled hours are customer-approved; live opening and menu availability still need confirmation.
    return {
        label:
            phtDay() === 'Wed'
                ? 'Wednesday: contact to confirm'
                : '6:00 PM - 12:00 AM · confirm availability',
        short:
            phtDay() === 'Wed' ? 'Usually closed · ask us' : '6 PM - midnight',
        fg: '#8A5E12',
    };
}
const MARQ_A = [
    'Tapsilog',
    'Liemposilog',
    'Chicksilog',
    'Bangsilog',
    'Longsilog',
    'Hamsilog',
];
const MARQ_B = [
    'Beef pares',
    'Bulalo',
    'Pancit canton',
    'Pork sisig',
    'Lemon Yakult',
    'Mango graham',
];

export default class LandingExperience extends React.Component {
    rootRef = React.createRef();
    heroRef = React.createRef();
    wordsRef = React.createRef();
    peakRef = React.createRef();
    panRef = React.createRef();
    trackRef = React.createRef();
    lemonRef = React.createRef();
    deskNavRef = React.createRef();
    progRef = React.createRef();
    marqRef = React.createRef();
    prefixRef = React.createRef();
    priceRef = React.createRef();
    peakBarRef = React.createRef();
    plateWrapRef = React.createRef();
    countRef = React.createRef();
    comeRef = React.createRef();
    mapRef = React.createRef();
    infoRef = React.createRef();
    heroRowRef = React.createRef();
    wmRef = React.createRef();
    heroTextRef = React.createRef();
    heroMediaRef = React.createRef();
    heroImgRef = React.createRef();
    lineRef = React.createRef();
    peakRowRef = React.createRef();
    peakImgRef = React.createRef();
    _today = phtDay();
    state = {
        idx: 0,
        cat: 'silog',
        dwell: false,
        boardCat: 'all',
        menuLens: 'pics',
        narrow: false,
        profOpen: false,
        /* Touch map: true = locked (page scrolls over it), false = unlocked by a tap, null = mouse or not ready. */
        mapLocked: null,
        privacyOpen: false,
    };
    profRef = React.createRef();
    profPopRef = React.createRef();
    _lastProf = false;
    toggleProf = () => this.setState((s) => ({ profOpen: !s.profOpen }));
    staffLogin = () => {
        this.setState({ profOpen: false });
    };
    onDocDown = (e) => {
        if (
            this.state.profOpen &&
            this.profRef.current &&
            !this.profRef.current.contains(e.target)
        )
            this.setState({ profOpen: false });
        if (
            this.state.mapLocked === false &&
            this.mapRef.current &&
            !this.mapRef.current.contains(e.target)
        )
            this.lockMap();
    };
    /* Counts taps on the Facebook, directions and Menu links anywhere on the page. */
    trackLink = (e) => {
        const event = websiteLinkEvent(
            e.target.closest?.('a')?.getAttribute('href') ?? null,
        );
        if (event) track(event);
    };
    onEsc = (e) => {
        if (e.key === 'Escape' && this.state.profOpen)
            this.setState({ profOpen: false });
    };
    chipRowRef = React.createRef();
    _lastBoardCat = 'all';
    pickBoardCat = (k) => () => {
        track('menu_click');
        if (k !== this.state.boardCat) this.setState({ boardCat: k });
    };
    setMenuLens = (menuLens) => {
        track('menu_click');
        /* Each view has its own categories: start its filter row from the first chip again. */
        if (this.chipRowRef.current) this.chipRowRef.current.scrollLeft = 0;
        this.setState({ menuLens, boardCat: 'all' });
    };
    pauseMarquee = () => {
        this._marqueePaused = true;
    };
    resumeMarquee = () => {
        this._marqueePaused = false;
    };
    viewRef = React.createRef();
    slideBarRef = React.createRef();
    _sx = 0;
    _stx = 0;
    _sActive = -1;

    slideBounds() {
        const v = this.viewRef.current,
            t = this.trackRef.current;
        if (!v || !t) return null;
        const cards = Array.from(t.querySelectorAll('[data-card]'));
        if (!cards.length) return null;
        const max = Math.max(0, t.scrollWidth - v.clientWidth);
        const base = cards[0].offsetLeft;
        return {
            v,
            t,
            cards,
            max,
            stops: cards.map((c) => Math.min(max, c.offsetLeft - base)),
        };
    }
    nearest(x, bias) {
        const b = this.slideBounds();
        if (!b) return 0;
        let best = 0,
            d = Infinity;
        b.stops.forEach((s, i) => {
            const dd = Math.abs(s - x);
            if (dd < d) {
                d = dd;
                best = i;
            }
        });
        if (bias && Math.abs(bias) > 0.35) {
            const cur = b.stops[best];
            if (bias > 0 && cur < x - 1)
                best = Math.min(b.stops.length - 1, best + 1);
            if (bias < 0 && cur > x + 1) best = Math.max(0, best - 1);
        }
        return best;
    }
    slideTo = (i) => {
        const b = this.slideBounds();
        if (!b) return;
        i = Math.max(0, Math.min(b.stops.length - 1, i));
        let x = b.stops[i];
        if (i === b.stops.length - 1) x = b.max;
        this._stx = x;
        if (!this.motionOn()) this._sx = x;
    };
    activeIdx() {
        return this.nearest(this._stx);
    }
    slidePrev = () => this.slideTo(this.activeIdx() - 1);
    slideNext = () => this.slideTo(this.activeIdx() + 1);
    slideKey = (e) => {
        if (e.key === 'ArrowRight') {
            e.preventDefault();
            this.slideNext();
        }
        if (e.key === 'ArrowLeft') {
            e.preventDefault();
            this.slidePrev();
        }
    };
    dragStart = (e) => {
        if (e.button !== undefined && e.button !== 0) return;
        const v = this.viewRef.current;
        if (!v) return;
        this._drag = {
            x: e.clientX,
            y: e.clientY,
            start: this._stx,
            lastX: e.clientX,
            lastT: performance.now(),
            vel: 0,
            moved: false,
            id: e.pointerId,
            locked: e.pointerType === 'mouse',
        };
        v.style.cursor = 'grabbing';
        if (e.pointerType === 'mouse') {
            try {
                v.setPointerCapture(e.pointerId);
            } catch {}
        }
    };
    dragMove = (e) => {
        const d = this._drag;
        if (!d) return;
        const dx = e.clientX - d.x,
            dy = e.clientY - d.y;
        if (!d.locked) {
            if (Math.abs(dx) < 6 && Math.abs(dy) < 6) return;
            if (Math.abs(dy) > Math.abs(dx)) {
                this._drag = null;
                this.viewRef.current.style.cursor = 'grab';
                return;
            }
            d.locked = true;
            try {
                this.viewRef.current.setPointerCapture(d.id);
            } catch {}
        }
        if (Math.abs(dx) > 4) d.moved = true;
        const b = this.slideBounds();
        if (!b) return;
        let x = d.start - dx;
        if (x < 0) x = x * 0.35;
        if (x > b.max) x = b.max + (x - b.max) * 0.35;
        this._stx = x;
        if (!this.motionOn()) this._sx = x;
        const now = performance.now(),
            dt = Math.max(1, now - d.lastT);
        d.vel = d.vel * 0.6 + ((d.lastX - e.clientX) / dt) * 0.4;
        d.lastX = e.clientX;
        d.lastT = now;
    };
    dragEnd = () => {
        const d = this._drag;
        this._drag = null;
        const v = this.viewRef.current;
        if (v) v.style.cursor = 'grab';
        if (!d) return;
        this._dragMoved = d.moved;
        setTimeout(() => {
            this._dragMoved = false;
        }, 0);
        const b = this.slideBounds();
        if (!b) return;
        if (performance.now() - d.lastT > 80) d.vel = 0;
        d.vel = Math.max(-2.5, Math.min(2.5, d.vel));
        const proj = Math.max(0, Math.min(b.max, this._stx + d.vel * 260));
        this.slideTo(this.nearest(proj, d.vel));
    };
    pickCard = (i) => () => {
        if (!this._dragMoved) this.slideTo(i);
    };
    onWheelH = (e) => {
        if (Math.abs(e.deltaX) <= Math.abs(e.deltaY) || Math.abs(e.deltaX) < 2)
            return;
        e.preventDefault();
        const b = this.slideBounds();
        if (!b) return;
        this._stx = Math.max(0, Math.min(b.max, this._stx + e.deltaX));
        clearTimeout(this._wt);
        this._wt = setTimeout(() => this.slideTo(this.nearest(this._stx)), 140);
    };

    slideFrame(motion) {
        const b = this.slideBounds();
        if (!b) return;
        const r = b.v.getBoundingClientRect();
        if (r.bottom < -200 || r.top > window.innerHeight + 200) return;
        if (!this._drag && this._stx > b.max) this._stx = b.max;
        const k = this._drag ? 0.32 : 0.11;
        this._sx += (this._stx - this._sx) * (motion ? k : 1);
        if (Math.abs(this._stx - this._sx) < 0.05) this._sx = this._stx;
        const lag = this._stx - this._sx;
        const sk = motion ? Math.max(-4, Math.min(4, lag * -0.02)) : 0;
        b.t.style.transform =
            'translate3d(' + (-this._sx).toFixed(2) + 'px,0,0)';
        const cw = b.cards[0].offsetWidth || 1;
        b.cards.forEach((c, i) => {
            const off =
                (b.cards[i].offsetLeft - b.cards[0].offsetLeft - this._sx) / cw;
            const dist = Math.min(1.6, Math.abs(off));
            if (motion) {
                const sc = 1 - Math.min(1, dist) * 0.1;
                const ry = Math.max(-1, Math.min(1, off)) * -7;
                c.style.transform =
                    'perspective(1400px) rotateY(' +
                    ry.toFixed(2) +
                    'deg) skewX(' +
                    sk.toFixed(2) +
                    'deg) scale(' +
                    sc.toFixed(4) +
                    ')';
                c.style.filter =
                    'saturate(' +
                    (1 - Math.min(1, dist) * 0.35).toFixed(3) +
                    ')';
                c.style.opacity = (
                    1 -
                    Math.min(1, Math.max(0, dist - 0.6)) * 0.35
                ).toFixed(3);
                const img = c.firstElementChild;
                if (img)
                    img.style.transform =
                        'scale(1.14) translateX(' +
                        (Math.max(-1.5, Math.min(1.5, off)) * -5).toFixed(2) +
                        '%)';
            } else {
                c.style.transform = '';
                c.style.filter = '';
                c.style.opacity = '';
            }
        });
        const N = b.cards.length;
        const act = this._sx >= b.max - 2 ? N - 1 : this.nearest(this._sx);
        if (act !== this._sActive) {
            this._sActive = act;
            if (this.countRef.current) {
                this.countRef.current.textContent =
                    String(act + 1).padStart(2, '0') +
                    ' / ' +
                    String(N).padStart(2, '0');
                if (motion && this.countRef.current.animate)
                    this.countRef.current.animate(
                        [
                            { opacity: 0, translate: '0 8px' },
                            { opacity: 1, translate: '0 0' },
                        ],
                        { duration: 340, easing: 'cubic-bezier(.2,.8,.2,1)' },
                    );
            }
        }
        if (this.slideBarRef.current)
            this.slideBarRef.current.style.transform =
                'scaleX(' +
                (b.max
                    ? Math.max(0.1, Math.min(1, this._sx / b.max) * 0.9 + 0.1)
                    : 1
                ).toFixed(4) +
                ')';
    }

    scroller() {
        const de = document.scrollingElement || document.documentElement;
        if (de.scrollHeight > de.clientHeight + 4) return de;
        return document.body.scrollHeight > document.body.clientHeight + 4
            ? document.body
            : de;
    }
    jumpTo(top) {
        this.scroller().scrollTop = Math.max(0, Math.round(top));
    }
    docTop(el) {
        return (
            el.getBoundingClientRect().top + (this.scroller().scrollTop || 0)
        );
    }
    nav = (id) => (e) => {
        if (e) e.preventDefault();
        if (id === 'board') track('menu_click');
        const el = document.getElementById(id);
        if (el) this.jumpTo(this.docTop(el) - 60);
    };
    pickUlam = (i) => () => {
        const s = this.peakRef.current;
        if (!s) return;
        this.jumpTo(
            this.docTop(s) -
                60 +
                (s.offsetHeight - window.innerHeight) *
                    ((i + 0.5) / ULAM.length),
        );
    };
    motionOn() {
        return !this.reduced && this.props.motion !== false;
    }
    applyMobile(on) {
        const root = this.rootRef.current;
        if (!root) return;
        root.querySelectorAll('[data-mobile]').forEach((el) => {
            if (!el._mob)
                el._mob = el
                    .getAttribute('data-mobile')
                    .split(';')
                    .filter(Boolean)
                    .map((d) => {
                        const k = d.slice(0, d.indexOf(':')).trim();
                        return [
                            k,
                            d.slice(d.indexOf(':') + 1).trim(),
                            el.style.getPropertyValue(k),
                        ];
                    });
            el._mob.forEach(([k, v, orig]) =>
                el.style.setProperty(k, on ? v : orig),
            );
        });
    }
    responsive = () => {
        this.applyMobile(window.innerWidth < 700);
        if (window.innerWidth < 700 !== this.state.narrow)
            this.setState({ narrow: window.innerWidth < 700 });
        const vw = window.innerWidth,
            n = this.deskNavRef.current;
        if (n) n.style.display = vw >= 900 ? 'flex' : 'none';
        const hr = this.heroRowRef.current,
            ht = this.heroTextRef.current,
            hm = this.heroMediaRef.current,
            hi = this.heroImgRef.current,
            side = vw >= 700;
        if (hr) {
            hr.style.flexWrap = side ? 'nowrap' : 'wrap';
            hr.style.alignContent = side ? 'center' : 'flex-start';
            hr.style.flex = side ? '1 1 auto' : '0 0 auto';
        }
        if (ht) ht.style.flex = side ? '1 1 0' : '1 1 420px';
        if (hm) hm.style.flex = side ? '0 1 47%' : '1 1 400px';
        if (this.wmRef.current)
            this.wmRef.current.style.marginTop = side ? '-.42em' : '-.22em';
        if (hi)
            hi.style.maxHeight = side
                ? 'max(220px, calc(100svh - 440px))'
                : 'clamp(130px, calc(100svh - 530px), 34svh)';
        if (this.heroRef.current)
            this.heroRef.current.style.minHeight = side
                ? 'calc(100svh - 60px)'
                : '0';
        const row = this.peakRowRef.current,
            img = this.peakImgRef.current,
            narrow = vw < 700;
        if (row) {
            row.style.flexDirection = narrow ? 'column-reverse' : 'row';
            row.style.alignItems = narrow ? 'stretch' : 'center';
            row.style.gap = narrow ? '18px' : 'clamp(16px,4vw,64px)';
        }
        if (img) {
            img.style.flex = narrow ? '0 0 auto' : '1 1 40%';
            img.style.width = narrow ? '100%' : 'auto';
            img.style.maxHeight = narrow ? '38svh' : '46svh';
            img.style.aspectRatio = '16/9';
        }
    };

    onVisibility = () => {
        cancelAnimationFrame(this._raf);
        if (!document.hidden) this.loop();
    };
    loop = () => {
        if (document.hidden) return;
        this._raf = requestAnimationFrame(this.loop);
        this.frame();
    };

    frame() {
        const vh = window.innerHeight,
            motion = this.motionOn();
        const sc = this.scroller(),
            y = sc.scrollTop || 0,
            now = performance.now();
        const dy = this._lastY == null ? 0 : y - this._lastY;
        this._lastY = y;
        this._vel = (this._vel || 0) * 0.86 + dy * 0.14;
        const vel = motion ? Math.max(-60, Math.min(60, this._vel)) : 0;
        if (Math.abs(dy) > 0.5) {
            this._still = now;
            if (this.state.dwell) this.setState({ dwell: false });
        }

        const max = sc.scrollHeight - sc.clientHeight;
        if (this.progRef.current)
            this.progRef.current.style.transform =
                'scaleX(' + (max > 0 ? (y / max).toFixed(4) : 0) + ')';

        const hero = this.heroRef.current;
        if (hero && motion) {
            const t = -hero.getBoundingClientRect().top;
            if (t > -vh && t < vh * 1.4) {
                this._smx =
                    (this._smx || 0) +
                    ((this._mx || 0) - (this._smx || 0)) * 0.08;
                this._smy =
                    (this._smy || 0) +
                    ((this._my || 0) - (this._smy || 0)) * 0.08;
                this._planes.forEach((el) => {
                    const d = parseFloat(el.getAttribute('data-depth'));
                    el.style.transform =
                        'translate3d(' +
                        (this._smx * d * 40).toFixed(1) +
                        'px,' +
                        (t * d + this._smy * d * 24).toFixed(1) +
                        'px,0)';
                });
            }
        }

        if (this._words.length) {
            const r = this.wordsRef.current.getBoundingClientRect();
            const p = motion ? (vh * 0.8 - r.top) / (r.height + vh * 0.25) : 1,
                n = this._words.length;
            this._words.forEach((w, i) => {
                w.style.color =
                    'rgba(18,17,16,' +
                    (0.14 + 0.86 * clamp(p * (n + 2) - i)).toFixed(3) +
                    ')';
            });
        }

        const mq = this.marqRef.current;
        if (mq) {
            const r = mq.getBoundingClientRect();
            if (r.bottom > 0 && r.top < vh) {
                this._mOff =
                    (this._mOff || 0) +
                    (motion && !this._marqueePaused
                        ? 0.7 + Math.abs(vel) * 0.35
                        : 0);
                const rows = mq.querySelectorAll('[data-row]');
                rows.forEach((row, i) => {
                    const half = row.scrollWidth / 2 || 1,
                        o = this._mOff % half;
                    row.style.transform =
                        'translate3d(' +
                        (i ? -(half - o) : -o).toFixed(1) +
                        'px,0,0) skewX(' +
                        (vel * -0.12).toFixed(2) +
                        'deg)';
                });
            }
        }

        const pk = this.peakRef.current;
        if (pk) {
            const r = pk.getBoundingClientRect();
            const p = clamp((60 - r.top) / (r.height - vh));
            const N = ULAM.length,
                idx = Math.min(N - 1, Math.floor(p * N)),
                local = clamp(p * N - idx);
            if (idx !== this.state.idx) {
                this.changeUlam(this.state.idx, idx);
                this.setState({ idx });
            }
            if (this.peakBarRef.current)
                this.peakBarRef.current.style.transform =
                    'scaleX(' + p.toFixed(4) + ')';
            if (this.plateWrapRef.current && motion)
                this.plateWrapRef.current.style.transform =
                    'scale(' + (1 + local * 0.015).toFixed(4) + ')';
            const pinned = r.top <= 62 && r.bottom >= vh - 2;
            if (
                pinned &&
                motion &&
                !this.state.dwell &&
                now - (this._still || 0) > 900
            )
                this.setState({ dwell: true });
        }

        this.slideFrame(motion);

        const lm = this.lemonRef.current;
        if (lm) {
            if (!this._cups || !this._cups.length)
                this._cups = Array.from(lm.querySelectorAll('[data-cup]'));
            const r = lm.getBoundingClientRect();
            if (r.top < vh * 1.2 && r.bottom > -vh * 0.2) {
                const p = motion ? clamp((vh - r.top) / (vh * 0.9)) : 1;
                this._cups.forEach((c, i) => {
                    const l = clamp(p * 1.7 - i * 0.18);
                    c.style.clipPath =
                        'inset(' +
                        ((1 - l) * 100).toFixed(1) +
                        '% 0 0 0 round 18px)';
                    c.style.transform =
                        'translateY(' + ((1 - l) * 30).toFixed(1) + 'px)';
                });
            }
        }

        const cm = this.comeRef.current;
        if (cm) {
            const r = cm.getBoundingClientRect();
            const p = motion ? clamp((vh - r.top) / (vh * 0.7)) : 1;
            cm.style.letterSpacing = (0.06 - 0.095 * p).toFixed(4) + 'em';
            cm.style.opacity = (0.15 + 0.85 * p).toFixed(3);
        }
    }

    onMove = (e) => {
        this._mx = e.clientX / window.innerWidth - 0.5;
        this._my = e.clientY / window.innerHeight - 0.5;
        if (!this.motionOn()) return;
        this._magnets.forEach((el) => {
            const b = el.getBoundingClientRect();
            const dx = e.clientX - (b.left + b.width / 2),
                dy = e.clientY - (b.top + b.height / 2);
            const near = Math.hypot(dx, dy) < Math.max(b.width, b.height) * 0.9;
            el.style.translate = near
                ? (dx * 0.22).toFixed(1) + 'px ' + (dy * 0.3).toFixed(1) + 'px'
                : '0 0';
        });
    };

    enter() {
        const ease = 'cubic-bezier(.2,.8,.2,1)';
        Array.from(this.rootRef.current.querySelectorAll('[data-enter]'))
            .sort(
                (a, b) =>
                    a.getAttribute('data-enter') - b.getAttribute('data-enter'),
            )
            .forEach((el) => {
                const i = +el.getAttribute('data-enter');
                el.animate(
                    [
                        { opacity: 0, translate: '0 28px' },
                        { opacity: 1, translate: '0 0' },
                    ],
                    {
                        duration: 900,
                        delay: 80 + i * 90,
                        easing: ease,
                        fill: 'backwards',
                    },
                );
            });
        const vh = window.innerHeight;
        const els = Array.from(
            this.rootRef.current.querySelectorAll('[data-reveal]'),
        ).filter((el) => el.getBoundingClientRect().top > vh);
        if (!els.length || !('IntersectionObserver' in window)) return;
        els.forEach((el) => {
            el.style.opacity = '0';
        });
        this._io = new IntersectionObserver(
            (entries) =>
                entries.forEach((en) => {
                    if (!en.isIntersecting) return;
                    const el = en.target;
                    this._io.unobserve(el);
                    el.style.opacity = '';
                    el.animate(
                        [
                            { opacity: 0, translate: '0 32px' },
                            { opacity: 1, translate: '0 0' },
                        ],
                        { duration: 800, easing: ease, fill: 'backwards' },
                    );
                }),
            { rootMargin: '0px 0px -8% 0px' },
        );
        els.forEach((el) => this._io.observe(el));
    }

    initMap = () => {
        const el = this.mapRef.current;
        if (!el || this._map) return;
        const L = this._leaflet;
        if (!L) {
            if ((this._mapTries = (this._mapTries || 0) + 1) < 100)
                this._mapT = setTimeout(this.initMap, 100);
            return;
        }
        const motion = this.motionOn();
        /*
         * A touch screen starts locked, so a finger scrolling the page over the map is never captured. One tap unlocks
         * panning and pinch-zoom; touching anywhere outside locks it again. Zoom buttons and Recenter always work.
         */
        const touch =
            L.Browser.mobile ||
            Boolean(window.matchMedia?.('(pointer: coarse)').matches);
        const map = L.map(el, {
            scrollWheelZoom: false,
            dragging: !touch,
            touchZoom: !touch,
            tap: false,
            zoomControl: false,
            attributionControl: true,
        }).setView(SHOP, motion ? 14 : 17);
        L.control.zoom({ position: 'bottomright' }).addTo(map);
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution:
                '© <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors',
        }).addTo(map);
        const icon = L.divIcon({
            className: '',
            iconSize: [28, 28],
            iconAnchor: [14, 14],
            html: '<div style="position:relative;width:28px;height:28px"><span style="position:absolute;inset:0;border-radius:50%;background:#8A5E12;animation:v3pulse 2s cubic-bezier(.2,.8,.2,1) infinite"></span><span style="position:absolute;inset:3px;border-radius:50%;background:#121110;border:4px solid #FFFFFF;box-shadow:0 6px 16px rgba(18,17,16,.35)"></span></div>',
        });
        L.marker(SHOP, {
            icon,
            title: 'PONGSKILOG',
            alt: 'PONGSKILOG location',
        }).addTo(map);
        this._map = map;
        if (touch) this.setState({ mapLocked: true });
        if (motion && 'IntersectionObserver' in window) {
            this._mio = new IntersectionObserver(
                (en) => {
                    if (en[0].isIntersecting) {
                        this._mio.disconnect();
                        map.flyTo(SHOP, 17, { duration: 2.2 });
                    }
                },
                { threshold: 0.45 },
            );
            this._mio.observe(el);
        }
    };
    recenter = () => {
        if (this._map)
            this._map.flyTo(SHOP, 17, { duration: this.motionOn() ? 1 : 0 });
    };
    unlockMap = () => {
        if (!this._map || this.state.mapLocked !== true) return;
        this._map.dragging.enable();
        this._map.touchZoom.enable();
        this.setState({ mapLocked: false });
    };
    lockMap = () => {
        if (!this._map || this.state.mapLocked !== false) return;
        this._map.dragging.disable();
        this._map.touchZoom.disable();
        this.setState({ mapLocked: true });
    };

    infoMotion() {
        const box = this.infoRef.current;
        if (!box || !this.motionOn() || !('IntersectionObserver' in window))
            return;
        const ease = 'cubic-bezier(.2,.8,.2,1)';
        const items = Array.from(box.querySelectorAll('[data-info]'));
        const lines = Array.from(box.querySelectorAll('[data-line]'));
        const rows = Array.from(box.querySelectorAll('[data-row-in]'));
        if (box.getBoundingClientRect().top < window.innerHeight) return;
        items.forEach((el) => {
            el.style.opacity = '0';
        });
        lines.forEach((el) => {
            el.style.transform = 'scaleX(0)';
        });
        rows.forEach((el) => {
            el.style.opacity = '0';
        });
        this._iio = new IntersectionObserver(
            (en) => {
                if (!en[0].isIntersecting) return;
                this._iio.disconnect();
                items.forEach((el, i) => {
                    el.style.opacity = '';
                    el.animate(
                        [
                            {
                                opacity: 0,
                                translate: '0 44px',
                                clipPath: 'inset(0 0 100% 0 round 18px)',
                            },
                            {
                                opacity: 1,
                                translate: '0 0',
                                clipPath: 'inset(0 0 0% 0 round 18px)',
                            },
                        ],
                        {
                            duration: 900,
                            delay: i * 110,
                            easing: ease,
                            fill: 'backwards',
                        },
                    );
                });
                lines.forEach((el, i) => {
                    el.animate(
                        [
                            { transform: 'scaleX(0)' },
                            { transform: 'scaleX(1)' },
                        ],
                        {
                            duration: 900,
                            delay: 420 + i * 110,
                            easing: ease,
                            fill: 'forwards',
                        },
                    ).onfinish = () => {
                        el.style.transform = '';
                    };
                });
                rows.forEach((el, i) => {
                    el.style.opacity = '';
                    el.animate(
                        [
                            { opacity: 0, translate: '-16px 0' },
                            { opacity: 1, translate: '0 0' },
                        ],
                        {
                            duration: 600,
                            delay: 640 + i * 90,
                            easing: ease,
                            fill: 'backwards',
                        },
                    );
                });
            },
            { threshold: 0.12 },
        );
        this._iio.observe(box);
    }

    plateStyle(i) {
        const idx = this.state.idx;
        return (
            'position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:clip-path .95s cubic-bezier(.65,0,.25,1),transform 1.3s cubic-bezier(.2,.8,.2,1),filter .9s cubic-bezier(.2,.8,.2,1);' +
            (i === idx
                ? 'clip-path:circle(80% at 50% 50%);transform:rotate(0deg) scale(1);filter:brightness(1);z-index:2'
                : i < idx
                  ? 'clip-path:circle(80% at 50% 50%);transform:rotate(3deg) scale(1.03);filter:brightness(.82);z-index:1'
                  : 'clip-path:circle(0% at 50% 50%);transform:rotate(-18deg) scale(1.25);filter:brightness(1);z-index:3')
        );
    }

    setPrice(v) {
        if (this.priceRef.current)
            this.priceRef.current.textContent = '₱' + Math.round(v);
    }

    componentDidMount() {
        this._unmounted = false;
        this.reduced =
            window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        const root = this.rootRef.current;
        this._planes = root
            ? Array.from(root.querySelectorAll('[data-depth]'))
            : [];
        this._magnets = root
            ? Array.from(root.querySelectorAll('[data-magnet]'))
            : [];
        this._words = this.wordsRef.current
            ? Array.from(this.wordsRef.current.querySelectorAll('[data-w]'))
            : [];
        this._words.forEach((w) => {
            w.style.transition = 'color .3s';
        });
        this.setPrice(ULAM[this.state.idx].price);
        this.responsive();
        window.addEventListener('resize', this.responsive);
        if (window.matchMedia('(pointer: fine)').matches)
            window.addEventListener('pointermove', this.onMove, {
                passive: true,
            });
        if (this.motionOn() && root) this.enter();
        this.loop();
        document.addEventListener('visibilitychange', this.onVisibility);
        document.addEventListener('pointerdown', this.onDocDown);
        document.addEventListener('keydown', this.onEsc);
        if (this.viewRef.current)
            this.viewRef.current.addEventListener('wheel', this.onWheelH, {
                passive: false,
            });
        this.observeMap();
        this.infoMotion();
        track('page_view');
    }
    changeUlam(prev, next) {
        const from = ULAM[prev].price,
            to = ULAM[next].price;
        cancelAnimationFrame(this._pr);
        if (!this.motionOn()) {
            this.setPrice(to);
            return;
        }
        const dir = next > prev ? 1 : -1;
        requestAnimationFrame(() =>
            requestAnimationFrame(() => {
                const pre = this.prefixRef.current,
                    line = this.lineRef.current;
                if (pre)
                    Array.from(pre.children).forEach((el, i) => {
                        if (el.animate)
                            el.animate(
                                [
                                    {
                                        opacity: 0,
                                        translate: '0 ' + dir * 0.7 + 'em',
                                        rotate: dir * -14 + 'deg',
                                        scale: '1 .6',
                                    },
                                    {
                                        opacity: 1,
                                        translate: '0 0',
                                        rotate: '0deg',
                                        scale: '1 1',
                                    },
                                ],
                                {
                                    duration: 640,
                                    delay: i * 55,
                                    easing: 'cubic-bezier(.34,1.45,.64,1)',
                                    fill: 'backwards',
                                },
                            );
                    });
                if (line && line.animate)
                    line.animate(
                        [
                            { opacity: 0, translate: '0 ' + dir * 110 + '%' },
                            { opacity: 1, translate: '0 0' },
                        ],
                        { duration: 520, easing: 'cubic-bezier(.2,.8,.2,1)' },
                    );
            }),
        );
        const t0 = performance.now();
        const step = (t) => {
            const k = clamp((t - t0) / 450),
                e = 1 - Math.pow(1 - k, 3);
            this.setPrice(from + (to - from) * e);
            if (k < 1) this._pr = requestAnimationFrame(step);
            else this.setPrice(to);
        };
        this._pr = requestAnimationFrame(step);
    }
    componentDidUpdate() {
        if (
            this.state.profOpen &&
            !this._lastProf &&
            this.profPopRef.current &&
            this.profPopRef.current.animate &&
            this.motionOn()
        )
            this.profPopRef.current.animate(
                [
                    { opacity: 0, transform: 'translateY(-6px) scale(.96)' },
                    { opacity: 1, transform: 'none' },
                ],
                { duration: 260, easing: 'cubic-bezier(.2,.8,.2,1)' },
            );
        this._lastProf = this.state.profOpen;
        if (this.state.narrow) this.applyMobile(true);
        if (this._lastBoardCat !== this.state.boardCat) {
            this._lastBoardCat = this.state.boardCat;
            this._stx = 0;
            this._sx = 0;
            this._sActive = -1;
            this.applyMobile(window.innerWidth < 700);
            const t = this.trackRef.current;
            if (t && this.motionOn())
                Array.from(t.querySelectorAll('[data-card]'))
                    .slice(0, 5)
                    .forEach((c, i) => {
                        if (c.animate)
                            c.animate(
                                [
                                    { opacity: 0, translate: '60px 0' },
                                    { opacity: 1, translate: '0 0' },
                                ],
                                {
                                    duration: 620,
                                    delay: i * 70,
                                    easing: 'cubic-bezier(.2,.8,.2,1)',
                                    fill: 'backwards',
                                },
                            );
                    });
        }
    }
    componentWillUnmount() {
        document.removeEventListener('visibilitychange', this.onVisibility);
        document.removeEventListener('pointerdown', this.onDocDown);
        document.removeEventListener('keydown', this.onEsc);
        window.removeEventListener('resize', this.responsive);
        window.removeEventListener('pointermove', this.onMove);
        cancelAnimationFrame(this._raf);
        cancelAnimationFrame(this._pr);
        if (this._io) this._io.disconnect();
        if (this._mio) this._mio.disconnect();
        if (this._iio) this._iio.disconnect();
        this._unmounted = true;
        this._mapLoader?.disconnect();
        if (this.viewRef.current)
            this.viewRef.current.removeEventListener('wheel', this.onWheelH);
        clearTimeout(this._wt);
        clearTimeout(this._mapT);
        if (this._map) {
            this._map.remove();
            this._map = null;
        }
    }

    renderVals() {
        const idx = this.state.idx,
            u = ULAM[idx];
        const cat = MENU[this.state.cat] ? this.state.cat : 'silog';
        return {
            rootRef: this.rootRef,
            heroRef: this.heroRef,
            wordsRef: this.wordsRef,
            peakRef: this.peakRef,
            panRef: this.panRef,
            trackRef: this.trackRef,
            viewRef: this.viewRef,
            slideBarRef: this.slideBarRef,
            slidePrev: this.slidePrev,
            slideNext: this.slideNext,
            slideKey: this.slideKey,
            dragStart: this.dragStart,
            dragMove: this.dragMove,
            dragEnd: this.dragEnd,
            lemonRef: this.lemonRef,
            deskNavRef: this.deskNavRef,
            progRef: this.progRef,
            marqRef: this.marqRef,
            prefixRef: this.prefixRef,
            priceRef: this.priceRef,
            peakBarRef: this.peakBarRef,
            imgsReady: true,
            lineRef: this.lineRef,
            preLetters: u.pre.split(''),
            plate0Style: this.plateStyle(0),
            plate1Style: this.plateStyle(1),
            plate2Style: this.plateStyle(2),
            plate3Style: this.plateStyle(3),
            plate4Style: this.plateStyle(4),
            heroRowRef: this.heroRowRef,
            wmRef: this.wmRef,
            heroTextRef: this.heroTextRef,
            heroMediaRef: this.heroMediaRef,
            heroImgRef: this.heroImgRef,
            peakRowRef: this.peakRowRef,
            peakImgRef: this.peakImgRef,
            plateWrapRef: this.plateWrapRef,
            countRef: this.countRef,
            comeRef: this.comeRef,
            mapRef: this.mapRef,
            recenter: this.recenter,
            status: openStatus(),
            goTop: (e) => {
                if (e) e.preventDefault();
                this.jumpTo(0);
            },
            goName: this.nav('name'),
            goMenu: this.nav('board'),
            goBoard: this.nav('board'),
            goLemon: this.nav('lemonade'),
            goVisit: this.nav('visit'),
            ulam: u,
            dwellOp: this.state.dwell ? 1 : 0,
            dwellY: this.state.dwell ? 0 : 10,
            showMarquee: this.props.showMarquee !== false,
            marqueeA: MARQ_A.concat(MARQ_A),
            marqueeB: MARQ_B.concat(MARQ_B),
            chips: ULAM.map((x, i) => ({
                label: x.pre.charAt(0) + x.pre.slice(1).toLowerCase() + 'silog',
                on: i === idx,
                onPick: this.pickUlam(i),
                style: PILL + (i === idx ? ON : OFF),
            })),
            plates: ULAM.map((x, i) => ({
                img: x.img,
                alt: x.alt,
                style:
                    'position:absolute;inset:0;width:100%;height:100%;object-fit:cover;transition:clip-path .7s cubic-bezier(.2,.8,.2,1),transform 1.1s cubic-bezier(.2,.8,.2,1);' +
                    (i === idx
                        ? 'clip-path:inset(0 0 0 0);transform:scale(1);z-index:2'
                        : i < idx
                          ? 'clip-path:inset(0 0 0 0);transform:scale(1.04);z-index:1'
                          : 'clip-path:inset(100% 0 0 0);transform:scale(1.08);z-index:3'),
            })),
            cards: CARDS.filter(
                (c) =>
                    this.state.boardCat === 'all' ||
                    c.cat === this.state.boardCat,
            ).map((c, i) => ({
                img: RES(c.img),
                alt: c.alt,
                onPick: this.pickCard(i),
            })),
            cups: CUPS.map((c) => Object.assign({}, c, { img: RES(c.img) })),
            profRef: this.profRef,
            profPopRef: this.profPopRef,
            profOpen: this.state.profOpen,
            toggleProf: this.toggleProf,
            staffLogin: this.staffLogin,
            chipRowRef: this.chipRowRef,
            boardChips: (this.state.menuLens === 'prices'
                ? [['all', 'All'], ...CAT_ORDER.map((k) => [k, MENU[k].label])]
                : BOARD_CATS
            ).map(([k, l]) => ({
                label: l,
                n:
                    this.state.menuLens === 'prices'
                        ? k === 'all'
                            ? CAT_ORDER.reduce(
                                  (total, key) =>
                                      total + MENU[key].items.length,
                                  0,
                              )
                            : MENU[k].items.length
                        : k === 'all'
                          ? CARDS.length
                          : CARDS.filter((c) => c.cat === k).length,
                on: k === this.state.boardCat,
                onPick: this.pickBoardCat(k),
                style: PILL + (k === this.state.boardCat ? ON : OFF),
            })),

            tabs: CAT_ORDER.map((k) => ({
                label: MENU[k].label,
                on: k === cat,
                onSelect: () => this.setState({ cat: k }),
                style: PILL + (k === cat ? ON : OFF),
            })),
            catNote: MENU[cat].note,
            items: MENU[cat].items.map(([n, p]) => ({ n, p })),
            hours: HOURS.map((x) => ({
                day:
                    x.day +
                    (x.days.indexOf(this._today) >= 0 ? ' (today)' : ''),
                time: x.time,
                note: x.note,
                fg: x.fg,
                bg:
                    x.days.indexOf(this._today) >= 0
                        ? '#F4F0E8'
                        : 'transparent',
            })),
            infoRef: this.infoRef,
        };
    }

    observeMap() {
        const load = async () => {
            this._mapLoader?.disconnect();
            try {
                const leaflet = await loadLeaflet();
                if (this._unmounted) return;
                this._leaflet = leaflet;
                this.initMap();
            } catch {
                /* The address and directions link remain available offline. */
            }
        };
        if (!('IntersectionObserver' in window)) {
            void load();
            return;
        }
        this._mapLoader = new IntersectionObserver(
            (entries) => {
                if (entries.some((entry) => entry.isIntersecting)) void load();
            },
            { rootMargin: '600px' },
        );
        if (this.mapRef.current) this._mapLoader.observe(this.mapRef.current);
    }
    render() {
        const {
            rootRef,
            heroRef,
            wordsRef,
            peakRef,
            panRef,
            trackRef,
            viewRef,
            slideBarRef,
            slidePrev,
            slideNext,
            slideKey,
            dragStart,
            dragMove,
            dragEnd,
            lemonRef,
            deskNavRef,
            progRef,
            marqRef,
            prefixRef,
            priceRef,
            peakBarRef,
            imgsReady,
            lineRef,
            preLetters,
            plate0Style,
            plate1Style,
            plate2Style,
            plate3Style,
            plate4Style,
            heroRowRef,
            wmRef,
            heroTextRef,
            heroMediaRef,
            heroImgRef,
            peakRowRef,
            peakImgRef,
            plateWrapRef,
            countRef,
            comeRef,
            mapRef,
            recenter,
            status,
            goTop,
            goName,
            goMenu,
            goBoard,
            goLemon,
            goVisit,
            ulam,
            dwellOp,
            dwellY,
            showMarquee,
            marqueeA,
            marqueeB,
            chips,
            cards,
            cups,
            profRef,
            profPopRef,
            profOpen,
            toggleProf,
            staffLogin,
            chipRowRef,
            boardChips,
            hours,
            infoRef,
        } = this.renderVals();
        const { loginHref, loginLabel } = this.props;
        return (
            <div
                ref={rootRef}
                data-part="rootRef"
                id="top"
                className="landing-page landing-v3 v3-s1"
                onClick={this.trackLink}
            >
                <header className="v3-s2">
                    <div
                        ref={progRef}
                        data-part="progRef"
                        aria-hidden="true"
                        className="v3-s3"
                    ></div>
                    <nav aria-label="Primary" className="v3-s4">
                        <a
                            href="#top"
                            onClick={goTop}
                            aria-label="PONGSKILOG home"
                            className="v3-s5"
                        >
                            <img
                                src="/images/landing-v121-3f29c90a.webp"
                                alt="PONGSKILOG"
                                width="400"
                                height="200"
                                className="v3-s6"
                                loading="lazy"
                                decoding="async"
                            />
                        </a>
                        <div className="v3-s7"></div>
                        <div
                            ref={deskNavRef}
                            data-part="deskNavRef"
                            className="v3-s8"
                        >
                            <a
                                href="#name"
                                onClick={goName}
                                className="v3-s9 v3-s10"
                            >
                                Silog
                            </a>
                            <a
                                href="#board"
                                onClick={goBoard}
                                className="v3-s11 v3-s12"
                            >
                                Menu
                            </a>
                            <a
                                href="#lemonade"
                                onClick={goLemon}
                                className="v3-s13 v3-s14"
                            >
                                Lemonade
                            </a>
                            <a
                                href="#visit"
                                onClick={goVisit}
                                className="v3-s15 v3-s16"
                            >
                                Visit
                            </a>
                        </div>
                        <div className="v3-s17">
                            <a
                                href="https://www.facebook.com/profile.php?id=100094575655003"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="Message us on Facebook"
                                title="Message on Facebook"
                                className="v3-s18 v3-s19"
                            >
                                <svg
                                    width="22"
                                    height="22"
                                    viewBox="0 0 24 24"
                                    fill="currentColor"
                                    aria-hidden="true"
                                >
                                    <path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.19 5.44 3.14 7.17.16.14.26.35.27.57l.05 1.78c.02.57.6.94 1.12.71l1.98-.87c.17-.08.36-.09.53-.04.91.25 1.88.38 2.91.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2zm6 7.46l-2.94 4.66c-.47.74-1.47.93-2.17.4l-2.34-1.75a.6.6 0 0 0-.72 0l-3.16 2.4c-.42.32-.97-.18-.69-.63l2.94-4.66c.47-.74 1.47-.93 2.17-.4l2.34 1.75a.6.6 0 0 0 .72 0l3.16-2.4c.42-.32.97.18.69.63z"></path>
                                </svg>
                            </a>
                            <a
                                href="https://maps.app.goo.gl/nivpx3cxuP56wAXo8"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="Open in Google Maps"
                                title="Get directions"
                                className="v3-s20 v3-s21"
                            >
                                <svg
                                    width="21"
                                    height="21"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path>
                                    <circle cx="12" cy="10" r="3"></circle>
                                </svg>
                            </a>
                            <a
                                href="#board"
                                onClick={goMenu}
                                aria-label="The rest of the menu"
                                title="Menu"
                                className="v3-s22 v3-s23"
                            >
                                <svg
                                    width="21"
                                    height="21"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2"></path>
                                    <path d="M7 2v20"></path>
                                    <path d="M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3zm0 0v7"></path>
                                </svg>
                            </a>
                            <a
                                href="#reviews"
                                onClick={this.nav('reviews')}
                                aria-label="Reviews"
                                title="Reviews"
                                className="v3-s22 v3-s23"
                            >
                                <svg
                                    width="19"
                                    height="19"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="1.9"
                                    aria-hidden="true"
                                >
                                    <path d="M21 15a4 4 0 0 1-4 4H7l-4 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z" />
                                    <path d="M8 8h8M8 12h6" />
                                </svg>
                            </a>
                            <div
                                ref={profRef}
                                data-part="profRef"
                                className="v3-s24"
                            >
                                <button
                                    type="button"
                                    onClick={toggleProf}
                                    aria-label="Staff profile"
                                    aria-haspopup="dialog"
                                    aria-expanded={profOpen}
                                    className="v3-s25 v3-s26"
                                >
                                    <svg
                                        width="22"
                                        height="22"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="1.9"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        aria-hidden="true"
                                    >
                                        <circle
                                            cx="12"
                                            cy="8.5"
                                            r="3.6"
                                        ></circle>
                                        <path d="M5 20c1.2-3.6 4-5.4 7-5.4s5.8 1.8 7 5.4"></path>
                                    </svg>
                                </button>
                                {profOpen && (
                                    <>
                                        <div
                                            ref={profPopRef}
                                            data-part="profPopRef"
                                            role="dialog"
                                            aria-label="Staff login"
                                            className="v3-s27"
                                        >
                                            <div className="v3-s28">
                                                <span className="v3-s29">
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
                                                        <circle
                                                            cx="12"
                                                            cy="8.5"
                                                            r="3.6"
                                                        ></circle>
                                                        <path d="M5 20c1.2-3.6 4-5.4 7-5.4s5.8 1.8 7 5.4"></path>
                                                    </svg>
                                                </span>
                                                <span className="v3-s30">
                                                    Pongskilog staff
                                                </span>
                                            </div>
                                            <Link
                                                href={loginHref}
                                                onClick={staffLogin}
                                                className="v3-s31 v3-s32"
                                            >
                                                {loginLabel}
                                            </Link>
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>
                    </nav>
                </header>

                <main>
                    <section
                        aria-label="Intro"
                        ref={heroRef}
                        data-part="heroRef"
                        className="v3-s33"
                    >
                        <div
                            ref={heroRowRef}
                            data-part="heroRowRef"
                            className="v3-s34"
                        >
                            <div
                                data-depth="0.08"
                                ref={heroTextRef}
                                data-part="heroTextRef"
                                className="v3-s35"
                            >
                                <h1 className="v3-s36">
                                    <span data-enter="1" className="v3-s37">
                                        Your favorite silog.
                                    </span>
                                    <span data-enter="2" className="v3-s38">
                                        PONGSKILOG.
                                    </span>
                                </h1>
                                <p data-enter="4" className="v3-s39">
                                    Serving your favorite Filipino comfort food
                                    na swak sa budget, solid sa sarap, at sulit
                                    sa bawat kagat. Proudly serving Adarna,
                                    Quezon City since 2022.{' '}
                                    <strong className="v3-s40">
                                        Cravings mo, panalo dito!
                                    </strong>
                                </p>
                                <div data-enter="5" className="v3-s41">
                                    <a
                                        href="https://www.facebook.com/profile.php?id=100094575655003"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        data-magnet=""
                                        className="v3-s42 v3-s43"
                                    >
                                        <svg
                                            width="20"
                                            height="20"
                                            viewBox="0 0 24 24"
                                            fill="currentColor"
                                            aria-hidden="true"
                                            className="v3-s44"
                                        >
                                            <path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.19 5.44 3.14 7.17.16.14.26.35.27.57l.05 1.78c.02.57.6.94 1.12.71l1.98-.87c.17-.08.36-.09.53-.04.91.25 1.88.38 2.91.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2zm6 7.46l-2.94 4.66c-.47.74-1.47.93-2.17.4l-2.34-1.75a.6.6 0 0 0-.72 0l-3.16 2.4c-.42.32-.97-.18-.69-.63l2.94-4.66c.47-.74 1.47-.93 2.17-.4l2.34 1.75a.6.6 0 0 0 .72 0l3.16-2.4c.42-.32.97.18.69.63z"></path>
                                        </svg>
                                        <span>Message on Facebook</span>
                                        <span
                                            aria-hidden="true"
                                            className="v3-s45"
                                        >
                                            <svg
                                                width="16"
                                                height="16"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="2.4"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                            >
                                                <path d="M7 17L17 7M8 7h9v9"></path>
                                            </svg>
                                        </span>
                                    </a>
                                    <button
                                        type="button"
                                        onClick={goBoard}
                                        className="v3-s46 v3-s47"
                                    >
                                        <span>See the menu</span>
                                        <svg
                                            width="16"
                                            height="16"
                                            viewBox="0 0 24 24"
                                            fill="none"
                                            stroke="currentColor"
                                            strokeWidth="2.2"
                                            strokeLinecap="round"
                                            strokeLinejoin="round"
                                            aria-hidden="true"
                                            className="v3-s48"
                                        >
                                            <path d="M5 12h14M13 6l6 6-6 6"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                            <div
                                ref={heroMediaRef}
                                data-part="heroMediaRef"
                                className="v3-s49"
                            >
                                <div data-depth="0.16" className="v3-s50">
                                    <img
                                        src="/images/landing-v121-212d9512.webp"
                                        alt="The PONGSKILOG dining area at night"
                                        width="1200"
                                        height="900"
                                        fetchPriority="high"
                                        data-enter="2"
                                        ref={heroImgRef}
                                        data-part="heroImgRef"
                                        className="v3-s51"
                                        loading="eager"
                                        decoding="async"
                                    />
                                </div>
                                <div data-depth="-0.12" className="v3-s52">
                                    <img
                                        src="/images/landing-v121-20b09867.webp"
                                        alt="PONGSKI-LOG logo, a chef tossing garlic rice in a wok, EST 2022"
                                        width="640"
                                        height="640"
                                        data-enter="5"
                                        className="v3-s53"
                                        loading="lazy"
                                        decoding="async"
                                    />
                                </div>
                                <div data-depth="-0.22" className="v3-s54">
                                    <div data-enter="6" className="v3-s55">
                                        <div className="v3-s56">
                                            Serving silog
                                        </div>
                                        <div className="v3-s57">Since 2022</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div
                            ref={wmRef}
                            data-part="wmRef"
                            aria-hidden="true"
                            className="v3-s58"
                        >
                            <div data-depth="0.12" className="v3-s59">
                                PONGSKILOG
                            </div>
                        </div>
                        {showMarquee && (
                            <>
                                <div
                                    ref={marqRef}
                                    data-part="marqRef"
                                    onPointerEnter={this.pauseMarquee}
                                    onPointerLeave={this.resumeMarquee}
                                    onPointerDown={this.pauseMarquee}
                                    onPointerUp={this.resumeMarquee}
                                    onPointerCancel={this.resumeMarquee}
                                    aria-hidden="true"
                                    className="v3-s60"
                                >
                                    <div data-row="" className="v3-s61">
                                        {marqueeA.map((m, index) => (
                                            <Fragment key={index}>
                                                <span className="v3-s62">
                                                    {m}
                                                    <span className="v3-s63"></span>
                                                </span>
                                            </Fragment>
                                        ))}
                                    </div>
                                    <div data-row="" className="v3-s64">
                                        {marqueeB.map((m, index) => (
                                            <Fragment key={index}>
                                                <span className="v3-s65">
                                                    {m}
                                                    <span className="v3-s66"></span>
                                                </span>
                                            </Fragment>
                                        ))}
                                    </div>
                                </div>
                            </>
                        )}
                    </section>

                    <section aria-label="About" className="v3-s67">
                        <div
                            data-mobile="padding:56px 16px;align-items:center"
                            className="v3-s68 v3-s69"
                        >
                            <p
                                ref={wordsRef}
                                data-part="wordsRef"
                                className="v3-s70"
                            >
                                <span data-w="">Sarap</span>{' '}
                                <span data-w="">na</span>{' '}
                                <span data-w="">hinahanap-hanap.</span>{' '}
                                <span data-w="">Busog</span>{' '}
                                <span data-w="">na</span>{' '}
                                <span data-w="">binabalik-balikan.</span>
                            </p>
                        </div>
                    </section>

                    <section
                        data-mobile="height:330vh"
                        id="name"
                        ref={peakRef}
                        data-part="peakRef"
                        aria-label="How a silog gets its name"
                        className="v3-s71 v3-s72"
                    >
                        <div className="v3-s73">
                            <div
                                ref={peakRowRef}
                                data-part="peakRowRef"
                                className="v3-s74"
                            >
                                <div className="v3-s75">
                                    <div className="v3-s76">
                                        How a silog gets its name
                                    </div>
                                    <div className="v3-s77">
                                        <span>
                                            <b className="v3-s78">SI</b>nangag,
                                            garlic rice
                                        </span>
                                        <span>
                                            + it<b className="v3-s79">LOG</b>,
                                            fried egg
                                        </span>
                                        <span className="v3-s80">
                                            <span
                                                ref={lineRef}
                                                data-part="lineRef"
                                                className="v3-s81"
                                            >
                                                +{' '}
                                                <b className="v3-s82">
                                                    {ulam.pre}
                                                </b>
                                                {ulam.rest}
                                            </span>
                                        </span>
                                    </div>
                                    <div aria-live="polite" className="v3-s83">
                                        <span
                                            ref={prefixRef}
                                            data-part="prefixRef"
                                            className="v3-s84"
                                        >
                                            {preLetters.map((l, index) => (
                                                <Fragment key={index}>
                                                    <span className="v3-s85">
                                                        {l}
                                                    </span>
                                                </Fragment>
                                            ))}
                                        </span>
                                        SILOG
                                    </div>
                                    <div className="v3-s86">
                                        <span
                                            ref={priceRef}
                                            data-part="priceRef"
                                            className="v3-s87"
                                        ></span>
                                        <span className="v3-s88">
                                            {ulam.note}
                                        </span>
                                    </div>
                                    <div className="v2-rail v3-s89">
                                        {chips.map((c, index) => (
                                            <Fragment key={index}>
                                                <button
                                                    type="button"
                                                    onClick={c.onPick}
                                                    aria-pressed={c.on}
                                                    style={{
                                                        ...cssStyle(c.style),
                                                    }}
                                                >
                                                    {c.label}
                                                </button>
                                            </Fragment>
                                        ))}
                                    </div>
                                    <div aria-hidden="true" className="v3-s90">
                                        <div
                                            ref={peakBarRef}
                                            data-part="peakBarRef"
                                            className="v3-s91"
                                        ></div>
                                    </div>
                                </div>
                                <div
                                    ref={peakImgRef}
                                    data-part="peakImgRef"
                                    className="v3-s92"
                                >
                                    <div
                                        ref={plateWrapRef}
                                        data-part="plateWrapRef"
                                        className="v3-s93"
                                    >
                                        <img
                                            src="/images/landing-v121-822fd717.webp"
                                            alt="Tapsilog"
                                            width="900"
                                            height="664"
                                            style={{ ...cssStyle(plate0Style) }}
                                            loading="lazy"
                                            decoding="async"
                                        />
                                        <img
                                            src="/images/landing-v121-2be3bb14.webp"
                                            alt="Liempo silog"
                                            width="900"
                                            height="664"
                                            style={{ ...cssStyle(plate1Style) }}
                                            loading="lazy"
                                            decoding="async"
                                        />
                                        <img
                                            src="/images/landing-v121-1bba3869.webp"
                                            alt="Chicken silog"
                                            width="900"
                                            height="664"
                                            style={{ ...cssStyle(plate2Style) }}
                                            loading="lazy"
                                            decoding="async"
                                        />
                                        <img
                                            src="/images/landing-v121-2f7901fa.webp"
                                            alt="Bangus silog"
                                            width="900"
                                            height="664"
                                            style={{ ...cssStyle(plate3Style) }}
                                            loading="lazy"
                                            decoding="async"
                                        />
                                        <img
                                            src="/images/landing-v121-8611ee78.webp"
                                            alt="Pork silog"
                                            width="900"
                                            height="664"
                                            style={{ ...cssStyle(plate4Style) }}
                                            loading="lazy"
                                            decoding="async"
                                        />
                                    </div>
                                    <div
                                        aria-live="polite"
                                        className="v3-s94"
                                        style={{
                                            opacity: `${dwellOp}`,
                                            translate: `0 ${dwellY}px`,
                                        }}
                                    >
                                        <span className="v3-s95"></span>
                                        {ulam.alt} with garlic rice and egg
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section
                        data-mobile="min-height:0;justify-content:flex-start;padding:40px 0 36px;gap:18px"
                        id="board"
                        ref={panRef}
                        data-part="panRef"
                        aria-label="The rest of the menu"
                        className="v3-s96 v3-s97"
                    >
                        <div data-reveal="" className="v3-s98">
                            <div className="v3-s99">
                                <h2 className="v3-s100">
                                    The rest
                                    <br />
                                    of the menu.
                                </h2>
                                <p className="menu-availability">
                                    Menu availability is unverified.{' '}
                                    <a
                                        href="https://www.facebook.com/profile.php?id=100094575655003"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        Contact us to confirm items and prices.
                                    </a>
                                </p>
                                <p className="v3-s101">
                                    Silog, pares, bulalo and short orders. Every
                                    silog comes with garlic rice and egg.
                                </p>
                                <div
                                    className="menu-lens"
                                    role="group"
                                    aria-label="Menu view"
                                >
                                    {['pics', 'prices'].map((lens) => (
                                        <button
                                            key={lens}
                                            type="button"
                                            aria-pressed={
                                                this.state.menuLens === lens
                                            }
                                            onClick={() =>
                                                this.setMenuLens(lens)
                                            }
                                        >
                                            <svg
                                                width="16"
                                                height="16"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="2"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                aria-hidden="true"
                                            >
                                                {lens === 'pics' ? (
                                                    <>
                                                        <rect
                                                            x="3"
                                                            y="3"
                                                            width="18"
                                                            height="18"
                                                            rx="3"
                                                        />
                                                        <circle
                                                            cx="9"
                                                            cy="9"
                                                            r="2"
                                                        />
                                                        <path d="M21 15l-5-5L5 21" />
                                                    </>
                                                ) : (
                                                    <>
                                                        <path d="M8 6h13M8 12h13M8 18h13" />
                                                        <path d="M3 6h.01M3 12h.01M3 18h.01" />
                                                    </>
                                                )}
                                            </svg>
                                            {lens === 'pics'
                                                ? 'Pics'
                                                : 'Prices'}
                                        </button>
                                    ))}
                                </div>
                            </div>
                            <div className="board-controls">
                                <div
                                    ref={chipRowRef}
                                    data-part="chipRowRef"
                                    role="tablist"
                                    aria-label="Filter dishes"
                                    className="v2-rail v3-s110"
                                >
                                    {boardChips.map((bc, index) => (
                                        <Fragment key={index}>
                                            <button
                                                type="button"
                                                role="tab"
                                                aria-selected={bc.on}
                                                onClick={bc.onPick}
                                                style={{
                                                    ...cssStyle(bc.style),
                                                }}
                                            >
                                                {bc.label}{' '}
                                                <span className="v3-s111">
                                                    {bc.n}
                                                </span>
                                            </button>
                                        </Fragment>
                                    ))}
                                </div>
                                <div
                                    className="v3-s102"
                                    hidden={this.state.menuLens !== 'pics'}
                                >
                                    <span
                                        ref={countRef}
                                        hidden={this.state.menuLens !== 'pics'}
                                        data-part="countRef"
                                        aria-live="polite"
                                        className="v3-s103"
                                    >
                                        {`01 / ${String(cards.length).padStart(2, '0')}`}
                                    </span>
                                    <button
                                        type="button"
                                        onClick={slidePrev}
                                        hidden={this.state.menuLens !== 'pics'}
                                        aria-label="Previous dish"
                                        className="v3-s104 v3-s105"
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
                                            <path d="M15 18l-6-6 6-6"></path>
                                        </svg>
                                    </button>
                                    <button
                                        type="button"
                                        onClick={slideNext}
                                        hidden={this.state.menuLens !== 'pics'}
                                        aria-label="Next dish"
                                        className="v3-s106 v3-s107"
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
                                            <path d="M9 18l6-6-6-6"></path>
                                        </svg>
                                    </button>
                                </div>
                            </div>
                        </div>

                        {this.state.menuLens === 'prices' && (
                            <LandingMenuPrices category={this.state.boardCat} />
                        )}
                        <div
                            hidden={this.state.menuLens !== 'pics'}
                            ref={viewRef}
                            data-part="viewRef"
                            tabIndex="0"
                            role="region"
                            aria-roledescription="carousel"
                            aria-label="Menu photos. Drag left or right, or use the arrow keys."
                            onPointerDown={dragStart}
                            onPointerMove={dragMove}
                            onPointerUp={dragEnd}
                            onPointerCancel={dragEnd}
                            onKeyDown={slideKey}
                            className="v3-s112"
                        >
                            <div
                                ref={trackRef}
                                data-part="trackRef"
                                className="v3-s113"
                            >
                                {imgsReady && (
                                    <>
                                        {' '}
                                        {cards.map((cd, index) => (
                                            <Fragment key={index}>
                                                <figure
                                                    data-mobile="width:80vw"
                                                    data-card=""
                                                    onClick={cd.onPick}
                                                    className="v3-s114 v3-s115"
                                                >
                                                    <img
                                                        src={cd.img}
                                                        alt={cd.alt}
                                                        width="960"
                                                        height="540"
                                                        loading="lazy"
                                                        draggable="false"
                                                        className="v3-s116"
                                                        decoding="async"
                                                    />
                                                </figure>
                                            </Fragment>
                                        ))}{' '}
                                    </>
                                )}
                            </div>
                        </div>
                        <div
                            className="v3-s117"
                            hidden={this.state.menuLens !== 'pics'}
                        >
                            <div aria-hidden="true" className="v3-s118">
                                <div
                                    ref={slideBarRef}
                                    data-part="slideBarRef"
                                    className="v3-s119"
                                ></div>
                            </div>
                            <span className="v3-s120">Drag to explore</span>
                        </div>
                    </section>

                    <section
                        id="lemonade"
                        ref={lemonRef}
                        data-part="lemonRef"
                        aria-label="Three'S Lemonade"
                        className="v3-s121"
                    >
                        <div
                            data-mobile="padding:44px 16px 48px"
                            className="v3-s122 v3-s123"
                        >
                            <div data-reveal="" className="v3-s124">
                                <div className="v3-s125">
                                    <div className="v3-s126">
                                        Three’S Lemonade
                                    </div>
                                    <h2 className="v3-s127">Freshen it up.</h2>
                                </div>
                                <p className="v3-s128">
                                    Fresh lemonade only. Small 12oz, Medium
                                    16oz, Large 22oz. Crystal or nata add-on
                                    ₱10.
                                </p>
                            </div>
                            <div className="v3-s129">
                                {imgsReady && (
                                    <>
                                        {' '}
                                        {cups.map((cp, index) => (
                                            <Fragment key={index}>
                                                <article
                                                    data-cup=""
                                                    className="v3-s130"
                                                >
                                                    <div className="v3-s131">
                                                        <img
                                                            src={cp.img}
                                                            alt={cp.alt}
                                                            width="820"
                                                            height="702"
                                                            loading="lazy"
                                                            className="v3-s132"
                                                            decoding="async"
                                                        />
                                                    </div>
                                                    <div
                                                        data-mobile="flex-direction:column;align-items:flex-start;gap:2px;margin-top:10px"
                                                        className="v3-s133 v3-s134"
                                                    >
                                                        <h3 className="v3-s135">
                                                            {cp.name}
                                                        </h3>
                                                        <span className="v3-s136">
                                                            {cp.price}
                                                        </span>
                                                    </div>
                                                </article>
                                            </Fragment>
                                        ))}{' '}
                                    </>
                                )}
                            </div>
                        </div>
                    </section>

                    <CustomerReviews
                        reviews={this.props.reviews ?? []}
                        branches={this.props.reviewBranches ?? []}
                        noticeVersion={this.props.reviewNoticeVersion ?? ''}
                    />

                    <section id="visit" aria-label="Visit" className="v3-s152">
                        <div
                            data-mobile="padding:44px 16px 28px"
                            className="v3-s153 v3-s154"
                        >
                            <h2
                                ref={comeRef}
                                data-part="comeRef"
                                className="v3-s155"
                            >
                                Come hungry.
                            </h2>
                            <div data-reveal="" className="v3-s156">
                                <div
                                    ref={mapRef}
                                    data-part="mapRef"
                                    role="region"
                                    aria-label="Map showing PONGSKILOG on Adarna Street, Quezon City"
                                    className="v3-s157"
                                    onClick={this.unlockMap}
                                ></div>
                                {this.state.mapLocked !== null && (
                                    <p className="map-hint" aria-live="polite">
                                        {this.state.mapLocked
                                            ? 'Tap the map to move it'
                                            : 'Tap outside the map when done'}
                                    </p>
                                )}
                                <div className="v3-s158">
                                    <div
                                        className="v3-s159"
                                        style={{ color: `${status.fg}` }}
                                    >
                                        <span
                                            className="v3-s160"
                                            style={{
                                                background: `${status.fg}`,
                                            }}
                                        ></span>
                                        {status.label}
                                    </div>
                                    <div className="v3-s161">PONGSKILOG</div>
                                    <div className="v3-s162">
                                        1511, Unit V, Purok 8 Adarna St., Quezon
                                        City
                                    </div>
                                    <div className="v3-s163">
                                        <a
                                            href="https://maps.app.goo.gl/nivpx3cxuP56wAXo8"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="v3-s164 v3-s165"
                                        >
                                            <svg
                                                width="18"
                                                height="18"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="1.9"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                aria-hidden="true"
                                                className="v3-s166"
                                            >
                                                <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path>
                                                <circle
                                                    cx="12"
                                                    cy="10"
                                                    r="3"
                                                ></circle>
                                            </svg>
                                            <span>Get directions</span>
                                        </a>
                                        <button
                                            type="button"
                                            onClick={recenter}
                                            className="v3-s167 v3-s168"
                                        >
                                            Recenter
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div
                                ref={infoRef}
                                data-part="infoRef"
                                className="v3-s169"
                            >
                                <div className="v3-s170">
                                    <h3 data-info="" className="v3-s171">
                                        Info and contact
                                    </h3>
                                    <p data-info="" className="v3-s172">
                                        Dine-in and takeout. Message us on
                                        Facebook for orders and questions.
                                    </p>
                                </div>
                                <div className="v3-s173">
                                    <article
                                        data-info=""
                                        className="v3-s174 v3-s175"
                                    >
                                        <div
                                            data-line=""
                                            aria-hidden="true"
                                            className="v3-s176"
                                        ></div>
                                        <div className="v3-s177">
                                            <svg
                                                width="16"
                                                height="16"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="1.9"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                aria-hidden="true"
                                                className="v3-s178"
                                            >
                                                <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path>
                                                <circle
                                                    cx="12"
                                                    cy="10"
                                                    r="3"
                                                ></circle>
                                            </svg>
                                            Location
                                        </div>
                                        <address className="v3-s179">
                                            1511, Unit V, Purok 8 Adarna St.
                                            <br />
                                            Quezon City, Metro Manila
                                        </address>
                                        <div className="v3-s180"></div>
                                        <a
                                            href="https://maps.app.goo.gl/nivpx3cxuP56wAXo8"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            className="v3-s181 v3-s182"
                                        >
                                            <svg
                                                width="18"
                                                height="18"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="1.9"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                aria-hidden="true"
                                                className="v3-s183"
                                            >
                                                <path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0z"></path>
                                                <circle
                                                    cx="12"
                                                    cy="10"
                                                    r="3"
                                                ></circle>
                                            </svg>
                                            Get directions
                                            <svg
                                                width="16"
                                                height="16"
                                                viewBox="0 0 16 16"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="1.8"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                aria-hidden="true"
                                            >
                                                <path d="M3 8h10M9 4l4 4-4 4"></path>
                                            </svg>
                                        </a>
                                    </article>
                                    <article
                                        data-info=""
                                        className="v3-s184 v3-s185"
                                    >
                                        <div
                                            data-line=""
                                            aria-hidden="true"
                                            className="v3-s186"
                                        ></div>
                                        <div className="v3-s187">
                                            <span className="v3-s188">
                                                <svg
                                                    width="16"
                                                    height="16"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="2"
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    aria-hidden="true"
                                                    className="v3-s189"
                                                >
                                                    <circle
                                                        cx="12"
                                                        cy="12"
                                                        r="10"
                                                    ></circle>
                                                    <path d="M12 6v6l4 2"></path>
                                                </svg>
                                                Hours
                                            </span>
                                            <span
                                                className="v3-s190"
                                                style={{
                                                    color: `${status.fg}`,
                                                }}
                                            >
                                                <span
                                                    className="v3-s191"
                                                    style={{
                                                        background: `${status.fg}`,
                                                    }}
                                                ></span>
                                                {status.short}
                                            </span>
                                        </div>
                                        <div>
                                            {hours.map((h, index) => (
                                                <Fragment key={index}>
                                                    <div
                                                        data-row-in=""
                                                        className="v3-s192"
                                                        style={{
                                                            background: `${h.bg}`,
                                                        }}
                                                    >
                                                        <span className="v3-s193">
                                                            {h.day}
                                                        </span>
                                                        <span
                                                            className="v3-s194"
                                                            style={{
                                                                color: `${h.fg}`,
                                                            }}
                                                        >
                                                            {h.time}
                                                        </span>
                                                        {h.note && (
                                                            <span className="hours-note">
                                                                {h.note}
                                                            </span>
                                                        )}
                                                    </div>
                                                </Fragment>
                                            ))}
                                        </div>
                                    </article>
                                    <article
                                        data-info=""
                                        className="v3-s195 v3-s196"
                                    >
                                        <div
                                            data-line=""
                                            aria-hidden="true"
                                            className="v3-s197"
                                        ></div>
                                        <div className="v3-s198">
                                            <svg
                                                width="16"
                                                height="16"
                                                viewBox="0 0 24 24"
                                                fill="none"
                                                stroke="currentColor"
                                                strokeWidth="2"
                                                strokeLinecap="round"
                                                strokeLinejoin="round"
                                                aria-hidden="true"
                                                className="v3-s199"
                                            >
                                                <path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"></path>
                                            </svg>
                                            Contact
                                        </div>
                                        <div className="v3-s200">
                                            <a
                                                href="https://www.facebook.com/profile.php?id=100094575655003"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="v3-s201 v3-s202"
                                            >
                                                <span className="v3-s203">
                                                    <svg
                                                        width="18"
                                                        height="18"
                                                        viewBox="0 0 24 24"
                                                        fill="currentColor"
                                                        aria-hidden="true"
                                                        className="v3-s204"
                                                    >
                                                        <path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.41 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"></path>
                                                    </svg>
                                                </span>
                                                Pongski-log on Facebook
                                            </a>
                                            <span className="v3-s205">
                                                Orders, questions and updates on
                                                Wednesday hours.
                                            </span>
                                        </div>
                                        <div className="v3-s206"></div>
                                        <a
                                            href="https://www.facebook.com/profile.php?id=100094575655003"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            data-magnet=""
                                            className="v3-s207 v3-s208"
                                        >
                                            <svg
                                                width="18"
                                                height="18"
                                                viewBox="0 0 24 24"
                                                fill="currentColor"
                                                aria-hidden="true"
                                                className="v3-s209"
                                            >
                                                <path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.41 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"></path>
                                            </svg>
                                            <span>Message on Facebook</span>
                                        </a>
                                    </article>
                                    <article
                                        data-info=""
                                        className="v3-s210 v3-s211"
                                    >
                                        <img
                                            src="/images/landing-v121-212d9512.webp"
                                            alt="The PONGSKILOG storefront at night"
                                            width="1200"
                                            height="900"
                                            loading="lazy"
                                            className="v3-s212"
                                            decoding="async"
                                        />
                                        <div className="v3-s213">
                                            <span className="v3-s214">
                                                Dine-in
                                            </span>
                                            <span className="v3-s215">
                                                Takeout
                                            </span>
                                            <span className="v3-s216">
                                                Est. 2022
                                            </span>
                                        </div>
                                    </article>
                                </div>
                                <a
                                    data-info=""
                                    href="https://www.facebook.com/profile.php?id=100094575655003"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="v3-s217 v3-s218"
                                >
                                    <span className="v3-s219">Message us</span>
                                    <svg
                                        width="1em"
                                        height="1em"
                                        viewBox="0 0 24 24"
                                        fill="none"
                                        stroke="currentColor"
                                        strokeWidth="2.2"
                                        strokeLinecap="round"
                                        strokeLinejoin="round"
                                        aria-hidden="true"
                                        className="v3-s220"
                                    >
                                        <path d="M7 17L17 7M8 7h9v9"></path>
                                    </svg>
                                </a>
                            </div>
                        </div>
                        <footer className="v3-s221">
                            {/* Kept on the left: the fixed "powered by" badge covers the bottom-right corner. */}
                            <span>
                                © 2026 PONGSKILOG. Dine-in and takeout.{' '}
                                <button
                                    type="button"
                                    onClick={() =>
                                        this.setState({ privacyOpen: true })
                                    }
                                >
                                    Privacy Notice
                                </button>
                            </span>
                            <span>Est. 2022 · Quezon City</span>
                        </footer>
                    </section>
                </main>
                <ReviewLegalDialog
                    legal={this.state.privacyOpen ? 'privacy' : null}
                    noticeVersion={this.props.reviewNoticeVersion ?? ''}
                    onClose={() => this.setState({ privacyOpen: false })}
                />
                <div aria-label="Powered by SEBBY" className="v3-s222">
                    powered by <span className="v3-s223">SEBBY</span>
                </div>
            </div>
        );
    }
}
