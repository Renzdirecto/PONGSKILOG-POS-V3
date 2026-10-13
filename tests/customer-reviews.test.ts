import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import ts from 'typescript';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import {
    advanceReviewScroll,
    reviewCharacterCount,
    reviewFileError,
} from '../resources/js/lib/customer-reviews.ts';

const require = createRequire(import.meta.url);
const source = readFileSync(
    new URL('../resources/js/components/customer-reviews.tsx', import.meta.url),
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
const review = {
    id: 'a',
    preferred_name: '<script>alert(1)</script>',
    rating: 4,
    date: '2026-10-13',
    description: '<img src=x onerror=alert(1)> ' + 'A'.repeat(150),
    purchased_items: '<iframe>food</iframe>',
    visit_type: null,
    images: [],
};

test('review text is counted by Unicode characters and image selection enforces original limits', () => {
    assert.equal(reviewCharacterCount('\u00e9\u{1F600}'), 2);
    assert.equal(
        reviewFileError([{ size: 5 * 1024 * 1024, type: 'image/jpeg' }], 3),
        null,
    );
    assert.match(
        reviewFileError(
            [{ size: 5 * 1024 * 1024 + 1, type: 'image/jpeg' }],
            3,
        ) ?? '',
        /5 MB/,
    );
    assert.match(
        reviewFileError(
            Array.from({ length: 4 }, () => ({
                size: 100,
                type: 'image/webp',
            })),
            3,
        ) ?? '',
        /at most 3/,
    );
    assert.match(
        reviewFileError([{ size: 100, type: 'image/svg+xml' }], 3) ?? '',
        /JPEG, PNG or WebP/,
    );
});

test('automatic carousel movement is slow bounded and reverses at either edge', () => {
    assert.equal(advanceReviewScroll(100, 500, 16, 1).position, 100.288);
    assert.deepEqual(advanceReviewScroll(500, 500, 16, 1), {
        position: 500,
        direction: -1,
    });
    assert.deepEqual(advanceReviewScroll(0, 500, 16, -1), {
        position: 0,
        direction: 1,
    });
    assert.ok(advanceReviewScroll(100, 500, 10000, 1).position < 102);
});

function exportsFor(react: unknown, jsx: unknown, httpForm?: any) {
    const module = {
        exports: {} as Record<
            string,
            (props: Record<string, unknown>) => unknown
        >,
    };
    new Function(
        'require',
        'exports',
        compiled + '; exports.WriteReview = WriteReview;',
    )((name: string) => {
        if (name === 'react') return react;
        if (name === 'react/jsx-runtime') return jsx;
        if (name === '@/lib/customer-reviews')
            return {
                advanceReviewScroll,
                reviewCharacterCount,
                reviewFileError,
            };
        if (name === '@/lib/landing')
            return {
                FB: 'https://www.facebook.com/profile.php?id=100094575655003',
            };
        if (name === '@/lib/client-uuid')
            return { createClientUuid: () => 'stable-form-key' };
        if (name === '@/routes/reviews')
            return { store: { url: () => '/reviews' } };
        if (name === '@inertiajs/react')
            return {
                useHttp: (initial: any) =>
                    httpForm ?? {
                        data: initial,
                        errors: {},
                        processing: false,
                    },
            };
        if (name === '@/lib/qr-http')
            return { qrError: () => ({ status: 422, message: 'Invalid' }) };
        if (name.startsWith('@/components/')) return {};
        if (name.endsWith('.css')) return {};
        throw new Error(`Unexpected import ${name}`);
    }, module.exports);
    return module.exports;
}

test('review cards escape customer text and describe purchases as self-reported', () => {
    const exports = exportsFor(React, require('react/jsx-runtime'));
    const html = renderToStaticMarkup(
        React.createElement(exports.ReviewCard as React.ComponentType<any>, {
            review,
            management: true,
        }),
    );
    assert.ok(html.includes('&lt;script&gt;'));
    assert.ok(html.includes('&lt;img'));
    assert.ok(html.includes('&lt;iframe&gt;'));
    assert.ok(!html.includes('<script>') && !html.includes('<iframe>'));
    assert.match(html, /self-reported/);
    assert.match(html, /4 out of 5 stars/);
});

type Element = { type: string; props: Record<string, any> };
function elements(value: any): Element[] {
    if (!value || typeof value !== 'object') return [];
    if (Array.isArray(value)) return value.flatMap(elements);
    return [value, ...elements(value.props?.children)];
}
function carouselHarness(reduced = false) {
    let position = 0;
    let callback: (time: number) => void = () => {};
    let cancelled = 0;
    const refs: any[] = [];
    const effects: (() => (() => void) | void)[] = [];
    const states: any[] = [];
    const media = {
        matches: reduced,
        addEventListener() {},
        removeEventListener() {},
    };
    const rail = {
        scrollLeft: 0,
        scrollWidth: 1200,
        clientWidth: 400,
        setPointerCapture() {},
    };
    const exports = exportsFor(
        {
            useState: (value: any) => {
                const index = position++;
                states[index] ??= value;
                return [
                    states[index],
                    (next: any) => {
                        states[index] = next;
                    },
                ];
            },
            useRef: (value: any) => {
                const ref = { current: value };
                refs.push(ref);
                return ref;
            },
            useEffect: (effect: any) => effects.push(effect),
        },
        {
            jsx: (type: any, props: any) => ({ type, props }),
            jsxs: (type: any, props: any) => ({ type, props }),
        },
    );
    const tree = exports.CustomerReviews({
        reviews: [review, { ...review, id: 'b' }],
        branches: [],
        noticeVersion: 'v1',
    });
    refs[0].current = rail;
    const previous = {
        window: globalThis.window,
        document: globalThis.document,
        IntersectionObserver: globalThis.IntersectionObserver,
        requestAnimationFrame: globalThis.requestAnimationFrame,
        cancelAnimationFrame: globalThis.cancelAnimationFrame,
    };
    Object.assign(globalThis, {
        window: { matchMedia: () => media },
        document: {
            hidden: false,
            addEventListener() {},
            removeEventListener() {},
        },
        IntersectionObserver: class {
            constructor(fn: any) {
                fn([{ isIntersecting: true }]);
            }
            observe() {}
            disconnect() {}
        },
        requestAnimationFrame: (fn: any) => {
            callback = fn;
            return 1;
        },
        cancelAnimationFrame: () => {
            cancelled += 1;
        },
    });
    const cleanup = effects[0]();
    const region = elements(tree).find(
        (node) => node.props['aria-roledescription'] === 'carousel',
    )!;
    return {
        rail,
        region,
        states,
        tree,
        tick: (time: number) => callback(time),
        media,
        dispose: () => {
            cleanup?.();
            Object.assign(globalThis, previous);
            return cancelled;
        },
    };
}

test('review carousel pauses on hover and touch supports mouse dragging and keyboard navigation and cleans up', () => {
    const h = carouselHarness();
    try {
        h.tick(10000);
        h.tick(10016);
        assert.ok(h.rail.scrollLeft > 0);
        h.region.props.onPointerEnter({ pointerType: 'mouse' });
        const hovered = h.rail.scrollLeft;
        h.tick(10032);
        assert.equal(h.rail.scrollLeft, hovered);
        h.region.props.onPointerLeave();
        h.tick(10048);
        assert.ok(h.rail.scrollLeft > hovered);
        h.region.props.onPointerDown({ pointerType: 'touch' });
        const touched = h.rail.scrollLeft;
        h.tick(10064);
        assert.equal(h.rail.scrollLeft, touched);
        h.region.props.onPointerCancel();
        h.rail.scrollLeft = 100;
        h.region.props.onPointerDown({
            pointerType: 'mouse',
            button: 0,
            clientX: 200,
            currentTarget: h.rail,
            target: { closest: () => null },
        });
        h.region.props.onPointerMove({
            clientX: 150,
            currentTarget: h.rail,
            pointerId: 1,
        });
        assert.equal(h.rail.scrollLeft, 150);
        h.region.props.onPointerUp();
        let prevented = false;
        h.region.props.onKeyDown({
            target: h.rail,
            currentTarget: h.rail,
            key: 'ArrowRight',
            preventDefault() {
                prevented = true;
            },
        });
        assert.ok(prevented);
        assert.ok(h.rail.scrollLeft > 150);
        const pauseButton = elements(h.tree).find(
            (node) => node.props.children === 'Pause',
        )!;
        pauseButton.props.onClick();
        assert.equal(h.states[1], false);
    } finally {
        assert.ok(h.dispose() > 0);
    }
});

test('reduced-motion preference leaves reviews still and long reviews expand on demand', () => {
    const h = carouselHarness(true);
    try {
        h.tick(10000);
        h.tick(10016);
        assert.equal(h.rail.scrollLeft, 0);
    } finally {
        h.dispose();
    }
    let expanded = false;
    const exports = exportsFor(
        {
            useState: () => [
                false,
                (value: boolean) => {
                    expanded = value;
                },
            ],
        },
        {
            jsx: (type: any, props: any) => ({ type, props }),
            jsxs: (type: any, props: any) => ({ type, props }),
        },
    );
    const tree = exports.ReviewCard({ review });
    const more = elements(tree).find(
        (node) => node.props.children === 'See More',
    )!;
    assert.equal(more.props['aria-expanded'], false);
    more.props.onClick();
    assert.equal(expanded, true);
});

test('review form caps Unicode text at 200 characters and rejects too many selected images', () => {
    const states: any[] = [];
    let stateIndex = 0;
    const form = {
        data: { description: '', visibility: 'private', acknowledgment: false },
        errors: {},
        processing: false,
        setData: (key: string, value: any) => {
            (form.data as any)[key] = value;
        },
    };
    const exports = exportsFor(
        {
            useState: (value: any) => {
                const index = stateIndex++;
                states[index] = value;
                return [
                    value,
                    (next: any) => {
                        states[index] = next;
                    },
                ];
            },
            useRef: (value: any) => ({ current: value }),
        },
        {
            jsx: (type: any, props: any) => ({ type, props }),
            jsxs: (type: any, props: any) => ({ type, props }),
        },
        form,
    );
    const tree = exports.WriteReview({
        branches: [{ id: 'main' }],
        noticeVersion: 'v1',
        onClose() {},
    });
    const text = elements(tree).find((element) => element.type === 'textarea')!;
    text.props.onChange({ target: { value: '\u{1F600}'.repeat(201) } });
    assert.equal(reviewCharacterCount(form.data.description), 200);
    const images = elements(tree).find(
        (element) => element.type === 'input' && element.props.multiple,
    )!;
    images.props.onChange({
        target: {
            files: Array.from({ length: 4 }, () => ({
                size: 100,
                type: 'image/jpeg',
            })),
        },
    });
    assert.match(states[4], /at most 3/);
});

test('the review form blocks double submit keeps its request key and reports pending approval after success', async () => {
    let calls = 0,
        resolve: (value: unknown) => void = () => {};
    const states: any[] = [];
    let stateIndex = 0;
    const form = {
        data: {
            submission_key: 'stable-form-key',
            visibility: 'public',
            description: '',
        },
        errors: {},
        processing: false,
        post: async () => {
            calls++;
            return new Promise((done) => {
                resolve = done;
            });
        },
    };
    const exports = exportsFor(
        {
            useState: (value: any) => {
                const index = stateIndex++;
                states[index] = value;
                return [
                    value,
                    (next: any) => {
                        states[index] = next;
                    },
                ];
            },
            useRef: (value: any) => ({ current: value }),
        },
        {
            jsx: (type: any, props: any) => ({ type, props }),
            jsxs: (type: any, props: any) => ({ type, props }),
        },
        form,
    );
    const tree = exports.WriteReview({
        branches: [{ id: 'main' }],
        noticeVersion: 'v1',
        onClose() {},
    });
    const submit = elements(tree).find((element) => element.type === 'form')!
        .props.onSubmit;
    const first = submit({ preventDefault() {} });
    const second = submit({ preventDefault() {} });
    assert.equal(calls, 1);
    assert.equal(form.data.submission_key, 'stable-form-key');
    resolve({ saved: true });
    await Promise.all([first, second]);
    assert.equal(states[2], true);
});

test('automatic sliding accumulates fractional motion on browsers that round scrollLeft', () => {
    const h = carouselHarness();
    let rounded = 0;
    Object.defineProperty(h.rail, 'scrollLeft', {
        get: () => rounded,
        set: (value: number) => {
            rounded = Math.round(value);
        },
        configurable: true,
    });
    try {
        for (let time = 10000; time <= 10480; time += 16) h.tick(time);
        assert.ok(h.rail.scrollLeft >= 8);
    } finally {
        h.dispose();
    }
});
