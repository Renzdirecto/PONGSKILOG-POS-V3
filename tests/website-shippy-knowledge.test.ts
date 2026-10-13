import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
import * as landingShippy from '../resources/js/lib/landing-shippy.ts';
import {
    NEW_SHIPPY_FAQ,
    shippyAlternatives,
    shippyDocumentProblem,
    shippyDocumentSize,
    shippyFaqForm,
    shippyFaqPayload,
    shippyFaqProblem,
    shippySource,
} from '../resources/js/lib/website-shippy.ts';
import type {
    ShippyDocumentRow,
    ShippyFaqRow,
    ShippyLimits,
} from '../resources/js/lib/website-shippy.ts';
import * as shippyLib from '../resources/js/lib/website-shippy.ts';

const source = readFileSync(
    new URL(
        '../resources/js/components/website-shippy-knowledge.tsx',
        import.meta.url,
    ),
    'utf8',
);
const settle = () => new Promise((resolve) => setImmediate(resolve));
const limits: ShippyLimits = {
    faqs: 100,
    alternatives: 8,
    category: 40,
    question: 200,
    answer: 420,
    documents: 10,
    document_kilobytes: 100,
};
const faq: ShippyFaqRow = {
    id: 7,
    question: 'Do you deliver?',
    answer: 'Wala pa kaming delivery sa ngayon.',
    category: 'Orders',
    alternatives: ['Nagdedeliver ba kayo?'],
    enabled: true,
};
const notes: ShippyDocumentRow = {
    id: 3,
    filename: 'store-notes.md',
    bytes: 2048,
    sections: 4,
    enabled: true,
    updated_at: '2026-10-14T04:00:00+00:00',
};

type Element = { type: unknown; props: Record<string, any> };
function elements(value: any): Element[] {
    if (!value || typeof value !== 'object') return [];
    if (Array.isArray(value)) return value.flatMap(elements);
    return [value, ...elements(value.props?.children)];
}
function words(value: any): string {
    if (typeof value === 'string' || typeof value === 'number')
        return String(value);
    if (Array.isArray(value)) return value.map(words).join('');
    return value?.props ? words(value.props.children) : '';
}
const text = (nodes: Element[]): string => words(nodes[0]).replace(/\s+/g, ' ');

/** One panel of Website → Shippy with hand-driven React state and a pretend server. */
function panelHarness(
    name: 'ShippyTest' | 'ShippyFaqs' | 'ShippyDocuments',
    props: Record<string, unknown>,
    reply: () => Promise<unknown> = async () => ({ saved: true }),
) {
    const compiled = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ESNext,
            jsx: ts.JsxEmit.ReactJSX,
            esModuleInterop: true,
        },
    }).outputText;
    const states: any[] = [];
    const requests: { route: string; data: any }[] = [];
    const reloads: { only: string[]; onFinish: () => void }[] = [];
    const toasts: string[] = [];
    let state = 0;
    const slot = (initial: unknown) => {
        const index = state++;
        if (!(index in states)) states[index] = initial;
        return index;
    };
    const named = new Proxy({}, { get: (_module, key) => key });
    const route = (label: string) =>
        new Proxy(
            {},
            {
                get: (_module, key) => (id?: number) =>
                    `${String(key)} ${label}${id === undefined ? '' : `/${id}`}`,
            },
        );
    const exports: Record<string, (props: any) => unknown> = {};
    new Function('require', 'exports', compiled)((module: string) => {
        if (module === 'react')
            return {
                useState: (initial: unknown) => {
                    const index = slot(initial);
                    return [
                        states[index],
                        (next: unknown) => {
                            states[index] = next;
                        },
                    ];
                },
                useRef: (initial: unknown) =>
                    states[slot({ current: initial })],
            };
        if (module === 'react/jsx-runtime')
            return {
                jsx: (type: unknown, props: unknown) => ({ type, props }),
                jsxs: (type: unknown, props: unknown) => ({ type, props }),
                Fragment: 'Fragment',
            };
        if (module === '@inertiajs/react')
            return {
                router: {
                    reload: (options: (typeof reloads)[number]) =>
                        reloads.push(options),
                },
            };
        if (module === 'sonner')
            return {
                toast: { success: (message: string) => toasts.push(message) },
            };
        if (module === '@/lib/website-shippy') return shippyLib;
        if (module === '@/lib/landing-shippy') return landingShippy;
        if (module === '@/lib/qr-http')
            return {
                qrRequest: (target: string, data: unknown) => {
                    requests.push({ route: target, data });
                    return reply();
                },
                qrError: (failure: Error) => ({ message: failure.message }),
            };
        if (module === '@/routes/workspaces/website/shippy')
            return route('shippy');
        if (module === '@/routes/workspaces/website/shippy/faqs')
            return route('faqs');
        if (module === '@/routes/workspaces/website/shippy/documents')
            return route('documents');
        return named;
    }, exports);
    const render = () => {
        state = 0;
        return elements(exports[name](props));
    };
    const button = (label: string) =>
        render().find(
            (node) =>
                node.type === 'Button' &&
                (node.props['aria-label'] === label ||
                    words(node).trim() === label),
        )!;
    return { render, requests, reloads, toasts, button };
}

