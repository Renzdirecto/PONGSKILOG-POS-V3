import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';

const source = readFileSync(
    new URL(
        '../resources/js/hooks/use-pending-transaction.ts',
        import.meta.url,
    ),
    'utf8',
);

function navigationHarness(pending: boolean) {
    let visit: (event: unknown) => void = () => {};
    let unload: (event: unknown) => void = () => {};
    let cleanup = () => {};
    let notices = 0;
    let removed = 0;
    const code = ts.transpileModule(
        source
            .slice(source.indexOf('export function'))
            .replace('export function', 'function'),
        {
            compilerOptions: {
                target: ts.ScriptTarget.ESNext,
                module: ts.ModuleKind.None,
            },
        },
    ).outputText;
    new Function(
        'dependencies',
        `const { useEffect, useRef, router, toast, window } = dependencies; ${code}; usePendingTransaction(${pending});`,
    )({
        useRef: (value: boolean) => ({ current: value }),
        useEffect: (callback: () => () => void) => {
            cleanup = callback();
        },
        router: {
            on: (_name: string, callback: typeof visit) => {
                visit = callback;
                return () => {
                    removed += 1;
                };
            },
        },
        toast: {
            info: () => {
                notices += 1;
            },
        },
        window: {
            addEventListener: (_name: string, callback: typeof unload) => {
                unload = callback;
            },
            removeEventListener: () => {
                removed += 1;
            },
        },
    });
    return {
        navigate: (async = false) => {
            let blocked = false;
            visit({
                detail: { visit: { async } },
                preventDefault: () => {
                    blocked = true;
                },
            });
            return blocked;
        },
        unload: () => {
            let blocked = false;
            unload({
                preventDefault: () => {
                    blocked = true;
                },
            });
            return blocked;
        },
        cleanup: () => {
            cleanup();
            return removed;
        },
        notices: () => notices,
    };
}

test('pending or unconfirmed transactions keep their state mounted and explain blocked navigation', () => {
    const ui = navigationHarness(true);
    assert.equal(ui.navigate(), true);
    assert.equal(ui.notices(), 1);
    assert.equal(ui.unload(), true);
    assert.equal(ui.navigate(true), false);
    assert.equal(ui.cleanup(), 2);
});

test('confirmed or rejected transactions allow navigation and leave no listeners behind', () => {
    const ui = navigationHarness(false);
    assert.equal(ui.navigate(), false);
    assert.equal(ui.unload(), false);
    assert.equal(ui.notices(), 0);
    assert.equal(ui.cleanup(), 2);
});
