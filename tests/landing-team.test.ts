import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
import * as landingData from '../resources/js/lib/landing.ts';
import type { TeamCard } from '../resources/js/lib/landing.ts';

/** Six cards as Website → Team sends them: three filled in, three still empty. */
const TEAM: TeamCard[] = ['CEO', 'COO', 'CTO', 'CFO', 'CHEF', 'CMO'].map(
    (code, index) => ({
        id: index + 1,
        code,
        title: `${code} role`,
        name: index % 2 ? `Person ${index}` : null,
        bio: index % 2 ? `Bio ${index}` : null,
        img: index % 2 ? `/landing-team/${index + 1}/photo?v=abc` : null,
        visible: true,
    }),
);

const compiled = ts.transpileModule(
    readFileSync(
        new URL('../resources/js/components/landing-team.tsx', import.meta.url),
        'utf8',
    ),
    {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ESNext,
            jsx: ts.JsxEmit.ReactJSX,
            esModuleInterop: true,
        },
    },
).outputText;

type Element = { type: string; props: Record<string, any> };
function elements(value: any): Element[] {
    if (!value || typeof value !== 'object') return [];
    if (Array.isArray(value)) return value.flatMap(elements);
    return [value, ...elements(value.props?.children)];
}

/** The team rail with six 300 px cards 20 px apart in a 400 px window, driven frame by frame. */
function teamHarness(reduced = false) {
    const refs: { current: any }[] = [];
    const effects: (() => (() => void) | void)[] = [];
    const exports: Record<string, (props: { members: TeamCard[] }) => unknown> =
        {};
    new Function('require', 'exports', compiled)((name: string) => {
        if (name === 'react')
            return {
                useRef: (value: unknown) => {
                    const ref = { current: value };
                    refs.push(ref);
                    return ref;
                },
                useEffect: (effect: () => (() => void) | void) =>
                    effects.push(effect),
            };
        if (name === 'react/jsx-runtime')
            return {
                jsx: (type: unknown, props: unknown) => ({ type, props }),
                jsxs: (type: unknown, props: unknown) => ({ type, props }),
            };
        if (name === '@/lib/landing') return landingData;
        throw new Error(`Unexpected import ${name}`);
    }, exports);
    const tree = exports.LandingTeam({ members: TEAM });
    const glides: { left: number; behavior: string }[] = [];
    const rail = {
        scrollLeft: 0,
        scrollWidth: 1900,
        clientWidth: 400,
        style: {} as Record<string, string>,
        querySelectorAll: () =>
            [0, 320, 640, 960, 1280, 1600].map((offsetLeft) => ({
                offsetLeft,
            })),
        scrollTo(options: { left: number; behavior: string }) {
            glides.push(options);
            rail.scrollLeft = options.left;
        },
        setPointerCapture() {},
        addEventListener() {},
        removeEventListener() {},
        matches: (_selector: string) => false,
    };
    const bar = { style: { transform: '' } };
    refs[0].current = rail;
    refs[1].current = bar;
    let callback: ((time: number) => void) | null = null;
    let cancelled = 0;
    const media = {
        matches: reduced,
        addEventListener() {},
        removeEventListener() {},
    };
    const previous = {
        window: globalThis.window,
        document: globalThis.document,
        IntersectionObserver: globalThis.IntersectionObserver,
        requestAnimationFrame: globalThis.requestAnimationFrame,
        cancelAnimationFrame: globalThis.cancelAnimationFrame,
    };
    Object.assign(globalThis, {
        window: {
            matchMedia: () => media,
            addEventListener() {},
            removeEventListener() {},
        },
        document: {
            hidden: false,
            addEventListener() {},
            removeEventListener() {},
        },
        IntersectionObserver: class {
            constructor(
                seen: (entries: { isIntersecting: boolean }[]) => void,
            ) {
                seen([{ isIntersecting: true }]);
            }
            observe() {}
            disconnect() {}
        },
        requestAnimationFrame: (next: (time: number) => void) => {
            callback = next;
            return 1;
        },
        cancelAnimationFrame: () => {
            cancelled += 1;
        },
    });
    const cleanup = effects[0]();
    /** Frame times follow the real clock the component reads when a visitor lets go of the rail. */
    const started = performance.now();
    return {
        tree,
        rail,
        bar,
        glides,
        region: elements(tree).find((node) => node.props.role === 'region')!,
        running: () => callback !== null,
        frame: (milliseconds: number) => callback?.(started + milliseconds),
        dispose() {
            cleanup?.();
            Object.assign(globalThis, previous);
            return cancelled;
        },
    };
}

