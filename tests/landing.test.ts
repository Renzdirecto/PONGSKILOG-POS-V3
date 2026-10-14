import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
import * as landingData from '../resources/js/lib/landing.ts';
import {
    CARDS,
    CUPS,
    HOURS,
    MENU,
    ULAM,
    advanceTeamScroll,
} from '../resources/js/lib/landing.ts';
import * as websiteAnalytics from '../resources/js/lib/website-analytics.ts';

test('public prices and hours agree with the independently verified published business menu', () => {
    assert.deepEqual(
        ULAM.map(({ price }) => price),
        [100, 100, 110, 110, 95],
    );
    /** Landing V7: the seven Three's Lemonade posters, at the prices printed on them and in the published menu. */
    assert.deepEqual(
        CUPS.map(({ name, price }) => [name, price]),
        [
            ['Pure Lemonade', '₱45 / 55 / 65'],
            ['Lemon + Yakult', '₱55 / 65 / 75'],
            ['Lemon + Dutch Mill', '₱55 / 65 / 75'],
            ['Lemon + Cucumber', '₱55 / 65 / 75'],
            ['Lemon + Calamansi', '₱55 / 65 / 75'],
            ['Lemon + Lychee', '₱55 / 65 / 75'],
            ['Lemon + Cola', '₱55 / 65 / 75'],
        ],
    );
    /** One path per poster: the cups come from the published menu itself, beside each name and price. */
    assert.deepEqual(
        CUPS.map(({ name, price, img }) => [name, price, img]),
        MENU.drinks.items.filter((item) => item.length > 2),
    );
    assert.deepEqual(MENU.dessert.items, [
        ['Mango Graham Bar', '₱35'],
        ['Oreo Graham Bar', '₱30'],
        ['Classic Graham Bar', '₱25'],
    ]);
    const wednesday = HOURS.find(({ day }) => day === 'Wednesday');
    assert.equal(wednesday?.time, 'Usually Closed');
    assert.equal(wednesday?.note, 'Sometimes open — message to confirm.');
    assert.deepEqual(wednesday?.days, ['Wed']);
    assert.equal(HOURS.find(({ day }) => day === 'Sunday')?.time, 'Closed');
    assert.equal(HOURS[0].time, '6:00 PM - 12:00 AM');
    assert.deepEqual(landingData.SHOP, [14.6936607, 121.079139]);
    assert.equal(
        CARDS.some(({ alt }) =>
            ['Lomi', 'Siopao', 'Beef broccoli', 'Beef ampalaya'].includes(alt),
        ),
        false,
    );
});

test('every referenced landing photograph is a local WebP asset', () => {
    for (const { img } of [...ULAM, ...CARDS, ...CUPS]) {
        assert.match(img, /^\/images\/landing-v1(?:21|30)-[a-f\d]+\.webp$/);
        assert.ok(existsSync(new URL(`../public${img}`, import.meta.url)), img);
        const bytes = readFileSync(new URL(`../public${img}`, import.meta.url));
        assert.equal(bytes.toString('ascii', 8, 12), 'WEBP');
    }
});

