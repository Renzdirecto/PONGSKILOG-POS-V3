import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
import * as landingData from '../resources/js/lib/landing.ts';
import { CARDS, CUPS, HOURS, MENU, ULAM } from '../resources/js/lib/landing.ts';

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
    assert.equal(
        HOURS.find(({ day }) => day === 'Wednesday')?.time,
        'Usually Closed (Sometimes Open - Contact to Confirm)',
    );
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
        removeEventListener: (name: string) => listeners.push(name),
    };
    const Experience = new Function(
        'require',
        'exports',
        'window',
        'document',
        'cancelAnimationFrame',
        'IntersectionObserver',
        `${code}; return exports.default;`,
    )(
        (name: string) =>
            name === 'react'
                ? react
                : name === '@/lib/landing'
                  ? landingData
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
    ui.state.filterOpen = true;
    ui.pickBoardCat('pares')();
    ui.componentDidUpdate();
    assert.equal(ui.state.filterOpen, false);
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
    assert.equal(ui.renderVals().filterActive, true);
    assert.equal(ui.renderVals().filterOpen, false);
    assert.equal(
        ui.renderVals().boardChips.find((chip: { on: boolean }) => chip.on)
            .label,
        'Drinks',
    );
    ui.setMenuLens('pics');
    assert.equal(ui.state.boardCat, 'all');
    assert.equal(ui.state.filterOpen, false);
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

test('the map uses keyless OpenStreetMap tiles and keeps its marker, zoom control and recenter', () => {
    const { ui } = experienceHarness();
    const tiles: { url: string; options: { attribution: string } }[] = [];
    const flights: unknown[][] = [];
    let markers = 0,
        zoomControls = 0;
    const map = {
        setView: () => map,
        flyTo: (...call: unknown[]) => flights.push(call),
    };
    ui._leaflet = {
        Browser: { mobile: true },
        map: () => map,
        control: { zoom: () => ({ addTo: () => (zoomControls += 1) }) },
        tileLayer: (url: string, options: { attribution: string }) => {
            tiles.push({ url, options });
            return { addTo() {} };
        },
        divIcon: () => ({}),
        marker: () => ({ addTo: () => (markers += 1) }),
    };
    ui.mapRef.current = {};
    ui.initMap();
    assert.equal(tiles.length, 1);
    assert.equal(
        tiles[0].url,
        'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    );
    assert.doesNotMatch(tiles[0].url, /key|token|carto/i);
    assert.match(tiles[0].options.attribution, /OpenStreetMap/);
    assert.equal(markers, 1);
    assert.equal(zoomControls, 1);
    ui.recenter();
    assert.equal(flights.length, 1);
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