test('the team rail slides by itself, pauses while hovered or touched and resumes afterward', () => {
    const h = teamHarness();
    try {
        /** The progress bar starts at the share of the rail already in view. */
        assert.equal(h.bar.style.transform, 'scaleX(0.2105)');
        h.frame(0);
        h.frame(16);
        const sliding = h.rail.scrollLeft;
        assert.ok(sliding > 0 && sliding < 1, String(sliding));

        h.region.props.onPointerEnter({ pointerType: 'mouse' });
        h.frame(32);
        h.frame(2000);
        assert.equal(h.rail.scrollLeft, sliding);
        h.region.props.onPointerLeave({ pointerType: 'mouse' });
        h.frame(2016);
        const resumed = h.rail.scrollLeft;
        assert.ok(resumed > sliding);

        /** A finger on the rail holds it; letting go gives the swipe a short rest before the slide continues. */
        h.region.props.onTouchStart();
        h.frame(2032);
        assert.equal(h.rail.scrollLeft, resumed);
        h.region.props.onTouchEnd();
        h.frame(2048);
        assert.equal(h.rail.scrollLeft, resumed);
        h.frame(2048 + 60_000);
        h.frame(2064 + 60_000);
        assert.ok(h.rail.scrollLeft > resumed);

        /** A finger scrolling the page does not hover: only a mouse pointer pauses on enter. */
        const before = h.rail.scrollLeft;
        h.region.props.onPointerEnter({ pointerType: 'touch' });
        h.frame(2080 + 60_000);
        assert.ok(h.rail.scrollLeft > before);
    } finally {
        assert.ok(h.dispose() > 0);
    }
});

test('the team rail returns to the first card after the last one and rests there', () => {
    const h = teamHarness();
    try {
        h.frame(0);
        h.rail.scrollLeft = 1500;
        h.frame(16);
        assert.deepEqual(h.glides, [{ left: 0, behavior: 'smooth' }]);
        h.frame(32);
        h.frame(1000);
        assert.equal(h.rail.scrollLeft, 0);
        h.frame(2000);
        h.frame(2016);
        assert.ok(h.rail.scrollLeft > 0);
    } finally {
        h.dispose();
    }
});

test('reduced motion keeps the team rail still and its arrows and keys move it without animation', () => {
    const h = teamHarness(true);
    try {
        assert.equal(h.running(), false);
        const [previous, next] = elements(h.tree).filter(
            (node) => node.type === 'button',
        );
        assert.equal(previous.props['aria-label'], 'Previous team member');
        assert.equal(next.props['aria-label'], 'Next team member');
        next.props.onClick();
        assert.deepEqual(h.glides.at(-1), { left: 320, behavior: 'auto' });
        let prevented = false;
        h.region.props.onKeyDown({
            key: 'ArrowRight',
            preventDefault() {
                prevented = true;
            },
        });
        assert.ok(prevented);
        assert.deepEqual(h.glides.at(-1), { left: 640, behavior: 'auto' });
        /** Past either end the arrows wrap around, like the rail does by itself. */
        h.rail.scrollLeft = 1500;
        next.props.onClick();
        assert.equal(h.glides.at(-1)!.left, 0);
        previous.props.onClick();
        assert.equal(h.glides.at(-1)!.left, 1500);
    } finally {
        h.dispose();
    }
});