function experienceHarness() {
    const source = readFileSync(
        new URL(
            '../resources/js/components/landing-experience.jsx',
            import.meta.url,
        ),
        'utf8',
    );
    const code = ts.transpileModule(source, {
        fileName: 'landing-experience.jsx',
        compilerOptions: {
            target: ts.ScriptTarget.ESNext,
            module: ts.ModuleKind.CommonJS,
            jsx: ts.JsxEmit.ReactJSX,
            esModuleInterop: true,
        },
    }).outputText;
    const listeners: string[] = [];
    const cancelled: number[] = [];
    /** Website counters the page sent, with a clock the test advances past the one-second de-duplication. */
    const tracked: string[] = [];
    const clock = { now: 10_000 };
    let observerCallback: (
        entries: { isIntersecting: boolean }[],
    ) => void = () => {};
    let disconnected = 0;
    class Component {
        props = { motion: false };
        state = {};
        setState(update: object | ((state: object) => object)) {
            this.state = {
                ...this.state,
                ...(typeof update === 'function' ? update(this.state) : update),
            };
        }
    }
    const react = { Component, createRef: () => ({ current: null }) };
    const scrollingElement = {
        scrollTop: 0,
        scrollHeight: 6000,
        clientHeight: 800,
    };
    const window = {
        innerWidth: 1000,
        innerHeight: 800,
        L: {},
        IntersectionObserver: true,
        removeEventListener: (name: string) => listeners.push(name),
    };
    const document = {
        scrollingElement,
        documentElement: scrollingElement,
        getElementById: () => null,
        removeEventListener: (name: string) => listeners.push(name),
    };
    const Experience = new Function(
        'require',
        'exports',
        'window',
        'document',
        'cancelAnimationFrame',
        'IntersectionObserver',
        'Date',
        `${code}; return exports.default;`,
    )(
        (name: string) =>
            name === 'react'
                ? react
                : name === '@/lib/landing'
                  ? landingData
                  : name === '@/lib/website-analytics'
                    ? websiteAnalytics
                    : name === '@/routes/website-events'
                      ? {
                            store: () => ({
                                url: '/website-events',
                                method: 'post',
                            }),
                        }
                      : name === '@inertiajs/react'
                        ? {
                              http: {
                                  getClient: () => ({
                                      request: (request: {
                                          url: string;
                                          data: { event: string };
                                      }) => {
                                          assert.equal(
                                              request.url,
                                              '/website-events',
                                          );
                                          tracked.push(request.data.event);
                                          return Promise.resolve();
                                      },
                                  }),
                              },
                          }
                        : name === 'react/jsx-runtime'
                          ? {
                                jsx: (type: unknown, props: unknown) => ({
                                    type,
                                    props,
                                }),
                                jsxs: (type: unknown, props: unknown) => ({
                                    type,
                                    props,
                                }),
                            }
                          : name.startsWith('@/components/')
                            ? new Proxy({}, { get: (_module, key) => key })
                            : {},
        {},
        window,
        document,
        (id: number) => cancelled.push(id),
        class {
            constructor(callback: typeof observerCallback) {
                observerCallback = callback;
            }
            observe() {}
            disconnect() {
                disconnected += 1;
            }
        },
        { now: () => clock.now },
    );
    const ui = new Experience();
    ui.rootRef.current = { querySelectorAll: () => [] };
    const cards = [0, 400, 800, 1200].map((offsetLeft) => ({
        offsetLeft,
        offsetWidth: 400,
        style: {},
    }));
    ui.viewRef.current = {
        clientWidth: 500,
        style: {},
        setPointerCapture: () => {},
        getBoundingClientRect: () => ({ top: 200, bottom: 500 }),
    };
    ui.trackRef.current = {
        scrollWidth: 1500,
        querySelectorAll: () => cards,
        style: {},
    };
    ui.countRef.current = { textContent: '' };
    ui.slideBarRef.current = { style: {} };
    return {
        ui,
        window,
        document,
        listeners,
        cancelled,
        tracked,
        clock,
        intersect: () => observerCallback([{ isIntersecting: true }]),
        disconnected: () => disconnected,
    };
}

test('hero sizing preserves the approved desktop marquee space and mobile image bounds', () => {
    const { ui, window } = experienceHarness();
    ui.applyMobile = () => {};
    ui.heroRef.current = { style: {} };
    ui.wmRef.current = { style: {} };
    ui.heroImgRef.current = { style: {} };

    ui.responsive();
    assert.equal(ui.wmRef.current.style.marginTop, '-.42em');
    assert.equal(
        ui.heroImgRef.current.style.maxHeight,
        'max(220px, calc(100svh - 440px))',
    );
    assert.equal(ui.heroRef.current.style.minHeight, 'calc(100svh - 60px)');

    window.innerWidth = 390;
    ui.responsive();
    assert.equal(ui.wmRef.current.style.marginTop, '-.22em');
    assert.equal(
        ui.heroImgRef.current.style.maxHeight,
        'clamp(130px, calc(100svh - 530px), 34svh)',
    );
    assert.equal(ui.heroRef.current.style.minHeight, '0');
});

test('standalone carousel buttons and arrow keys snap to bounded cards with the correct counter', () => {
    const { ui } = experienceHarness();
    let prevented = 0;
    ui.slideKey({
        key: 'ArrowRight',
        preventDefault: () => {
            prevented += 1;
        },
    });
    ui.slideFrame(false);
    assert.equal(prevented, 1);
    /** 400 px cards in a 500 px carousel: each stop is the card's middle minus half the carousel (v1.3.0). */
    assert.equal(ui._stx, 350);
    assert.equal(ui.countRef.current.textContent, '02 / 04');
    ui.slideTo(99);
    ui.slideFrame(false);
    assert.equal(ui._stx, 1150);
    assert.equal(ui.countRef.current.textContent, '04 / 04');
    ui.slideTo(-1);
    assert.equal(ui._stx, -50);
});

