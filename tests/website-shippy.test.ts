import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
import { shippyCostLabel } from '../resources/js/lib/website-shippy.ts';
import type { WebsiteShippy } from '../resources/js/lib/website-shippy.ts';
import * as shippyLib from '../resources/js/lib/website-shippy.ts';

const source = readFileSync(
    new URL(
        '../resources/js/components/website-shippy-settings.tsx',
        import.meta.url,
    ),
    'utf8',
);
const settle = () => new Promise((resolve) => setImmediate(resolve));
const shippy: WebsiteShippy = {
    enabled: true,
    configured: true,
    model: 'gemini-3.5-flash-lite',
    usage: {
        today: {
            messages: 40,
            requests: 25,
            errors: 2,
            input_tokens: 16250,
            output_tokens: 1100,
            estimated_usd: 0.0021,
        },
        month: {
            messages: 1240,
            requests: 810,
            errors: 12,
            input_tokens: 1000000,
            output_tokens: 500000,
            estimated_usd: 0.3,
        },
        days: 30,
        daily_limit: 300,
    },
    faqs: [],
    documents: [],
    limits: {
        faqs: 100,
        alternatives: 8,
        category: 40,
        question: 200,
        answer: 420,
        documents: 10,
        document_kilobytes: 100,
    },
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

/** Website → Shippy with hand-driven React state and a pretend server. */
function settingsHarness(
    data: WebsiteShippy,
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
    const requests: { route: string; data: unknown }[] = [];
    const reloads: { only: string[]; onFinish: () => void }[] = [];
    const toasts: string[] = [];
    let state = 0;
    const named = new Proxy({}, { get: (_module, key) => key });
    const exports: Record<
        string,
        (props: { shippy: WebsiteShippy }) => unknown
    > = {};
    new Function('require', 'exports', compiled)((name: string) => {
        if (name === 'react')
            return {
                useState: (initial: unknown) => {
                    const index = state++;
                    if (!(index in states)) states[index] = initial;
                    return [
                        states[index],
                        (next: unknown) => {
                            states[index] = next;
                        },
                    ];
                },
            };
        if (name === 'react/jsx-runtime')
            return {
                jsx: (type: unknown, props: unknown) => ({ type, props }),
                jsxs: (type: unknown, props: unknown) => ({ type, props }),
            };
        if (name === '@inertiajs/react')
            return {
                router: {
                    reload: (options: (typeof reloads)[number]) =>
                        reloads.push(options),
                },
            };
        if (name === 'sonner')
            return {
                toast: { success: (message: string) => toasts.push(message) },
            };
        if (name === '@/lib/website-shippy') return shippyLib;
        if (name === '@/lib/qr-http')
            return {
                qrRequest: (route: string, body: unknown) => {
                    requests.push({ route, data: body });
                    return reply();
                },
                qrError: (failure: Error) => ({ message: failure.message }),
            };
        if (name === '@/routes/workspaces/website/shippy')
            return { update: () => 'PUT website/shippy' };
        return named;
    }, exports);
    const render = () => {
        state = 0;
        return elements(exports.WebsiteShippySettings({ shippy: data }));
    };
    return {
        render,
        requests,
        reloads,
        toasts,
        toggle: () => render().find((node) => node.type === 'SettingSwitch')!,
    };
}

test('an estimated cost is never rounded down to nothing and is always in dollars', () => {
    assert.equal(shippyCostLabel(0), '$0.00');
    assert.equal(shippyCostLabel(0.0021), 'under $0.01');
    assert.equal(shippyCostLabel(0.3), '$0.30');
    assert.equal(shippyCostLabel(12.345), '$12.35');
});

test('the switch saves at once, refreshes only the Shippy figures and ignores a second press while saving', async () => {
    const h = settingsHarness(shippy);
    assert.equal(h.toggle().props.checked, true);
    assert.equal(h.toggle().props.label, 'Show Shippy on the landing page');

    h.toggle().props.onChange(false);
    assert.equal(h.toggle().props.disabled, true);
    h.toggle().props.onChange(true);
    await settle();
    assert.deepEqual(h.requests, [
        { route: 'PUT website/shippy', data: { is_enabled: false } },
    ]);
    assert.deepEqual(h.toasts, ['Shippy is switched off']);
    assert.deepEqual(
        h.reloads.map(({ only }) => only),
        [['shippy']],
    );
    h.reloads[0].onFinish();
    assert.equal(h.toggle().props.disabled, false);
});

test('a switch the server refuses shows the reason and claims nothing', async () => {
    const h = settingsHarness(shippy, async () => {
        throw new Error('This action is unauthorized.');
    });
    h.toggle().props.onChange(false);
    await settle();
    const alert = h.render().find((node) => node.props.role === 'alert')!;
    assert.equal(words(alert), 'This action is unauthorized.');
    assert.deepEqual(h.toasts, []);
    assert.equal(h.reloads.length, 0);
    assert.equal(h.toggle().props.disabled, false);
});

test('usage shows real counts for today and thirty days with the cost marked as an estimate', () => {
    const h = settingsHarness(shippy);
    const rows = h
        .render()
        .filter((node) => node.type === 'tr')
        .map((row) =>
            elements(row)
                .filter((node) => node.type === 'th' || node.type === 'td')
                .map((cell) => words(cell).replace(/\s+/g, ' ').trim()),
        );
    assert.deepEqual(rows, [
        [
            'Period',
            'Questions',
            'Gemini requests',
            'Errors',
            'Tokens in / out',
            'Estimated cost',
        ],
        ['Today', '40', '25', '2', '16,250 / 1,100', '≈ under $0.01'],
        [
            'Last 30 days',
            '1,240',
            '810',
            '12',
            '1,000,000 / 500,000',
            '≈ $0.30',
        ],
    ]);
    const text = words(h.render()).replace(/\s+/g, ' ');
    assert.match(text, /Estimated cost is an estimate, not a bill\./);
    assert.match(text, /Free-tier usage is not charged/);
    assert.match(
        text,
        /No message text and nothing about a visitor is stored\./,
    );
    assert.match(text, /300 requests/);
    assert.match(text, /On landing page/);

    /** Refresh reads the figures again on request; nothing polls. */
    const refresh = h
        .render()
        .find(
            (node) => node.type === 'Button' && words(node).includes('Refresh'),
        )!;
    refresh.props.onClick();
    assert.deepEqual(
        h.reloads.map(({ only }) => only),
        [['shippy']],
    );
    assert.equal(h.requests.length, 0);
    assert.doesNotMatch(source, /usePoll|setInterval|useEcho|setTimeout/);
});

test('the page says whether a key is configured and can never show the key itself', () => {
    const configured = words(settingsHarness(shippy).render());
    assert.ok(configured.includes('Configured on the server'));
    assert.ok(configured.includes('gemini-3.5-flash-lite'));

    const missing = words(
        settingsHarness({
            ...shippy,
            enabled: false,
            configured: false,
        }).render(),
    ).replace(/\s+/g, ' ');
    assert.ok(missing.includes('Not configured'));
    assert.ok(missing.includes('Switched off'));
    assert.match(missing, /Without a key Shippy still answers/);
    /** The component is given no key and has no field or request that could carry one. */
    assert.doesNotMatch(source, /api_key|apiKey|GEMINI_API_KEY|\.key\b/);
    assert.doesNotMatch(source, /<input|<Input/);
});
