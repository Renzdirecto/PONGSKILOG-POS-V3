import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import type { TestContext } from 'node:test';
import ts from 'typescript';
import * as loveLib from '../resources/js/lib/landing-love.ts';
import {
    LOVE_MAX_TAPS,
    LOVE_SEND_EVERY_MS,
    createLoveQueue,
    loveRetryable,
} from '../resources/js/lib/landing-love.ts';
import type {
    LoveBatch,
    LoveResult,
} from '../resources/js/lib/landing-love.ts';

const settle = () => new Promise((resolve) => setImmediate(resolve));

/** A tap queue against a pretend server that starts at `total`; `replies` scripts what the next requests answer. */
function loveQueue(t: TestContext, total = 100) {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    const sent: LoveBatch[] = [];
    const shown: number[] = [];
    const replies: LoveResult[] = [];
    let server = total;
    let keys = 0;
    const queue = createLoveQueue(
        total,
        async (batch) => {
            sent.push({ ...batch });
            const scripted = replies.shift();
            if (scripted) return scripted;
            server += batch.taps;
            return { total: server };
        },
        () => `key-${++keys}`,
        (count) => shown.push(count),
    );
    return {
        queue,
        sent,
        shown,
        replies,
        async wait(milliseconds: number) {
            t.mock.timers.tick(milliseconds);
            await settle();
        },
    };
}

test('the first tap is saved at once and quick taps after it travel as one batch', async (t) => {
    const { queue, sent, shown, wait } = loveQueue(t);
    queue.tap();
    assert.deepEqual(shown, [101]);
    await wait(0);
    assert.deepEqual(sent, [{ key: 'key-1', taps: 1 }]);

    for (let tap = 0; tap < 5; tap++) queue.tap();
    assert.equal(shown.at(-1), 106);
    await wait(LOVE_SEND_EVERY_MS - 1);
    assert.equal(sent.length, 1);
    await wait(1);
    assert.deepEqual(sent[1], { key: 'key-2', taps: 5 });
    assert.equal(shown.at(-1), 106);
});

test("a saved batch brings back everyone's total and the number shown never drops", async (t) => {
    const { queue, shown, replies, wait } = loveQueue(t);
    replies.push({ total: 250 });
    queue.tap();
    await wait(0);
    assert.equal(shown.at(-1), 250);

    queue.tap();
    assert.equal(shown.at(-1), 251);
    replies.push({ total: 240 });
    await wait(LOVE_SEND_EVERY_MS);
    assert.equal(shown.at(-1), 251);
});

test('one batch carries at most the server limit and the rest follow in the next', async (t) => {
    const { queue, sent, shown, wait } = loveQueue(t);
    for (let tap = 0; tap < LOVE_MAX_TAPS + 5; tap++) queue.tap();
    await wait(0);
    assert.deepEqual(
        sent.map(({ taps }) => taps),
        [LOVE_MAX_TAPS],
    );
    await wait(LOVE_SEND_EVERY_MS);
    assert.deepEqual(
        sent.map(({ taps }) => taps),
        [LOVE_MAX_TAPS, 5],
    );
    assert.equal(shown.at(-1), 100 + LOVE_MAX_TAPS + 5);
});

test('a batch that did not get through is sent again with the same key so it counts once', async (t) => {
    const { queue, sent, shown, replies, wait } = loveQueue(t);
    replies.push({ failed: 0 }, { failed: 503 });
    queue.tap();
    queue.tap();
    await wait(0);
    await wait(LOVE_SEND_EVERY_MS);
    await wait(LOVE_SEND_EVERY_MS);
    assert.deepEqual(sent, [
        { key: 'key-1', taps: 2 },
        { key: 'key-1', taps: 2 },
        { key: 'key-1', taps: 2 },
    ]);
    assert.equal(shown.at(-1), 102);
    await wait(LOVE_SEND_EVERY_MS * 5);
    assert.equal(sent.length, 3);
});

test('after three failed attempts the taps stay on this screen and new taps start a new batch', async (t) => {
    const { queue, sent, shown, replies, wait } = loveQueue(t);
    replies.push({ failed: 0 }, { failed: 0 }, { failed: 0 });
    queue.tap();
    await wait(0);
    await wait(LOVE_SEND_EVERY_MS);
    await wait(LOVE_SEND_EVERY_MS);
    await wait(LOVE_SEND_EVERY_MS * 5);
    assert.equal(sent.length, 3);
    assert.equal(shown.at(-1), 101);

    queue.tap();
    assert.equal(shown.at(-1), 102);
    await wait(LOVE_SEND_EVERY_MS);
    assert.deepEqual(sent.at(-1), { key: 'key-2', taps: 1 });
    /** The server counted only the second tap (101); the first stays visible here, so nothing goes backwards. */
    assert.equal(shown.at(-1), 102);
});