test('the highlighted menu card rests in the middle of the carousel, first and last included', () => {
    const { ui } = experienceHarness();
    const view = ui.viewRef.current;
    const cards = ui.trackRef.current.querySelectorAll();
    /** How far the middle of a card is from the middle of the carousel. */
    const offCentre = (index: number) =>
        cards[index].offsetLeft +
        cards[index].offsetWidth / 2 -
        ui._sx -
        view.clientWidth / 2;
    /** The first paint already has the first dish centred: no glide in from the left edge. */
    ui.slideFrame(false);
    assert.equal(offCentre(0), 0);
    assert.equal(ui.countRef.current.textContent, '01 / 04');
    ui.slideTo(2);
    ui.slideFrame(false);
    assert.equal(offCentre(2), 0);
    ui.slideTo(99);
    ui.slideFrame(false);
    assert.equal(offCentre(3), 0);
    /** A resize or a rotated phone keeps the same dish centred. */
    view.clientWidth = 900;
    ui.slideFrame(false);
    assert.equal(offCentre(3), 0);

    /** With motion the centred card is the full-size, unrotated one and its neighbours step back. */
    ui.props = { ...ui.props, motion: true };
    ui.slideTo(1);
    for (let frame = 0; frame < 200; frame++) ui.slideFrame(true);
    assert.equal(offCentre(1), 0);
    assert.match(
        cards[1].style.transform,
        /rotateY\(0\.00deg\).*scale\(1\.0000\)/,
    );
    assert.match(cards[0].style.transform, /scale\(0\.9000\)/);
    assert.match(cards[2].style.transform, /scale\(0\.9000\)/);

    /** Hidden behind the Prices view there is nothing to measure, so the carousel keeps its place. */
    view.clientWidth = 0;
    ui.slideFrame(true);
    assert.equal(ui._si, 1);
    assert.equal(ui.slideBounds(), null);
});

test('touch dragging respects vertical scrolling and snaps horizontal gestures', () => {
    const { ui } = experienceHarness();
    const pointer = {
        clientX: 200,
        clientY: 200,
        pointerId: 1,
        pointerType: 'touch',
        button: 0,
    };
    ui.dragStart(pointer);
    ui.dragMove({ ...pointer, clientX: 203, clientY: 230 });
    assert.equal(ui._drag, null);
    ui.dragStart(pointer);
    ui.dragMove({ ...pointer, clientX: -200, clientY: 202 });
    assert.equal(ui._drag.locked, true);
    ui.dragEnd();
    assert.equal(ui._drag, null);
    /** The swipe lands on a later card, centred. */
    assert.ok([350, 750, 1150].includes(ui._stx), String(ui._stx));
});

test('dish and price-list filters use real category data and reset the carousel', () => {
    const { ui } = experienceHarness();
    ui._sx = 800;
    ui._stx = 800;
    ui.pickBoardCat('pares')();
    ui.componentDidUpdate();
    /** Back to the first dish of the new filter, already centred. */
    assert.equal(ui._stx, -50);
    assert.equal(ui._sx, -50);
    assert.equal(
        ui.renderVals().cards.length,
        CARDS.filter((card) => card.cat === 'pares').length,
    );
    ui.renderVals()
        .tabs.find((tab: { label: string }) => tab.label === 'Dessert')
        .onSelect();
    assert.deepEqual(
        ui
            .renderVals()
            .items.map(({ n, p }: { n: string; p: string }) => [n, p]),
        MENU.dessert.items,
    );
});

test('silog chips use the reference scroll positions and staff login keeps normal route navigation', () => {
    const { ui, document } = experienceHarness();
    ui.peakRef.current = {
        offsetHeight: 4000,
        getBoundingClientRect: () => ({ top: 1000 }),
    };
    ui.pickUlam(2)();
    assert.equal(document.scrollingElement.scrollTop, 2540);
    ui.toggleProf();
    assert.equal(ui.state.profOpen, true);
    ui.onEsc({ key: 'Escape' });
    assert.equal(ui.state.profOpen, false);
    ui.toggleProf();
    ui.staffLogin({
        preventDefault: () =>
            assert.fail('Login must follow the existing Inertia route'),
    });
    assert.equal(ui.state.profOpen, false);
});

test('map loads only near the viewport and late loading cannot initialize an unmounted page', async () => {
    const { ui, intersect, disconnected } = experienceHarness();
    let maps = 0;
    ui.mapRef.current = {};
    ui.initMap = () => {
        maps += 1;
    };
    ui.observeMap();
    assert.equal(maps, 0);
    intersect();
    ui._unmounted = true;
    await Promise.resolve();
    assert.equal(maps, 0);
    assert.equal(disconnected(), 1);
});

test('leaving the landing page cleans up animation, map, observers, and event listeners', () => {
    const { ui, listeners, cancelled } = experienceHarness();
    let removed = 0;
    ui._raf = 17;
    ui._pr = 18;
    ui._map = {
        remove: () => {
            removed += 1;
        },
    };
    ui.viewRef.current.removeEventListener = (name: string) =>
        listeners.push(name);
    ui.componentWillUnmount();
    assert.equal(removed, 1);
    assert.equal(ui._map, null);
    assert.equal(ui._unmounted, true);
    assert.deepEqual(cancelled, [17, 18]);
    assert.deepEqual(listeners, [
        'visibilitychange',
        'pointerdown',
        'keydown',
        'resize',
        'pointermove',
        'wheel',
    ]);
});