test('other wordings are read one per line, without blanks or repeats, and an FAQ form becomes the request body', () => {
    assert.deepEqual(
        shippyAlternatives(
            ' Do you deliver? \n\nNagdedeliver ba kayo?\nDo you deliver?',
        ),
        ['Do you deliver?', 'Nagdedeliver ba kayo?'],
    );
    assert.deepEqual(shippyFaqForm(faq), {
        question: 'Do you deliver?',
        answer: 'Wala pa kaming delivery sa ngayon.',
        category: 'Orders',
        alternatives: 'Nagdedeliver ba kayo?',
        is_enabled: true,
    });
    assert.deepEqual(
        shippyFaqPayload({
            ...NEW_SHIPPY_FAQ,
            question: ' May parking ba? ',
            answer: ' Meron sa harap. ',
            alternatives: 'Parking?\n',
        }),
        {
            question: 'May parking ba?',
            answer: 'Meron sa harap.',
            category: '',
            alternatives: ['Parking?'],
            is_enabled: true,
        },
    );
});

test('an FAQ needs a question and an answer and at most eight other wordings', () => {
    const form = shippyFaqForm(faq);
    assert.equal(shippyFaqProblem(form, limits), '');
    assert.equal(
        shippyFaqProblem({ ...form, answer: '  ' }, limits),
        'Required: a question and its answer.',
    );
    assert.equal(
        shippyFaqProblem(
            {
                ...form,
                alternatives: Array.from(
                    { length: 9 },
                    (_, i) => `Wording ${i}`,
                ).join('\n'),
            },
            limits,
        ),
        'At most 8 other wordings.',
    );
    assert.equal(
        shippyFaqProblem({ ...form, alternatives: 'a'.repeat(201) }, limits),
        'Each other wording may be at most 200 characters.',
    );
});

test('only a small Markdown file is offered for upload', () => {
    assert.equal(
        shippyDocumentProblem({ name: 'notes.md', size: 2048 }, limits),
        '',
    );
    assert.equal(
        shippyDocumentProblem({ name: 'Notes.MARKDOWN', size: 1 }, limits),
        '',
    );
    assert.equal(
        shippyDocumentProblem({ name: 'notes.md.exe', size: 2048 }, limits),
        'notes.md.exe is not a Markdown (.md) file.',
    );
    assert.equal(
        shippyDocumentProblem({ name: 'big.md', size: 100 * 1024 + 1 }, limits),
        'big.md is larger than 100 KB.',
    );
    assert.equal(
        shippyDocumentProblem({ name: 'empty.md', size: 0 }, limits),
        'empty.md is empty.',
    );
    assert.equal(shippyDocumentSize(512), '512 B');
    assert.equal(shippyDocumentSize(2048), '2.0 KB');
});