test('a refused batch is never retried', async (t) => {
    const { queue, sent, shown, replies, wait } = loveQueue(t);
    replies.push({ failed: 429 });
    queue.tap();
    await wait(0);
    await wait(LOVE_SEND_EVERY_MS * 5);
    assert.equal(sent.length, 1);
    assert.equal(shown.at(-1), 101);
    assert.equal(loveRetryable(429), false);
    assert.equal(loveRetryable(422), false);
    assert.equal(loveRetryable(419), false);
    assert.equal(loveRetryable(0), true);
    assert.equal(loveRetryable(500), true);
});

test('a request that throws is treated as a lost connection and leaving the page stops sending', async (t) => {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    const keys: string[] = [];
    let calls = 0;
    const queue = createLoveQueue(
        0,
        async (batch) => {
            keys.push(batch.key);
            calls += 1;
            throw new Error('offline');
        },
        () => 'only-key',
        () => {},
    );
    queue.tap();
    t.mock.timers.tick(0);
    await settle();
    t.mock.timers.tick(LOVE_SEND_EVERY_MS);
    await settle();
    assert.deepEqual(keys, ['only-key', 'only-key']);

    queue.dispose();
    queue.tap();
    t.mock.timers.tick(LOVE_SEND_EVERY_MS * 5);
    await settle();
    assert.equal(calls, 2);
});

const source = readFileSync(
    new URL('../resources/js/components/landing-love.tsx', import.meta.url),
    'utf8',
);
const compiled = ts.transpileModule(source, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ESNext,
        jsx: ts.JsxEmit.ReactJSX,
        esModuleInterop: true,
    },
}).outputText;

type Element = { type: string; props: Record<string, any> };
function elements(value: any): Element[] {
    if (!value || typeof value !== 'object') return [];
    if (Array.isArray(value)) return value.flatMap(elements);
    return [value, ...elements(value.props?.children)];
}

/** The tap-to-love widget with hand-driven React state, a pretend server and a page that records floating hearts. */
function loveHarness(t: TestContext, total: number, reduced = false) {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    const states: any[] = [];
    const refs: { current: any }[] = [];
    const effects: (() => (() => void) | void)[] = [];
    const requests: { route: unknown; data: LoveBatch }[] = [];
    const hearts: { removed: boolean }[] = [];
    const animated: string[] = [];
    let position = 0;
    let reference = 0;
    let server = total;
    const exports: Record<string, (props: { total: number }) => unknown> = {};
    new Function('require', 'exports', compiled)((name: string) => {
        if (name === 'react')
            return {
                useState: (initial: unknown) => {
                    const index = position++;
                    if (!(index in states)) states[index] = initial;
                    return [
                        states[index],
                        (next: unknown) => {
                            states[index] = next;
                        },
                    ];
                },
                /** Like React, a ref is the same object on every render. */
                useRef: (value: unknown) => {
                    const index = reference++;
                    refs[index] ??= { current: value };
                    return refs[index];
                },
                useEffect: (effect: () => (() => void) | void) =>
                    effects.push(effect),
            };
        if (name === 'react/jsx-runtime')
            return {
                jsx: (type: unknown, props: unknown) => ({ type, props }),
                jsxs: (type: unknown, props: unknown) => ({ type, props }),
            };
        if (name === '@/lib/landing-love') return loveLib;
        if (name === '@/lib/client-uuid')
            return { createClientUuid: () => `batch-${requests.length + 1}` };
        if (name === '@/routes/landing-loves')
            return {
                store: () => ({ url: '/landing-loves', method: 'post' }),
            };
        if (name === '@/lib/qr-http')
            return {
                qrRequest: async (route: unknown, data: LoveBatch) => {
                    requests.push({ route, data: { ...data } });
                    server += data.taps;
                    return { total: server };
                },
                qrError: () => ({ status: 0, message: '' }),
            };
        throw new Error(`Unexpected import ${name}`);
    }, exports);
    const render = () => {
        position = 0;
        reference = 0;
        return elements(exports.LandingLove({ total }));
    };
    const first = render();
    const chef = {
        animate: () => animated.push('chef'),
        getBoundingClientRect: () => ({
            left: 16,
            top: 700,
            width: 60,
            height: 60,
        }),
    };
    refs[0].current = chef;
    refs[1].current = { animate: () => animated.push('count') };
    const previous = {
        window: globalThis.window,
        document: globalThis.document,
        navigator: Object.getOwnPropertyDescriptor(globalThis, 'navigator'),
    };
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: { vibrate: () => true },
    });
    Object.assign(globalThis, {
        window: { matchMedia: () => ({ matches: reduced }) },
        document: {
            createElement: () => {
                const heart = {
                    removed: false,
                    style: {},
                    dataset: {},
                    innerHTML: '',
                    setAttribute() {},
                    animate: () => ({}),
                    remove() {
                        heart.removed = true;
                    },
                };
                return heart;
            },
            body: {
                appendChild: (heart: { removed: boolean }) =>
                    hearts.push(heart),
            },
            querySelectorAll: () => hearts,
        },
    });
    const cleanup = effects[0]();
    return {
        first,
        render,
        requests,
        hearts,
        animated,
        async wait(milliseconds: number) {
            t.mock.timers.tick(milliseconds);
            await settle();
        },
        dispose() {
            cleanup?.();
            Object.assign(globalThis, {
                window: previous.window,
                document: previous.document,
            });
            if (previous.navigator)
                Object.defineProperty(
                    globalThis,
                    'navigator',
                    previous.navigator,
                );
        },
    };
}
/** The text of a rendered node, read in order. */
function words(value: any): string {
    if (typeof value === 'string') return value;
    if (Array.isArray(value)) return value.map(words).join('');
    return value?.props ? words(value.props.children) : '';
}
const chefButton = (nodes: Element[]) =>
    nodes.find(
        (node) => node.props['aria-label'] === 'Tap to love PONGSKILOG',
    )!;