test('menu availability stays under its heading and compact icon lenses switch views', () => {
    const { ui } = experienceHarness();
    const nodes: any[] = [];
    const visit = (node: any) => {
        if (Array.isArray(node)) return node.forEach(visit);
        if (!node?.props) return;
        nodes.push(node);
        visit(node.props.children);
    };
    visit(ui.render());
    const headingGroup = nodes.find(
        (node) => node.props.className === 'v3-s99',
    );
    const children = headingGroup.props.children.filter(Boolean);
    assert.equal(children[0].type, 'h2');
    assert.equal(children[1].props.className, 'menu-availability');
    assert.equal(
        nodes.filter((node) => node.props.className === 'menu-availability')
            .length,
        1,
    );
    const lens = nodes.find((node) => node.props['aria-label'] === 'Menu view');
    const [pics, prices] = lens.props.children;
    assert.equal(pics.props.children[0].type, 'svg');
    assert.equal(prices.props.children[0].type, 'svg');
    assert.equal(pics.props['aria-pressed'], true);
    prices.props.onClick();
    assert.equal(ui.state.menuLens, 'prices');
    pics.props.onClick();
    assert.equal(ui.state.menuLens, 'pics');
});

test('V2 menu lenses share category filters and reset when changing view', () => {
    const { ui } = experienceHarness();
    ui.setMenuLens('prices');
    const prices = ui.renderVals();
    assert.equal(
        prices.boardChips.find(
            (chip: { label: string }) => chip.label === 'Drinks',
        ).n,
        10,
    );
    ui.pickBoardCat('drinks')();
    assert.equal(ui.state.boardCat, 'drinks');
    ui.state.narrow = true;
    assert.equal(
        ui.renderVals().boardChips.find((chip: { on: boolean }) => chip.on)
            .label,
        'Drinks',
    );
    ui.chipRowRef.current = { scrollLeft: 240 };
    ui.setMenuLens('pics');
    assert.equal(ui.state.boardCat, 'all');
    assert.equal(ui.chipRowRef.current.scrollLeft, 0);
    /** Pics has Drinks too, counting its seven posters while Prices counts the ten listed drinks. */
    assert.equal(
        ui
            .renderVals()
            .boardChips.find(
                (chip: { label: string }) => chip.label === 'Drinks',
            ).n,
        7,
    );
    assert.ok(
        !ui
            .renderVals()
            .boardChips.some(
                (chip: { label: string }) => chip.label === 'Dessert',
            ),
    );
});

test('hero marquee pause and resume retain its current animation offset', () => {
    const { ui } = experienceHarness();
    ui._mOff = 321;
    ui.pauseMarquee();
    assert.equal(ui._marqueePaused, true);
    assert.equal(ui._mOff, 321);
    ui.resumeMarquee();
    assert.equal(ui._marqueePaused, false);
    assert.equal(ui._mOff, 321);
});

function mapHarness(mobile: boolean) {
    const harness = experienceHarness();
    const { ui } = harness;
    const tiles: { url: string; options: { attribution: string } }[] = [];
    const flights: unknown[][] = [];
    const handlers: string[] = [];
    const counts = { markers: 0, zoomControls: 0 };
    let options: Record<string, unknown> = {};
    const handler = (name: string) => ({
        enable: () => handlers.push(`${name}:on`),
        disable: () => handlers.push(`${name}:off`),
    });
    const map = {
        setView: () => map,
        flyTo: (...call: unknown[]) => flights.push(call),
        dragging: handler('dragging'),
        touchZoom: handler('touchZoom'),
    };
    ui._leaflet = {
        Browser: { mobile },
        map: (_element: unknown, mapOptions: Record<string, unknown>) => {
            options = mapOptions;
            return map;
        },
        control: {
            zoom: () => ({ addTo: () => (counts.zoomControls += 1) }),
        },
        tileLayer: (url: string, layer: { attribution: string }) => {
            tiles.push({ url, options: layer });
            return { addTo() {} };
        },
        divIcon: () => ({}),
        marker: () => ({ addTo: () => (counts.markers += 1) }),
    };
    const element = { contains: (target: unknown) => target === 'map' };
    ui.mapRef.current = element;
    ui.initMap();
    return {
        ...harness,
        tiles,
        flights,
        handlers,
        counts,
        options: () => options,
    };
}