test('every answer source has its own label and only Gemini is an AI answer', () => {
    assert.deepEqual(
        ['menu', 'faq', 'markdown', 'ai', 'fallback'].map((key) => [
            shippySource(key).label,
            shippySource(key).ai,
        ]),
        [
            ['Menu / Business', false],
            ['Instant FAQ', false],
            ['Markdown', false],
            ['Gemini', true],
            ['Fallback', false],
        ],
    );
    assert.equal(shippySource('fallback').tone, 'amber');
    assert.equal(shippySource('rules').label, 'Unknown');
    assert.equal(shippySource(undefined).ai, false);
});

test('Test Shippy sends the question to the real engine and shows the answer, its source and the server time', async () => {
    const h = panelHarness('ShippyTest', {}, async () => ({
        reply: {
            text: 'Wala pa kaming delivery sa ngayon.',
            items: [],
            actions: [],
            source: 'faq',
        },
        milliseconds: 12,
    }));
    const form = () => h.render().find((node) => node.type === 'form')!;
    const input = () => h.render().find((node) => node.type === 'Input')!;
    assert.equal(h.button('Ask Shippy').props.disabled, true);

    input().props.onChange({ target: { value: ' May delivery ba kayo? ' } });
    form().props.onSubmit({ preventDefault: () => undefined });
    form().props.onSubmit({ preventDefault: () => undefined });
    await settle();

    assert.deepEqual(h.requests, [
        { route: 'test shippy', data: { message: 'May delivery ba kayo?' } },
    ]);
    const shown = text(h.render());
    assert.match(shown, /Instant FAQ/);
    assert.match(shown, /Local answer/);
    assert.match(shown, /12 ms/);
    assert.match(shown, /Wala pa kaming delivery sa ngayon\./);
    assert.match(shown, /Your approved answer, exactly as written\./);
    assert.doesNotMatch(shown, /AI answer/);
    assert.equal(h.reloads.length, 0);
});

test('Test Shippy marks a Gemini answer as AI and lists the cards and buttons a guest would get', async () => {
    const h = panelHarness('ShippyTest', {}, async () => ({
        reply: {
            text: 'Masarap ang Tapa Silog namin!',
            items: [{ name: 'Tapa Silog', price: '₱100', img: null }],
            actions: [
                {
                    key: 'menu',
                    label: 'See the menu',
                    href: '#board',
                    external: false,
                },
            ],
            source: 'ai',
        },
        milliseconds: 3456,
    }));
    h.render()
        .find((node) => node.type === 'Input')!
        .props.onChange({ target: { value: 'Masarap ba ang tapsilog?' } });
    h.render()
        .find((node) => node.type === 'form')!
        .props.onSubmit({ preventDefault: () => undefined });
    await settle();

    const shown = text(h.render());
    assert.match(shown, /Gemini/);
    assert.match(shown, /AI answer/);
    assert.match(shown, /3,456 ms/);
    assert.match(shown, /Tapa Silog₱100/);
    assert.match(shown, /Buttons shown to the guest: See the menu/);
    assert.doesNotMatch(shown, /Local answer/);
});

test('Test Shippy shows why a question failed and no stale answer', async () => {
    const h = panelHarness('ShippyTest', {}, async () => {
        throw new Error('Too Many Attempts.');
    });
    h.render()
        .find((node) => node.type === 'Input')!
        .props.onChange({ target: { value: 'Hello' } });
    h.render()
        .find((node) => node.type === 'form')!
        .props.onSubmit({ preventDefault: () => undefined });
    await settle();

    const alert = h.render().find((node) => node.props.role === 'alert')!;
    assert.equal(words(alert), 'Too Many Attempts.');
    assert.doesNotMatch(text(h.render()), / ms/);
    /** The page keeps nothing: no storage, no polling and no saved history. */
    assert.doesNotMatch(
        source,
        /localStorage|sessionStorage|usePoll|setInterval|useEcho/,
    );
});

