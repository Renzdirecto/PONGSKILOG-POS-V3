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
        'Ask about Wednesday',
    );
    assert.equal(HOURS.find(({ day }) => day === 'Sunday')?.time, 'Closed');
    assert.equal(HOURS[0].time, 'Confirm today’s hours');
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
        document,
        listeners,
        cancelled,
        intersect: () => observerCallback([{ isIntersecting: true }]),
        disconnected: () => disconnected,
    };
}

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