const invitation = (nodes: Element[]) =>
    nodes.find((node) => node.props.className === 'love-invite');
const counted = (nodes: Element[]) =>
    elements(nodes.find((node) => node.props.className === 'love-count'))
        .map((node) => node.props.children)
        .filter((children) => typeof children === 'string');

test('the chef shows the shared total from the server and a tap saves to it', async (t) => {
    const h = loveHarness(t, 1234);
    try {
        assert.equal(chefButton(h.first).type, 'button');
        assert.deepEqual(counted(h.first), ['1,234']);
        assert.equal(
            words(h.first.find((node) => node.props.className === 'sr-only')),
            '1,234 taps of love',
        );
        assert.equal(
            h.first.find((node) => node.props.className === 'love-count')!
                .props['aria-live'],
            'polite',
        );

        chefButton(h.first).props.onClick();
        assert.deepEqual(counted(h.render()), ['1,235']);
        await h.wait(0);
        assert.deepEqual(h.requests, [
            {
                route: { url: '/landing-loves', method: 'post' },
                data: { key: 'batch-1', taps: 1 },
            },
        ]);
        assert.deepEqual(counted(h.render()), ['1,235']);
        /** The chef and the count pulse, and at least one heart floats up and removes itself from the page. */
        assert.deepEqual(h.animated, ['chef', 'count']);
        assert.ok(h.hearts.length >= 1 && h.hearts.length <= 2);
    } finally {
        h.dispose();
    }
    assert.ok(h.hearts.every((heart) => heart.removed));
});

test('the Tap tap invitation appears shortly after every page load and can be dismissed without loving', async (t) => {
    const h = loveHarness(t, 0);
    try {
        assert.equal(invitation(h.first), undefined);
        await h.wait(1399);
        assert.equal(invitation(h.render()), undefined);
        await h.wait(1);
        const invite = invitation(h.render())!;
        const [accept, dismiss] = elements(invite).filter(
            (node) => node.type === 'button',
        );
        assert.equal(
            words(accept),
            'Tap tap! I-tap si chef para i-love ang PONGSKILOG.',
        );
        assert.equal(dismiss.props['aria-label'], 'Dismiss');

        dismiss.props.onClick();
        assert.equal(invitation(h.render()), undefined);
        assert.equal(h.requests.length, 0);
    } finally {
        h.dispose();
    }
    /** Nothing remembers a dismissal, so a refresh invites again exactly as V6 does. */
    assert.doesNotMatch(source, /localStorage|sessionStorage|cookie/i);
});

test('tapping the Tap tap invitation loves the page and closes it', async (t) => {
    const h = loveHarness(t, 0);
    try {
        await h.wait(1400);
        const accept = elements(invitation(h.render())).find(
            (node) => node.type === 'button',
        )!;
        accept.props.onClick();
        assert.equal(invitation(h.render()), undefined);
        await h.wait(0);
        assert.equal(h.requests.length, 1);
    } finally {
        h.dispose();
    }
});

test('reduced motion still counts a tap but floats no hearts and pulses nothing', async (t) => {
    const h = loveHarness(t, 7, true);
    try {
        chefButton(h.first).props.onClick();
        await h.wait(0);
        assert.deepEqual(counted(h.render()), ['8']);
        assert.equal(h.requests.length, 1);
        assert.deepEqual(h.animated, []);
        assert.equal(h.hearts.length, 0);
    } finally {
        h.dispose();
    }
});
