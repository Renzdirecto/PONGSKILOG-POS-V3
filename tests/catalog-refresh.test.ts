import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
import { createRealtimeRefresh } from '../resources/js/lib/realtime-refresh.ts';
import { POS_CATALOG_REALTIME_EVENTS, shouldRefetchCatalogAfterConnectionChange } from '../resources/js/lib/pos-catalog-realtime.ts';

const source = readFileSync(new URL('../resources/js/hooks/use-pos-catalog-realtime.ts', import.meta.url), 'utf8');

function catalogHarness() {
    const requests: { only: string[]; onFinish: () => void }[] = [];
    const cleanup: (() => void)[] = [];
    let signal = () => {};
    const code = ts.transpileModule(source.slice(source.indexOf('export function')).replace('export function', 'function'), {
        compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.None },
    }).outputText;
    const hook = new Function('dependencies', `
        const { router, createRealtimeRefresh, POS_CATALOG_REALTIME_EVENTS, shouldRefetchCatalogAfterConnectionChange, cleanup, listen } = dependencies;
        const REFRESH_DEBOUNCE_MS = 160;
        const useRef = (current) => ({ current });
        const useMemo = (callback) => callback();
        const useCallback = (callback) => callback;
        const useEffect = (callback) => { const dispose = callback(); if (dispose) cleanup.push(dispose); };
        const useConnectionStatus = () => 'connected';
        const useEcho = (_channel, _events, callback) => listen(callback);
        const handleRevalidationException = () => false;
        ${code}
        return usePosCatalogRealtime('branch-1');
    `)({
        router: { reload: (options: typeof requests[number]) => requests.push(options), flushByCacheTags: () => {} },
        createRealtimeRefresh, POS_CATALOG_REALTIME_EVENTS, shouldRefetchCatalogAfterConnectionChange, cleanup,
        listen: (callback: () => void) => { signal = callback; },
    }) as { refreshCatalog: (props?: string[]) => void };
    return { ...hook, requests, signal: () => signal(), dispose: () => cleanup.forEach((callback) => callback()) };
}

test('confirmed payment refresh and matching realtime signals share one authoritative catalog request', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const ui = catalogHarness();
    assert.equal(ui.requests.length, 0);
    ui.refreshCatalog(['loadedQr', 'storeContext']);
    ui.signal(); ui.signal();
    t.mock.timers.tick(160);
    assert.equal(ui.requests.length, 1);
    assert.deepEqual(ui.requests[0].only, ['catalog', 'loadedQr', 'storeContext']);
    ui.requests[0].onFinish();
    ui.signal();
    t.mock.timers.tick(160);
    assert.deepEqual(ui.requests[1].only, ['catalog']);
    ui.dispose();
});

test('a confirmation during an in-flight catalog request gets one trailing refresh and nothing after unmount', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const ui = catalogHarness();
    ui.signal(); t.mock.timers.tick(160);
    ui.refreshCatalog(['loadedQr', 'storeContext']);
    assert.equal(ui.requests.length, 1);
    ui.requests[0].onFinish(); t.mock.timers.tick(0);
    assert.deepEqual(ui.requests[1].only, ['catalog', 'loadedQr', 'storeContext']);
    ui.requests[1].onFinish();
    ui.signal(); ui.dispose(); t.mock.timers.tick(1000);
    assert.equal(ui.requests.length, 2);
});