test('an FAQ is switched with its whole content and deleted only after a confirmation', async () => {
    const h = panelHarness('ShippyFaqs', { faqs: [faq], limits });
    const shown = text(h.render());
    assert.match(shown, /1 of up to 100 FAQs\./);
    assert.match(shown, /Also asked as: Nagdedeliver ba kayo\?/);

    h.button('Turn off the FAQ “Do you deliver?”').props.onClick();
    await settle();
    assert.deepEqual(h.requests, [
        {
            route: 'update faqs/7',
            data: {
                question: 'Do you deliver?',
                answer: 'Wala pa kaming delivery sa ngayon.',
                category: 'Orders',
                alternatives: ['Nagdedeliver ba kayo?'],
                is_enabled: false,
            },
        },
    ]);
    assert.deepEqual(h.toasts, ['FAQ turned off']);
    assert.deepEqual(
        h.reloads.map(({ only }) => only),
        [['shippy']],
    );
    h.reloads[0].onFinish();

    h.button('Delete the FAQ “Do you deliver?”').props.onClick();
    assert.equal(h.requests.length, 1);
    h.button('Delete FAQ').props.onClick();
    await settle();
    assert.equal(h.requests[1].route, 'destroy faqs/7');
});

test('at the FAQ limit no further FAQ can be added', () => {
    const h = panelHarness('ShippyFaqs', {
        faqs: [faq],
        limits: { ...limits, faqs: 1 },
    });
    assert.equal(h.button('Add FAQ').props.disabled, true);
});

test('documents show their name, state, size and date, and only Markdown files are sent', async () => {
    const h = panelHarness('ShippyDocuments', { documents: [notes], limits });
    const shown = text(h.render());
    assert.match(shown, /store-notes\.md/);
    assert.match(shown, /Active/);
    assert.match(shown, /4 sections · 2\.0 KB/);
    assert.match(shown, /Updated Oct 14, 2026, 12:00 PM/);
    assert.match(shown, /guests never get the file/);
    const chooser = () =>
        h
            .render()
            .find(
                (node) =>
                    node.type === 'input' &&
                    node.props['aria-label'] ===
                        'Choose Markdown documents to upload',
            )!;
    assert.equal(chooser().props.multiple, true);

    chooser().props.onChange({
        target: { files: [new File(['MZ'], 'virus.exe')], value: 'x' },
    });
    await settle();
    assert.equal(h.requests.length, 0);
    assert.equal(
        words(h.render().find((node) => node.props.role === 'alert')),
        'virus.exe is not a Markdown (.md) file.',
    );

    chooser().props.onChange({
        target: {
            files: [
                new File(['# Hours'], 'hours.md'),
                new File(['# Rules'], 'rules.md'),
            ],
            value: 'x',
        },
    });
    await settle();
    assert.equal(h.requests[0].route, 'store documents');
    assert.deepEqual(
        (h.requests[0].data as FormData)
            .getAll('files[]')
            .map((file) => (file as File).name),
        ['hours.md', 'rules.md'],
    );
    assert.deepEqual(h.toasts, ['2 documents uploaded']);
    /** The page has no field, link or request that could carry a document's text. */
    assert.doesNotMatch(source, /\.content\b|download|href=/);
});

test('a document is switched off, and deleted only after a confirmation', async () => {
    const h = panelHarness('ShippyDocuments', { documents: [notes], limits });

    h.button('Turn off store-notes.md').props.onClick();
    await settle();
    assert.deepEqual(h.requests, [
        { route: 'update documents/3', data: { is_enabled: false } },
    ]);
    h.reloads[0].onFinish();

    h.button('Delete store-notes.md').props.onClick();
    assert.equal(h.requests.length, 1);
    h.button('Delete document').props.onClick();
    await settle();
    assert.equal(h.requests[1].route, 'destroy documents/3');
    assert.deepEqual(h.toasts, ['Document turned off', 'Document deleted']);
});
