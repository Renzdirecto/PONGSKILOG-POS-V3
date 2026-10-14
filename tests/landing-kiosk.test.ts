import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
import * as landing from '../resources/js/lib/landing.ts';
import { kioskBranchStatus, kioskStatus } from '../resources/js/lib/landing.ts';
import type { KioskBranch } from '../resources/js/lib/landing.ts';

const read = (path: string) =>
    readFileSync(new URL(`../resources/${path}`, import.meta.url), 'utf8');
const source = read('js/components/landing-kiosk.tsx');

const adarna: KioskBranch = {
    name: 'Adarna St.',
    address: '1511, Unit V, Purok 8 Adarna St.',
    url: '/kiosk/ADARNA',
    open: true,
};
const carmen: KioskBranch = {
    name: 'Dona Carmen',
    address: null,
    url: '/kiosk/DC',
    open: false,
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

/** The kiosk section rendered with hand-driven React state. */
function kioskHarness(branches: KioskBranch[]) {
    const compiled = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ESNext,
            jsx: ts.JsxEmit.ReactJSX,
            esModuleInterop: true,
        },
    }).outputText;
    const states: unknown[] = [];
    let state = 0;
    const exports: Record<string, (props: unknown) => unknown> = {};
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
                Fragment: 'Fragment',
            };
        if (name === '@/lib/landing') return landing;
        return new Proxy({}, { get: (_module, key) => key });
    }, exports);
    return () => {
        state = 0;
        return elements(exports.LandingKiosk({ branches }));
    };
}

test('the status beside Start your order is the real state of the Branches, or nothing without one', () => {
    assert.deepEqual(kioskStatus([adarna]), {
        label: 'Open now',
        fg: '#2E5A4B',
    });
    assert.deepEqual(kioskStatus([carmen]), {
        label: 'Closed now',
        fg: '#B0281F',
    });
    assert.deepEqual(kioskStatus([adarna, carmen]), {
        label: 'Open now at 1 of 2 branches',
        fg: '#2E5A4B',
    });
    assert.deepEqual(kioskStatus([carmen, { ...carmen, url: '/kiosk/Q' }]), {
        label: 'All branches closed now',
        fg: '#B0281F',
    });
    assert.equal(kioskStatus([]), null);
    assert.equal(kioskBranchStatus(carmen).label, 'Closed now');
});

test('Start your order always opens Choose your branch, even while every Branch is closed', () => {
    for (const branches of [[carmen], [adarna, carmen], []]) {
        const render = kioskHarness(branches);
        const start = render().find(
            (node) =>
                node.type === 'button' &&
                words(node).includes('Start your order'),
        )!;
        assert.equal('disabled' in start.props, false);
        assert.equal(
            render().find((node) => node.type === 'Dialog')!.props.open,
            false,
        );
        start.props.onClick();
        assert.equal(
            render().find((node) => node.type === 'Dialog')!.props.open,
            true,
        );
    }
});

test('each Branch opens its own public kiosk page in a new tab, with its real status', () => {
    const render = kioskHarness([adarna, carmen]);
    const links = render().filter(
        (node) => node.type === 'a' && node.props.href?.startsWith('/kiosk/'),
    );
    assert.deepEqual(
        links.map((link) => [
            link.props.href,
            link.props.target,
            words(link).includes('Open now') ? 'open' : 'closed',
        ]),
        [
            ['/kiosk/ADARNA', '_blank', 'open'],
            ['/kiosk/DC', '_blank', 'closed'],
        ],
    );
    /** A Branch without an address shows only its name; nothing is made up. */
    assert.ok(words(links[0]).includes('1511, Unit V, Purok 8 Adarna St.'));
    assert.equal(words(links[1]), 'Dona CarmenClosed now');
    assert.doesNotMatch(source, /example\.com/);
    /** The sample ticket is an illustration, never a real order. */
    const ticket = render().find(
        (node) => node.props.className === 'kiosk-ticket',
    )!;
    assert.equal(ticket.props.role, 'img');
    assert.match(words(ticket), /Sample.*#024/);

    const empty = kioskHarness([]);
    assert.ok(
        words(empty()).includes(
            "Online ordering isn't set up for any branch yet.",
        ),
    );
});

test('the branch chooser is a centred dialog that becomes a bottom sheet on a phone', () => {
    const css = read('css/landing.css');
    const phone = css.slice(css.lastIndexOf('@media (max-width: 699px)'));
    assert.match(
        phone,
        /\.kiosk-sheet \{\s*top: auto;\s*bottom: 0;\s*left: 0;\s*translate: none;\s*max-width: none;\s*border-radius: 22px 22px 0 0;/,
    );
    assert.match(css, /\.kiosk-sheet \{[^}]*max-width: 460px;/);
    assert.match(
        css,
        /\.kiosk-sheet \{[^}]*padding: 18px 18px calc\(20px \+ env\(safe-area-inset-bottom\)\);/,
    );
    assert.match(source, /overlayClassName="kiosk-overlay"/);
});
