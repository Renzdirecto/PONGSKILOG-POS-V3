import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import ts from 'typescript';
import { renderToStaticMarkup } from 'react-dom/server';

const require = createRequire(import.meta.url);
function component(file, mocks) {
    const source = readFileSync(
        new URL(`../resources/js/${file}`, import.meta.url),
        'utf8',
    );
    const code = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            jsx: ts.JsxEmit.ReactJSX,
        },
    }).outputText;
    const exports = {};
    // Deliberate: runs the transpiled hook module with mocked imports; the code is the repository's own source.
    // oxlint-disable-next-line typescript/no-implied-eval
    new Function('require', 'exports', code)(
        (name) => (name in mocks ? mocks[name] : require(name)),
        exports,
    );
    return exports;
}
function qrHarness() {
    const values = [];
    const dependencies = [];
    const pending = [];
    const timers = [];
    let index = 0;
    let calls = 0;
    let resolve;
    let reject;
    let promise;
    const newRequest = () => {
        promise = new Promise((yes, no) => {
            resolve = yes;
            reject = no;
        });
    };
    newRequest();
    const hooks = {
        useState(initial) {
            const slot = index++;
            if (!(slot in values)) values[slot] = initial;
            return [
                values[slot],
                (value) => {
                    values[slot] =
                        typeof value === 'function'
                            ? value(values[slot])
                            : value;
                },
            ];
        },
        useRef(initial) {
            const slot = index++;
            return (values[slot] ??= { current: initial });
        },
        useEffect(effect, deps) {
            const slot = index++;
            if (
                !dependencies[slot] ||
                deps.some((value, i) => value !== dependencies[slot][i])
            ) {
                dependencies[slot] = deps;
                pending.push(effect);
            }
        },
    };
    const { PosReceiptQr } = component('components/pos-receipt-qr.tsx', {
        react: hooks,
        'lucide-react': {
            ArrowLeft: 'span',
            Clock3: 'span',
            LoaderCircle: 'span',
        },
        '@/actions/App/Http/Controllers/ReceiptShareController': {
            store: (id) => ({
                url: `/pos/orders/${id}/receipt-share`,
                method: 'post',
            }),
        },
        '@/lib/qr-http': {
            qrRequest: (route) => {
                assert.equal(route.url, '/pos/orders/order-1/receipt-share');
                calls++;
                return promise;
            },
            qrError: (error) => error,
        },
    });
    return {
        render() {
            index = 0;
            return PosReceiptQr({
                receipt: {
                    id: 'order-1',
                    order_number: '1001',
                    reference_number: 'MAIN-REF',
                },
                onBack() {},
            });
        },
        effects(replay = false) {
            return pending.splice(0).map((effect) => {
                const cleanup = effect();
                if (replay) {
                    cleanup?.();
                    return effect();
                }
                return cleanup;
            });
        },
        success() {
            resolve({
                url: '/receipt/order-1?signature=secure',
                expires_at: new Date(Date.now() + 10000).toISOString(),
                qr_image: 'data:image/svg+xml;base64,REALQR',
            });
        },
        fail(status = 500) {
            reject({ status });
        },
        newRequest,
        calls: () => calls,
        timers,
    };
}
function findButton(element, label) {
    if (!element || typeof element !== 'object') return null;
    if (element.type === 'button' && element.props.children === label)
        return element;
    return [element.props?.children]
        .flat(Infinity)
        .map((child) => findButton(child, label))
        .find(Boolean);
}
const settle = () => new Promise((resolve) => setImmediate(resolve));

test('Show QR loads once under Strict Mode effect replay and renders the real QR, identity and expiry', async () => {
    const view = qrHarness();
    assert.match(renderToStaticMarkup(view.render()), /Loading receipt QR/);
    view.effects(true);
    assert.equal(view.calls(), 1);
    view.success();
    await settle();
    const html = renderToStaticMarkup(view.render());
    assert.match(html, /REALQR/);
    assert.match(html, /1001/);
    assert.match(html, /MAIN-REF/);
    assert.match(html, /24 hours after payment/);
    assert.doesNotMatch(html, /not available yet|Phase 6|Phase 10/);
});

test('Show QR failure displays retry without a fake QR and retry succeeds', async () => {
    const view = qrHarness();
    view.render();
    view.effects();
    view.fail();
    await settle();
    const failed = view.render();
    assert.match(renderToStaticMarkup(failed), /Unable to load/);
    assert.doesNotMatch(renderToStaticMarkup(failed), /<img/);
    view.newRequest();
    findButton(failed, 'Retry').props.onClick();
    assert.match(renderToStaticMarkup(view.render()), /Loading receipt QR/);
    view.effects();
    view.success();
    await settle();
    assert.match(renderToStaticMarkup(view.render()), /REALQR/);
    assert.equal(view.calls(), 2);
});

test('Show QR reports expired receipts without retry or QR', async () => {
    const view = qrHarness();
    view.render();
    view.effects();
    view.fail(410);
    await settle();
    const html = renderToStaticMarkup(view.render());
    assert.match(html, /Digital receipt has expired/);
    assert.doesNotMatch(html, /<img|Retry/);
});

test('shared digital receipt renders branding REF payment and items with no action buttons', () => {
    const { DigitalReceiptCard } = component(
        'components/digital-receipt-card.tsx',
        {
            '@/lib/pos-money': { pesos: (value) => `PHP ${value}` },
            './customer-qr-product': { qrPanel: '' },
        },
    );
    const receipt = {
        branch: {
            name: 'Custom branch',
            address: 'Street',
            contact: '0917',
            show_logo: true,
            logo_url: '/logo.png',
            footer: 'Thank you!',
        },
        order_number: '1001',
        reference_number: 'MAIN-REF',
        paid_at: '2026-09-22T09:00:00Z',
        order_type: 'take_out',
        customer_label: 'Customer',
        items: [
            {
                name: 'Tapsilog',
                quantity: 2,
                line_total: '190.00',
                notes: 'Less salt',
                modifiers: [
                    { name: 'Scrambled', semantic_role: 'instruction' },
                ],
            },
        ],
        total: '190.00',
        payments: [
            {
                method: 'cash',
                amount: '190.00',
                amount_received: '200.00',
                change_amount: '10.00',
            },
        ],
    };
    const html = renderToStaticMarkup(DigitalReceiptCard({ receipt }));
    for (const value of [
        'Custom branch',
        'MAIN-REF',
        '/logo.png',
        'Tapsilog',
        'Less salt',
        'Instructions:',
        'Scrambled',
        '200.00',
        '10.00',
        'Thank you!',
    ])
        assert.ok(html.includes(value), value);
    assert.doesNotMatch(html, /<button/);
    receipt.branch.show_logo = false;
    assert.doesNotMatch(
        renderToStaticMarkup(DigitalReceiptCard({ receipt })),
        /<img/,
    );
});
