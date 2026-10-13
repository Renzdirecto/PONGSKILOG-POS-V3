import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import ts from 'typescript';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import * as reviewLib from '../resources/js/lib/customer-reviews.ts';
import {
    REVIEW_NAME_MAX,
    advanceReviewScroll,
    reviewCharacterCount,
    reviewDateLabel,
    reviewFileError,
    reviewSubmitFailure,
    reviewSwipeStep,
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
    assert.equal(advanceReviewScroll(100, 500, 16, 1).position, 100.336);
    /** Manual QA 2: roughly 15-20% faster than the first release (0.018 px per ms). */
    const speed = advanceReviewScroll(0, 500, 10, 1).position / 10 / 0.018;
    assert.ok(speed >= 1.15 && speed <= 1.2, String(speed));
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
        compiled +
            '; exports.WriteReview = WriteReview; exports.ReviewPhotoViewer = ReviewPhotoViewer;',
    )((name: string) => {
        if (name === 'react') return react;
        if (name === 'react/jsx-runtime') return jsx;
        if (name === '@/lib/customer-reviews') return reviewLib;
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
            return {
                qrError: (failure: any) => ({
                    status: failure?.response?.status ?? 0,
                    message: 'Invalid',
                }),
            };
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

test('a review card reads picture name and stars, then the review, then date and visit type', () => {
    const exports = exportsFor(React, require('react/jsx-runtime'));
    const render = (overrides: Record<string, unknown>) =>
        renderToStaticMarkup(
            React.createElement(
                exports.ReviewCard as React.ComponentType<any>,
                { review: { ...review, ...overrides } },
            ),
        );
    const html = render({
        preferred_name: 'Carlo M.',
        description: 'Solid ang tapa.',
        visit_type: 'takeout',
    });
    const [head, body, foot] = [
        'review-head',
        'review-body',
        'review-foot',
    ].map((name) => html.indexOf(`class="${name}"`));
    assert.ok(head > -1 && head < body && body < foot);
    assert.match(
        html.slice(head, body),
        /<span aria-hidden="true">C<\/span><strong title="Carlo M\.">Carlo M\.<\/strong><div class="review-stars"/,
    );
    assert.match(html.slice(body, foot), /Solid ang tapa\./);
    assert.match(
        html.slice(foot),
        /<time dateTime="2026-10-13">Oct 13, 2026<\/time><span>Takeout<\/span>/,
    );
    assert.match(render({ visit_type: 'dine_in' }), /<span>Dine-in<\/span>/);
    assert.doesNotMatch(render({ visit_type: null }), /Dine-in|Takeout/);
    assert.doesNotMatch(html, /Customer review/);
});

test('a name saved before the 10 character limit is shown whole in the data and only clipped by the layout', () => {
    const exports = exportsFor(React, require('react/jsx-runtime'));
    const name = 'Maria Cristina Dela Cruz';
    const html = renderToStaticMarkup(
        React.createElement(exports.ReviewCard as React.ComponentType<any>, {
            review: { ...review, preferred_name: name },
        }),
    );
    assert.ok(html.includes(`<strong title="${name}">${name}</strong>`));
    const css = readFileSync(
        new URL('../resources/css/customer-reviews.css', import.meta.url),
        'utf8',
    );
    const rule = css.slice(css.indexOf('.review-head strong {'));
    assert.match(rule.slice(0, rule.indexOf('}')), /text-overflow: ellipsis/);
    assert.equal(REVIEW_NAME_MAX, 10);
    assert.equal(reviewDateLabel('2026-01-05'), 'Jan 5, 2026');
    assert.equal(reviewDateLabel('not-a-date'), 'not-a-date');
});

function photoViewer(count: number, index = 0) {
    const moves: number[] = [];
    let closed = 0;
    const photos = Array.from({ length: count }, (_, photo) => ({
        url: `/reviews/a/images/${photo}`,
        kind: 'review',
        width: 1200,
        height: 900,
    }));
    const exports = exportsFor(
        { useRef: (value: any) => ({ current: value }) },
        {
            jsx: (type: any, props: any) => ({ type, props }),
            jsxs: (type: any, props: any) => ({ type, props }),
        },
    );
    const tree = exports.ReviewPhotoViewer({
        photos,
        name: 'Carlo M.',
        index,
        onIndex: (next: number) => moves.push(next),
        onClose: () => {
            closed += 1;
        },
    });
    const nodes = elements(tree);
    const labelled = (label: string) =>
        nodes.find((node) => node.props['aria-label'] === label);
    return {
        nodes,
        labelled,
        moves,
        closed: () => closed,
        content: nodes.find((node) => node.props.onTouchEnd)!,
        image: nodes.find((node) => node.type === 'img')!,
    };
}

test('the review photo viewer shows the full authorized image with close, previous and next', () => {
    const viewer = photoViewer(3, 0);
    assert.equal(viewer.image.props.src, '/reviews/a/images/0');
    assert.match(viewer.image.props.alt, /photo 1 shared by Carlo M\./);
    /** The same authorized route as the card: no public file URL, link or download. */
    assert.equal(
        viewer.nodes.some(
            (node) => node.type === 'a' || 'download' in node.props,
        ),
        false,
    );
    viewer.labelled('Next photo')!.props.onClick();
    viewer.labelled('Previous photo')!.props.onClick();
    assert.deepEqual(viewer.moves, [1, 2]);
    viewer.labelled('Close photo')!.props.onClick();
    assert.equal(viewer.closed(), 1);
    let prevented = false;
    viewer.content.props.onKeyDown({
        key: 'ArrowRight',
        preventDefault() {
            prevented = true;
        },
    });
    assert.ok(prevented);
    assert.equal(viewer.moves.at(-1), 1);

    const single = photoViewer(1);
    assert.equal(single.labelled('Next photo'), undefined);
    assert.equal(single.labelled('Previous photo'), undefined);
    assert.ok(single.labelled('Close photo'));
});

test('swiping the photo viewer changes photo while a tap or vertical drag does not', () => {
    assert.equal(reviewSwipeStep(-80, 10), 1);
    assert.equal(reviewSwipeStep(80, -10), -1);
    assert.equal(reviewSwipeStep(-20, 0), 0);
    assert.equal(reviewSwipeStep(-80, 120), 0);

    const viewer = photoViewer(3, 1);
    const swipe = (fromX: number, fromY: number, toX: number, toY: number) => {
        viewer.content.props.onTouchStart({
            touches: [{ clientX: fromX, clientY: fromY }],
        });
        viewer.content.props.onTouchEnd({
            changedTouches: [{ clientX: toX, clientY: toY }],
        });
    };
    swipe(300, 400, 180, 410);
    swipe(100, 400, 240, 395);
    swipe(200, 400, 205, 400);
    swipe(200, 300, 150, 520);
    assert.deepEqual(viewer.moves, [2, 0]);
});

test('tapping a review photo opens the viewer on that photo instead of leaving the page', () => {
    const opened: unknown[] = [];
    const exports = exportsFor(
        {
            useState: (initial: unknown) => [
                initial,
                (value: unknown) => opened.push(value),
            ],
        },
        {
            jsx: (type: any, props: any) => ({ type, props }),
            jsxs: (type: any, props: any) => ({ type, props }),
        },
    );
    const tree = exports.ReviewCard({
        review: {
            ...review,
            images: [
                { url: '/reviews/a/images/0', kind: 'profile' },
                { url: '/reviews/a/images/1', kind: 'review' },
                { url: '/reviews/a/images/2', kind: 'review' },
            ],
        },
    });
    const photos = elements(tree).filter((node) =>
        /^View photo/.test(node.props['aria-label'] ?? ''),
    );
    assert.deepEqual(
        photos.map((node) => [node.type, node.props['aria-label']]),
        [
            ['button', 'View photo 1 of 2'],
            ['button', 'View photo 2 of 2'],
        ],
    );
    photos[1].props.onClick();
    assert.deepEqual(opened, [1]);
    assert.equal(
        elements(tree).some((node) => node.props.target === '_blank'),
        false,
    );
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
        contains: (_target: unknown) => true,
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
        /** The photo viewer is a portal: a press inside it bubbles here but must never drag the rail behind it. */
        h.rail.contains = () => false;
        h.region.props.onPointerDown({
            pointerType: 'mouse',
            button: 0,
            clientX: 200,
            currentTarget: h.rail,
            target: { closest: () => null },
        });
        h.region.props.onPointerMove({
            clientX: 90,
            currentTarget: h.rail,
            pointerId: 1,
        });
        assert.equal(h.rail.scrollLeft, 150);
        h.region.props.onPointerUp();
        h.rail.contains = () => true;
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
            useState: (initial: unknown) => [
                initial,
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
            useEffect: () => {},
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
    const name = elements(tree).find(
        (element) => element.props.placeholder === 'e.g. Carlo M.',
    )!;
    name.props.onChange({ target: { value: '\u{1F600}'.repeat(12) } });
    assert.equal(
        reviewCharacterCount((form.data as any).preferred_name),
        REVIEW_NAME_MAX,
    );
    assert.equal(name.props.maxLength, undefined);
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
            useEffect: () => {},
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
    /** Nothing thanks the guest while the request is still unconfirmed. */
    assert.equal(states[2], null);
    assert.equal(
        elements(tree).some((element) => element.type === 'h3'),
        false,
    );
    resolve({ saved: true, preferred_name: 'Carlo M.' });
    await Promise.all([first, second]);
    assert.equal(states[2], 'Carlo M.');
});

test('the thank-you greets the guest by the preferred name the server saved', () => {
    let stateIndex = 0;
    const exports = exportsFor(
        {
            useState: (value: any) => [
                stateIndex++ === 2 ? 'Carlo M.' : value,
                () => {},
            ],
            useRef: (value: any) => ({ current: value }),
            useEffect: () => {},
        },
        {
            jsx: (type: any, props: any) => ({ type, props }),
            jsxs: (type: any, props: any) => ({ type, props }),
        },
        {
            data: { visibility: 'public', preferred_name: 'ignored draft' },
            errors: {},
            processing: false,
        },
    );
    const tree = exports.WriteReview({
        branches: [{ id: 'main' }],
        noticeVersion: 'v1',
        onClose() {},
    });
    const heading = elements(tree).find((element) => element.type === 'h3')!;
    assert.equal(
        [heading.props.children].flat().join(''),
        'Salamat, Carlo M.!',
    );
    assert.equal(
        elements(tree).some((element) => element.type === 'form'),
        false,
    );
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

test('the review form offers an optional email that is never required', () => {
    const form = {
        data: { description: '', visibility: 'public', customer_email: '' },
        errors: {} as Record<string, string>,
        processing: false,
        setData: (key: string, value: any) => {
            (form.data as any)[key] = value;
        },
    };
    const exports = exportsFor(
        {
            useState: (value: any) => [value, () => {}],
            useRef: (value: any) => ({ current: value }),
            useEffect: () => {},
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
    const email = elements(tree).find(
        (element) => element.props.type === 'email',
    )!;
    assert.ok(!email.props.required);
    assert.equal(email.props.autoComplete, 'email');
    assert.equal(email.props['aria-invalid'], false);
    email.props.onChange({ target: { value: 'suki@example.com' } });
    assert.equal(form.data.customer_email, 'suki@example.com');
    const submit = elements(tree).find(
        (element) => element.props.type === 'submit',
    )!;
    assert.equal(submit.props.disabled, false);
});

test('a rejected submission keeps the form open instead of thanking the customer', async () => {
    const states: any[] = [];
    let stateIndex = 0;
    const form = {
        data: { visibility: 'public', description: '' },
        errors: {},
        processing: false,
        /** useHttp resolves without a body when the server answers 422. */
        post: async () => undefined,
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
            useEffect: () => {},
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
    let scrolled = '';
    const invalidField = {
        scrollIntoView: (options: { block: string }) => {
            scrolled = options.block;
        },
    };
    await elements(tree)
        .find((element) => element.type === 'form')!
        .props.onSubmit({
            preventDefault() {},
            currentTarget: {
                querySelector: () => ({ closest: () => invalidField }),
            },
        });
    assert.equal(states[2], null);
    assert.match(states[1], /marked in red/);
    await new Promise((done) => setTimeout(done));
    assert.equal(scrolled, 'center');
});

test('a rate-limited or failed submission explains the wait keeps the form and never claims a field error', async () => {
    for (const [response, expected] of [
        [{ status: 429, headers: { 'retry-after': '1500' } }, /25 minutes/],
        [{ status: 503, headers: {} }, /could not be saved right now/],
        [undefined, /Check your connection/],
    ] as const) {
        const states: any[] = [];
        let stateIndex = 0;
        const data = {
            visibility: 'public',
            description: 'Sulit!',
            submission_key: 'stable-form-key',
        };
        const form = {
            data,
            errors: {},
            processing: false,
            post: async () => {
                throw { response };
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
                useEffect: () => {},
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
        await elements(tree)
            .find((element) => element.type === 'form')!
            .props.onSubmit({ preventDefault() {} });
        assert.equal(states[2], null);
        assert.match(states[1], expected);
        assert.doesNotMatch(states[1], /marked in red|highlighted/);
        assert.deepEqual(form.data, data);
    }
});

test('submission failures are described by their real cause', () => {
    assert.match(reviewSubmitFailure(429, '45'), /about a minute/);
    assert.match(reviewSubmitFailure(429, '3600'), /60 minutes/);
    assert.match(reviewSubmitFailure(429), /try again later/);
    assert.match(reviewSubmitFailure(419), /session expired/);
    assert.match(reviewSubmitFailure(413), /too large/);
    assert.match(reviewSubmitFailure(500), /Nothing was posted/);
});

test('Settings keeps the Reviews tab reachable on a phone and ignores a superseded review request', () => {
    const settings = readFileSync(
        new URL('../resources/js/pages/branches/index.tsx', import.meta.url),
        'utf8',
    );
    assert.match(settings, /<SegmentedTabs\s+wrap\s+label="Settings section"/);
    assert.match(settings, /\['reviews', 'Reviews'\]/);
    const panel = readFileSync(
        new URL(
            '../resources/js/components/customer-review-settings.tsx',
            import.meta.url,
        ),
        'utf8',
    );
    assert.match(panel, /!controller\.signal\.aborted/);
});

test('phone type is smaller but every form control stays at 16px so iOS does not zoom', () => {
    const css = readFileSync(
        new URL('../resources/css/customer-reviews.css', import.meta.url),
        'utf8',
    );
    const phone = css.slice(css.indexOf('@media (max-width: 600px)'));
    const controls = phone.slice(phone.indexOf('.review-form select,'));
    assert.match(controls.slice(0, controls.indexOf('}')), /font-size: 16px;/);
    const sizes = [...phone.matchAll(/font-size: ([\d.]+)px/g)].map((match) =>
        Number(match[1]),
    );
    assert.ok(Math.min(...sizes) >= 13, String(Math.min(...sizes)));
    assert.match(phone, /\.review-sheet-title \{\s*font-size: 22px;/);
    assert.match(css, /\.review-quote \{[^}]*font-size: 15px;/);
});

test('the privacy notice discloses the anonymous website counters and opens without the review form', () => {
    /** Wrapped JSX text: compare with whitespace collapsed so reformatting never breaks the check. */
    const text = source.replace(/\s+/g, ' ');
    assert.match(text, /export function ReviewLegalDialog/);
    assert.match(text, /counts page visits and taps/);
    assert.match(text, /no IP address, name or advertising tracker/);
    assert.match(text, /kept for up to 12 months/);
});
