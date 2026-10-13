import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
import * as landingData from '../resources/js/lib/landing.ts';
import { CARDS, CUPS, HOURS, MENU, ULAM } from '../resources/js/lib/landing.ts';
import * as websiteAnalytics from '../resources/js/lib/website-analytics.ts';

test('public prices and hours agree with the independently verified published business menu', () => {
    assert.deepEqual(
        ULAM.map(({ price }) => price),
        [100, 100, 110, 110, 95],
    );
    assert.deepEqual(
        CUPS.map(({ price }) => price),
        ['₱45 / 55 / 65', '₱55 / 65 / 75', '₱55 / 65 / 75', '₱55 / 65 / 75'],
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
        assert.match(img, /^\/images\/landing-v121-[a-f\d]+\.webp$/);
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
    assert.equal(ui._stx, 400);
    assert.equal(ui.countRef.current.textContent, '02 / 04');
    ui.slideTo(99);
    ui.slideFrame(false);
    assert.equal(ui._stx, 1000);
    assert.equal(ui.countRef.current.textContent, '04 / 04');
    ui.slideTo(-1);
    assert.equal(ui._stx, 0);
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
    assert.ok(ui._stx >= 400 && ui._stx <= 1000);
});

test('dish and price-list filters use real category data and reset the carousel', () => {
    const { ui } = experienceHarness();
    ui._sx = 800;
    ui._stx = 800;
    ui.pickBoardCat('pares')();
    ui.componentDidUpdate();
    assert.equal(ui._stx, 0);
    assert.equal(ui._sx, 0);
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
    assert.ok(
        !ui
            .renderVals()
            .boardChips.some(
                (chip: { label: string }) => chip.label === 'Drinks',
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
    assert.match(
        phone,
        /\.landing-v3 \.v3-s155 \{\s*font-size: clamp\(46px, 13vw, 56px\);/,
    );
    assert.match(
        css,
        /\.landing-v3 \.v3-s155 \{[^}]*font-size: clamp\(64px, 14vw, 220px\);/,
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