test('a mouse drags the team rail and lands on a card while a finger is left to swipe natively', () => {
    const h = teamHarness();
    try {
        const pointer = {
            pointerType: 'mouse',
            button: 0,
            pointerId: 7,
            currentTarget: h.rail,
        };
        h.region.props.onPointerDown({ ...pointer, clientX: 300 });
        /** A press that barely moves is a click, not a drag. */
        h.region.props.onPointerMove({ ...pointer, clientX: 297 });
        assert.equal(h.rail.scrollLeft, 0);
        h.region.props.onPointerMove({ ...pointer, clientX: 100 });
        assert.equal(h.rail.scrollLeft, 200);
        assert.equal(h.rail.style.cursor, 'grabbing');
        /** The rail does not slide under the pointer while it is being dragged. */
        h.frame(0);
        h.frame(16);
        assert.equal(h.rail.scrollLeft, 200);
        h.region.props.onPointerUp();
        assert.equal(h.rail.style.cursor, '');
        const landed = h.glides.at(-1)!;
        assert.equal(landed.behavior, 'smooth');
        assert.equal(landed.left % 320, 0);
        assert.ok(landed.left > 0);

        h.rail.scrollLeft = 0;
        h.region.props.onPointerDown({
            ...pointer,
            pointerType: 'touch',
            clientX: 300,
        });
        h.region.props.onPointerMove({
            ...pointer,
            pointerType: 'touch',
            clientX: 100,
        });
        assert.equal(h.rail.scrollLeft, 0);
    } finally {
        h.dispose();
    }
});

test('keyboard focus pauses the team rail but a mouse press or tap that focuses it does not', () => {
    const h = teamHarness();
    try {
        h.frame(0);
        h.region.props.onFocus({ currentTarget: h.rail });
        h.frame(16);
        assert.ok(h.rail.scrollLeft > 0);

        const before = h.rail.scrollLeft;
        h.rail.matches = (selector) => selector === ':focus-visible';
        h.region.props.onFocus({ currentTarget: h.rail });
        h.frame(32);
        assert.equal(h.rail.scrollLeft, before);
        h.region.props.onBlur();
        h.frame(48);
        assert.ok(h.rail.scrollLeft > before);
    } finally {
        h.dispose();
    }
});

test('team cards show supplied portraits and clearly mark what has not been supplied', () => {
    const h = teamHarness(true);
    try {
        const cards = elements(h.tree).filter(
            (node) => node.props.className === 'team-card',
        );
        assert.equal(cards.length, 6);
        for (const [index, card] of cards.entries()) {
            const member = TEAM[index];
            const inside = elements(card);
            const text = (className: string) =>
                inside.find((node) => node.props.className === className)!.props
                    .children;
            assert.equal(text('team-code'), member.code);
            assert.equal(text('team-title'), member.title);
            const photo = inside.find((node) => node.type === 'img');
            const name = inside.find((node) => node.type === 'h3')!.props
                .children;
            const bio = inside.find((node) => node.type === 'p')!.props
                .children;
            if (member.img) {
                assert.equal(photo!.props.src, member.img);
                assert.equal(
                    photo!.props.alt,
                    `${member.name}, ${member.title}`,
                );
                assert.equal(photo!.props.loading, 'lazy');
                assert.equal(name, member.name);
                assert.equal(bio, member.bio);
            } else {
                assert.equal(photo, undefined);
                assert.ok(
                    inside.some((node) =>
                        [node.props.children]
                            .flat()
                            .includes('Photo to follow'),
                    ),
                );
                assert.equal(name, 'Name to follow');
                assert.equal(bio, 'Details to follow.');
            }
        }
    } finally {
        h.dispose();
    }
});

test('the team section is left out while every card is hidden', () => {
    const exports: Record<string, (props: { members: TeamCard[] }) => unknown> =
        {};
    new Function('require', 'exports', compiled)((name: string) => {
        if (name === 'react')
            return {
                useRef: (current: unknown) => ({ current }),
                useEffect() {},
            };
        if (name === 'react/jsx-runtime')
            return {
                jsx: (type: unknown, props: unknown) => ({ type, props }),
                jsxs: (type: unknown, props: unknown) => ({ type, props }),
            };
        if (name === '@/lib/landing') return landingData;
        throw new Error(`Unexpected import ${name}`);
    }, exports);
    assert.equal(exports.LandingTeam({ members: [] }), null);
});