test('the map uses keyless OpenStreetMap tiles and keeps its marker, zoom control and recenter', () => {
    const { ui, tiles, flights, counts } = mapHarness(true);
    assert.equal(tiles.length, 1);
    assert.equal(
        tiles[0].url,
        'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    );
    assert.doesNotMatch(tiles[0].url, /key|token|carto/i);
    assert.match(tiles[0].options.attribution, /OpenStreetMap/);
    assert.equal(counts.markers, 1);
    assert.equal(counts.zoomControls, 1);
    ui.recenter();
    assert.equal(flights.length, 1);
});

test('a touch map never traps page scrolling until it is tapped and locks again outside it', () => {
    const { ui, handlers, options, flights } = mapHarness(true);
    /** Locked: no drag or pinch handler, so a finger over the map scrolls the page; wheel zoom stays off. */
    assert.equal(options().dragging, false);
    assert.equal(options().touchZoom, false);
    assert.equal(options().scrollWheelZoom, false);
    assert.equal(ui.state.mapLocked, true);
    const hint = () => {
        const nodes: any[] = [];
        const visit = (node: any) => {
            if (Array.isArray(node)) return node.forEach(visit);
            if (!node?.props) return;
            nodes.push(node);
            visit(node.props.children);
        };
        visit(ui.render());
        return {
            text: nodes.find((node) => node.props.className === 'map-hint')
                ?.props.children,
            map: nodes.find((node) => node.props.className === 'v3-s157'),
        };
    };
    assert.equal(hint().text, 'Tap the map to move it');

    hint().map.props.onClick();
    assert.deepEqual(handlers, ['dragging:on', 'touchZoom:on']);
    assert.equal(ui.state.mapLocked, false);
    assert.equal(hint().text, 'Tap outside the map when done');
    /** A touch that starts on the map keeps it active; Recenter still flies while unlocked. */
    ui.onDocDown({ target: 'map' });
    assert.equal(ui.state.mapLocked, false);
    ui.recenter();
    assert.equal(flights.length, 1);

    ui.onDocDown({ target: 'page' });
    assert.deepEqual(handlers.slice(2), ['dragging:off', 'touchZoom:off']);
    assert.equal(ui.state.mapLocked, true);
    ui.onDocDown({ target: 'page' });
    assert.equal(handlers.length, 4);
});

test('a mouse map drags immediately and shows no touch hint', () => {
    const { ui, options, handlers } = mapHarness(false);
    assert.equal(options().dragging, true);
    assert.equal(options().touchZoom, true);
    assert.equal(options().scrollWheelZoom, false);
    assert.equal(ui.state.mapLocked, null);
    ui.unlockMap();
    ui.onDocDown({ target: 'page' });
    assert.deepEqual(handlers, []);
});

function renderedNodes(ui: { render: () => unknown }) {
    const nodes: any[] = [];
    const visit = (node: any) => {
        if (Array.isArray(node)) return node.forEach(visit);
        if (!node?.props) return;
        nodes.push(node);
        visit(node.props.children);
    };
    visit(ui.render());
    return nodes;
}

test('phones show the Pics and Prices toggle and every category filter directly', () => {
    const { ui } = experienceHarness();
    ui.state.narrow = true;
    const nodes = renderedNodes(ui);
    assert.ok(nodes.find((node) => node.props['aria-label'] === 'Menu view'));
    const filters = nodes.filter(
        (node) => node.props['aria-label'] === 'Filter dishes',
    );
    /** One always-rendered row of chips; no icon button that hides them behind a tap. */
    assert.equal(filters.length, 1);
    assert.equal(filters[0].props.role, 'tablist');
    assert.equal('aria-expanded' in filters[0].props, false);
    const chips = filters[0].props.children.map(
        (fragment: any) => fragment.props.children,
    );
    assert.deepEqual(
        chips.map((chip: any) => chip.props['aria-selected']),
        landingData.BOARD_CATS.map(([key]) => key === 'all'),
    );
    chips[2].props.onClick();
    const active = ui
        .renderVals()
        .boardChips.filter((chip: { on: boolean }) => chip.on);
    assert.equal(active.length, 1);
    assert.equal(active[0].label, landingData.BOARD_CATS[2][1]);
    assert.match(active[0].style, /background:#121110;color:#FFFFFF/);

    const css = readFileSync(
        new URL('../resources/css/landing.css', import.meta.url),
        'utf8',
    );
    const phone = css.slice(css.lastIndexOf('@media (max-width: 699px)'));
    assert.match(css, /\.landing-v3 \.v3-s110 \{[^}]*overflow-x: auto;/);
    assert.match(phone, /\.board-controls \.v3-s110 \{[^}]*margin: 0 -16px;/);
    assert.doesNotMatch(css, /active-menu-filter|v3-s108/);
});

test('Wednesday reads Usually Closed with a wrapping secondary line inside the Hours card', () => {
    const { ui } = experienceHarness();
    const nodes = renderedNodes(ui);
    const notes = nodes.filter((node) => node.props.className === 'hours-note');
    assert.deepEqual(
        notes.map((node) => node.props.children),
        ['Sometimes open — message to confirm.'],
    );
    const times = nodes
        .filter((node) => node.props.className === 'v3-s194')
        .map((node) => node.props.children);
    assert.deepEqual(times, ['6:00 PM - 12:00 AM', 'Usually Closed', 'Closed']);

    const css = readFileSync(
        new URL('../resources/css/landing.css', import.meta.url),
        'utf8',
    );
    const rule = (selector: string) => {
        const start = css.indexOf(`.landing-v3 ${selector} {`);
        return css.slice(start, css.indexOf('}', start));
    };
    assert.match(rule('.v3-s192'), /flex-wrap: wrap;/);
    assert.doesNotMatch(rule('.v3-s194'), /white-space: nowrap/);
    assert.match(rule('.v3-s194'), /overflow-wrap: anywhere;/);
    assert.match(rule('.hours-note'), /flex: 0 0 100%;/);
});

test('the landing page reports only its own page view and link taps as anonymous events', () => {
    const { ui, tracked, clock } = experienceHarness();
    const follow = (href: string | null) => {
        clock.now += 2000;
        ui.trackLink({
            target: {
                closest: () =>
                    href === null ? null : { getAttribute: () => href },
            },
        });
    };
    follow(landingData.FB);
    follow('https://maps.app.goo.gl/nivpx3cxuP56wAXo8');
    follow('#board');
    follow('#reviews');
    follow('/login');
    follow(null);
    assert.deepEqual(tracked, [
        'facebook_click',
        'directions_click',
        'menu_click',
    ]);
    clock.now += 2000;
    ui.setMenuLens('prices');
    clock.now += 2000;
    ui.pickBoardCat('drinks')();
    /** A double tap inside one second is one event. */
    ui.pickBoardCat('silog')();
    assert.deepEqual(tracked.slice(3), ['menu_click', 'menu_click']);

    /** The hero uses a button; a Menu anchor also bubbles through trackLink and must still count only once. */
    clock.now += 2000;
    ui.nav('board')({ preventDefault() {} });
    ui.trackLink({
        target: { closest: () => ({ getAttribute: () => '#board' }) },
    });
    assert.deepEqual(tracked.slice(5), ['menu_click']);

    const source = readFileSync(
        new URL(
            '../resources/js/components/landing-experience.jsx',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(source, /this\.infoMotion\(\);\s*track\('page_view'\);/);
    assert.match(source, /onClick=\{this\.trackLink\}/);
    /** The beacon carries an event name only. */
    assert.match(source, /data: \{ event \}/);
});

test('the footer opens the Privacy Notice without the review form', () => {
    const { ui } = experienceHarness();
    const find = () => {
        const nodes = renderedNodes(ui);
        return {
            button: nodes.find(
                (node) =>
                    node.type === 'button' &&
                    node.props.children === 'Privacy Notice',
            ),
            dialog: nodes.find((node) => 'legal' in node.props),
        };
    };
    assert.equal(find().dialog.props.legal, null);
    find().button.props.onClick();
    assert.equal(find().dialog.props.legal, 'privacy');
    find().dialog.props.onClose();
    assert.equal(find().dialog.props.legal, null);
});

test('phone headings step down without shrinking body copy below a readable size', () => {
    const css = readFileSync(
        new URL('../resources/css/landing.css', import.meta.url),
        'utf8',
    );
    const phone = css.slice(css.lastIndexOf('@media (max-width: 699px)'));
    /** Landing V7: "Visit us" is already section-sized on every screen, so the phone keeps the base size. */
    assert.doesNotMatch(phone, /\.v3-s155 \{/);
    assert.match(
        css,
        /\.landing-v3 \.v3-s155 \{[^}]*font-size: clamp\(44px, 8vw, 120px\);/,
    );
    const sizes = [...phone.matchAll(/font-size: ([\d.]+)px/g)].map((match) =>
        Number(match[1]),
    );
    assert.ok(Math.min(...sizes) >= 13, String(Math.min(...sizes)));
});

test('the mobile About statement is sized by its content instead of a viewport-height block', () => {
    const css = readFileSync(
        new URL('../resources/css/landing.css', import.meta.url),
        'utf8',
    );
    const mobile = css.slice(css.indexOf('@media (max-width: 699px)'));
    const about = mobile.slice(
        mobile.indexOf('.landing-v3 .v3-s68 {'),
        mobile.indexOf('}'),
    );
    assert.match(about, /padding: 56px 16px;/);
    assert.doesNotMatch(about, /min-height/);
});

test('the Drinks filter shows the seven lemonade posters whole over their own blur', () => {
    const { ui } = experienceHarness();
    assert.deepEqual(landingData.BOARD_CATS.at(-1), ['drinks', 'Drinks']);
    const drinks = CARDS.filter((card) => card.cat === 'drinks');
    /** The same seven products, posters and names as the Three's Lemonade section: nothing new is claimed. */
    assert.deepEqual(
        drinks.map(({ img, alt }) => ({ img, alt })),
        CUPS.map(({ img, alt }) => ({ img, alt })),
    );
    assert.ok(drinks.every((card) => card.drink === true));
    assert.equal(
        ui
            .renderVals()
            .boardChips.find((chip: { label: string }) => chip.label === 'All')
            .n,
        CARDS.length,
    );

    ui._sx = 800;
    ui._stx = 800;
    ui.pickBoardCat('drinks')();
    ui.componentDidUpdate();
    assert.equal(ui._stx, -50);
    const figures = renderedNodes(ui).filter(
        (node) => node.props.className === 'v3-s114 v3-s115',
    );
    assert.equal(figures.length, 7);
    for (const [index, figure] of figures.entries()) {
        const [blur, photo] = figure.props.children;
        assert.equal(blur.props.className, 'board-drink-blur');
        assert.equal(blur.props.alt, '');
        assert.equal(blur.props['aria-hidden'], 'true');
        assert.equal(photo.props.className, 'v3-s116 board-drink');
        assert.equal(photo.props.alt, CUPS[index].alt);
        /** One file per card: the blur reuses the photo, and both wait until the card is near the screen. */
        assert.equal(blur.props.src, photo.props.src);
        assert.equal(blur.props.loading, 'lazy');
        assert.equal(photo.props.loading, 'lazy');
    }

    ui.pickBoardCat('silog')();
    const food = renderedNodes(ui).find(
        (node) => node.props.className === 'v3-s114 v3-s115',
    );
    const [noBlur, plate] = food.props.children;
    assert.equal(noBlur, false);
    assert.equal(plate.props.className, 'v3-s116');

    const css = readFileSync(
        new URL('../resources/css/landing.css', import.meta.url),
        'utf8',
    );
    assert.match(css, /\.landing-v3 \.board-drink \{\s*object-fit: contain;/);
    assert.match(
        css,
        /\.landing-v3 \.board-drink-blur \{[^}]*position: absolute;[^}]*filter: blur\(24px\)/,
    );
    assert.match(css, /\.landing-v3 \.v3-s115 \{[^}]*position: relative;/);
});

test("the Three's Lemonade section carries the V6 yellow heading over its lazily loaded cups", () => {
    const { ui } = experienceHarness();
    const nodes = renderedNodes(ui);
    const section = nodes.find((node) => node.props.id === 'lemonade');
    assert.equal(section.props['aria-label'], "Three'S Lemonade");
    assert.equal(
        nodes.find((node) => node.props.className === 'v3-s126').props.children,
        'Freshen it up.',
    );
    const heading = nodes.find((node) => node.props.className === 'v3-s127');
    assert.equal(heading.type, 'h2');
    assert.equal(heading.props.children, 'Three’s Lemonade');
    const cups = nodes.filter((node) => node.props.className === 'v3-s132');
    assert.deepEqual(
        cups.map((cup) => [cup.props.src, cup.props.alt, cup.props.loading]),
        CUPS.map(({ img, alt }) => [img, alt, 'lazy']),
    );

    const css = readFileSync(
        new URL('../resources/css/landing.css', import.meta.url),
        'utf8',
    );
    assert.match(css, /\.landing-v3 \.v3-s127 \{[^}]*color: #f2b419;/);
});

test('Landing V7: the online kiosk sits before the reviews, "Visit us" heads the map and the big Message us link is gone', () => {
    const { ui } = experienceHarness();
    const kiosk = [
        { name: 'Adarna St.', address: null, url: '/kiosk/ADARNA', open: true },
    ];
    ui.props = { ...ui.props, kiosk };
    const nodes = renderedNodes(ui);
    const main = nodes.find((node) => node.type === 'main');
    const order = main.props.children
        .filter(Boolean)
        .map((child: any) => child.props?.id ?? child.type);
    const lemonade = order.indexOf('lemonade');
    /** The Owner asked for the kiosk right before "Sabi ng mga suki." (the reviews). */
    assert.deepEqual(order.slice(lemonade, lemonade + 4), [
        'lemonade',
        'LandingKiosk',
        'CustomerReviews',
        'visit',
    ]);
    assert.equal(
        nodes.find((node) => node.type === 'LandingKiosk').props.branches,
        kiosk,
    );
    const visitHeading = nodes.find(
        (node) => node.props['data-part'] === 'comeRef',
    );
    assert.equal(visitHeading.props.children, 'Visit us');
    const texts = nodes.flatMap((node) =>
        typeof node.props.children === 'string' ? [node.props.children] : [],
    );
    assert.ok(!texts.includes('Come hungry.'));
    assert.ok(!texts.includes('Message us'));
    /** The Info and contact cards keep their own Facebook button. */
    assert.ok(texts.includes('Message on Facebook'));

    const css = readFileSync(
        new URL('../resources/css/landing.css', import.meta.url),
        'utf8',
    );
    /** Each poster carries its name and prices, so it is shown whole in Three's Lemonade. */
    assert.match(
        css,
        /\.landing-v3 \.v3-s132 \{\s*display: block;\s*width: 100%;\s*height: auto;\s*aspect-ratio: 4\/5;\s*object-fit: contain;\s*\}/,
    );
    assert.doesNotMatch(css, /\.v3-s217|\.v3-s218|\.v3-s219|\.v3-s220/);
});

test('the team rail advances at the V6 pace on any refresh rate and restarts at the end', () => {
    const moved = (elapsed: number) =>
        advanceTeamScroll(100, 1500, elapsed).position - 100;
    /** V6 moved 0.4 px per 60 Hz frame. */
    assert.ok(Math.abs(moved(1000 / 60) - 0.4) < 1e-9);
    assert.equal(advanceTeamScroll(100, 1500, 1000 / 60).restart, false);
    /** A 120 Hz screen moves half as far per frame, so the speed on screen is the same. */
    assert.ok(Math.abs(moved(1000 / 120) - 0.2) < 1e-9);
    /** A long gap between frames (a tab that was in the background) cannot make the rail jump. */
    assert.ok(Math.abs(moved(5000) - 64 * 0.024) < 1e-9);
    /** It never passes the last card; within a pixel of the end it asks to return to the first one. */
    assert.equal(advanceTeamScroll(1498.9, 1500, 1000).position, 1500);
    assert.equal(advanceTeamScroll(1499.9, 1500.5, 16).restart, true);
    assert.deepEqual(advanceTeamScroll(1500, 1500, 16), {
        position: 0,
        restart: true,
    });
});

test('the team follows Info and Contact, and the page hands the server totals to reviews and tap-to-love', () => {
    const { ui } = experienceHarness();
    const team = [{ id: 2, code: 'COO' }];
    ui.props = {
        ...ui.props,
        reviewSummary: { count: 12, average: 4.8 },
        team,
        loveTotal: 345,
    };
    const nodes = renderedNodes(ui);
    const main = nodes.find((node) => node.type === 'main');
    const sections = main.props.children.filter(Boolean);
    assert.equal(sections.at(-2).props.id, 'visit');
    assert.equal(sections.at(-1).type, 'LandingTeam');
    /** The cards are whatever Website → Team currently shows, straight from the server. */
    assert.equal(sections.at(-1).props.members, team);
    assert.deepEqual(
        nodes.find((node) => node.type === 'CustomerReviews').props.summary,
        { count: 12, average: 4.8 },
    );
    assert.equal(
        nodes.find((node) => node.type === 'LandingLove').props.total,
        345,
    );

    const css = readFileSync(
        new URL('../resources/css/landing.css', import.meta.url),
        'utf8',
    );
    const rule = (selector: string) => {
        const start = css.indexOf(`.landing-v3 ${selector} {`);
        return css.slice(start, css.indexOf('}', start));
    };
    /** White section, 3:4 portrait cards and the role in a white tab at the top-left corner. */
    assert.match(rule('.team-section'), /background: #fff;/);
    assert.match(rule('.team-card'), /aspect-ratio: 3\/4;/);
    assert.match(rule('.team-card'), /width: min\(76vw, 300px\);/);
    assert.match(rule('.team-code'), /top: 0;\s*left: 0;/);
    assert.match(rule('.team-code'), /border-radius: 0 0 14px 0;/);
    /** A finger swipes the rail natively; the tap-to-love chef sits at the bottom-left above the page. */
    assert.match(rule('.team-rail'), /overflow-x: auto;/);
    assert.match(rule('.love'), /position: fixed;/);
    assert.match(
        rule('.love'),
        /left: calc\(16px \+ env\(safe-area-inset-left\)\);/,
    );
    assert.match(rule('.love'), /pointer-events: none;/);
    const phone = css.slice(css.lastIndexOf('@media (max-width: 699px)'));
    assert.match(phone, /\.landing-v3 \.team-nav \{\s*display: none;/);
});
