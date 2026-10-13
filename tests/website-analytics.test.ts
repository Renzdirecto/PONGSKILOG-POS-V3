import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
import { activeManagementDestination } from '../resources/js/lib/management-navigation.ts';
import { activeSuperAdminDestination } from '../resources/js/lib/super-admin-navigation.ts';
import {
    WEBSITE_PERIODS,
    websiteAnalyticsQuery,
    websiteEventDue,
    websiteLinkEvent,
    websiteRefreshLabel,
} from '../resources/js/lib/website-analytics.ts';
import { workspaceIsLoading } from '../resources/js/lib/workspace-navigation.ts';

const read = (path: string) =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');

test('only the Facebook, directions and Menu links of the landing page are counted', () => {
    assert.equal(
        websiteLinkEvent(
            'https://www.facebook.com/profile.php?id=100094575655003',
        ),
        'facebook_click',
    );
    assert.equal(
        websiteLinkEvent('https://maps.app.goo.gl/nivpx3cxuP56wAXo8'),
        'directions_click',
    );
    assert.equal(websiteLinkEvent('#board'), 'menu_click');
    for (const href of ['#reviews', '#visit', '/login', '', null]) {
        assert.equal(websiteLinkEvent(href), null);
    }
});

test('the refresh timestamp always displays the Philippine date and clock time', () => {
    assert.equal(
        websiteRefreshLabel('2026-10-13T16:05:30Z'),
        'Oct 14, 2026, 12:05:30 AM',
    );
});

function refreshHarness() {
    const requests: Record<string, any>[] = [];
    const state: unknown[] = [];
    const listeners: Record<string, (event: any) => void> = {};
    const cleanup: (() => void)[] = [];
    const document = { visibilityState: 'visible' };
    const navigator = { onLine: true };
    let tick = () => {};
    let interval = 0;
    let cancelled = 0;
    const router = {
        reload(options: Record<string, any>) {
            if (!options.onBefore()) return;
            requests.push(options);
            options.onCancelToken({
                cancel() {
                    cancelled++;
                    options.onFinish();
                },
            });
            options.onStart();
        },
        on(name: string, callback: (event: any) => void) {
            listeners[name] = callback;
            return () => {
                delete listeners[name];
            };
        },
    };
    const source = read('hooks/use-website-analytics-refresh.ts');
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
    const hook = new Function(
        'dependencies',
        `
        const { router, document, navigator, state, cleanup, poll } = dependencies;
        const useRef = (current) => ({ current });
        const useState = (value) => { const index = state.push(value) - 1; return [value, (next) => { state[index] = next; }]; };
        const useEffect = (callback) => { cleanup.push(callback()); };
        const usePoll = poll;
        const handleRevalidationException = () => false;
        ${code}
        return useWebsiteAnalyticsRefresh();
    `,
    )({
        router,
        document,
        navigator,
        state,
        cleanup,
        poll(ms: number, options: () => Record<string, any>) {
            interval = ms;
            tick = () => router.reload(options());
            cleanup.push(() => {
                tick = () => {};
            });
        },
    }) as { refresh: () => void };
    return {
        ...hook,
        requests,
        state,
        document,
        navigator,
        listeners,
        tick: () => tick(),
        interval,
        cancelled: () => cancelled,
        dispose: () => cleanup.forEach((callback) => callback()),
    };
}

test('manual and 30-second refreshes fetch one report together without overlapping', () => {
    const ui = refreshHarness();
    assert.equal(ui.interval, 30_000);
    ui.refresh();
    ui.refresh();
    ui.tick();
    assert.equal(ui.requests.length, 1);
    assert.deepEqual(ui.requests[0].only, ['report']);
    assert.equal(ui.requests[0].showProgress, false);
    assert.equal(ui.state[0], true);
    ui.requests[0].onSuccess();
    ui.requests[0].onFinish();
    assert.equal(ui.state[0], false);
    ui.tick();
    assert.equal(ui.requests.length, 2);
    ui.dispose();
});

test('hidden or offline pages skip polling and navigation cancels the old report', () => {
    const ui = refreshHarness();
    ui.document.visibilityState = 'hidden';
    ui.tick();
    ui.document.visibilityState = 'visible';
    ui.navigator.onLine = false;
    ui.tick();
    assert.equal(ui.requests.length, 0);
    ui.navigator.onLine = true;
    ui.tick();
    ui.listeners.start({ detail: { visit: { async: false } } });
    assert.equal(ui.cancelled(), 1);
    ui.tick();
    ui.refresh();
    assert.equal(ui.requests.length, 1);
    ui.listeners.finish({ detail: { visit: { async: false } } });
    ui.tick();
    assert.equal(ui.requests.length, 2);
    ui.dispose();
    ui.tick();
    assert.equal(ui.cancelled(), 2);
    assert.equal(ui.requests.length, 2);
    assert.deepEqual(ui.listeners, {});
});

test('failed refreshes finish without replacing data or timestamps and a later success clears feedback', () => {
    const ui = refreshHarness();
    ui.refresh();
    assert.equal(ui.requests[0].onNetworkError(), false);
    ui.requests[0].onFinish();
    assert.deepEqual(ui.state, [false, true]);
    ui.refresh();
    assert.equal(ui.requests[1].onHttpException({ status: 503 }), false);
    ui.requests[1].onFinish();
    assert.deepEqual(ui.state, [false, true]);
    ui.refresh();
    ui.requests[2].onSuccess();
    ui.requests[2].onFinish();
    assert.deepEqual(ui.state, [false, false]);
    const page = read('pages/workspaces/website-analytics.tsx');
    assert.match(page, /dateTime=\{report.refreshed_at\}/);
    assert.match(page, /websiteRefreshLabel\(report.refreshed_at\)/);
    assert.match(page, /aria-label="Refresh analytics"/);
    ui.dispose();
});

test('the same event is sent at most once per second and other events are unaffected', () => {
    const sent = {};
    assert.equal(websiteEventDue(sent, 'page_view', 5000), true);
    assert.equal(websiteEventDue(sent, 'page_view', 5400), false);
    assert.equal(websiteEventDue(sent, 'menu_click', 5400), true);
    assert.equal(websiteEventDue(sent, 'page_view', 6000), true);
});

test('the period tabs are Today, 7 Days, 30 Days and Custom with a shareable query', () => {
    assert.deepEqual(
        WEBSITE_PERIODS.map(([, label]) => label),
        ['Today', '7 Days', '30 Days', 'Custom'],
    );
    assert.deepEqual(websiteAnalyticsQuery('today'), {});
    assert.deepEqual(websiteAnalyticsQuery('last_7_days'), {
        period: 'last_7_days',
    });
    assert.deepEqual(
        websiteAnalyticsQuery('custom', '2026-10-01', '2026-10-13'),
        { period: 'custom', from: '2026-10-01', to: '2026-10-13' },
    );
    /** A preset never carries a stale custom range. */
    assert.deepEqual(
        websiteAnalyticsQuery('last_30_days', '2026-10-01', '2026-10-13'),
        { period: 'last_30_days' },
    );
});

test('Website Analytics is a management page of the Owner and Super Admin shells', () => {
    const page = { component: 'workspaces/website-analytics', url: '/' };
    assert.equal(activeManagementDestination(page), 'website-analytics');
    assert.equal(activeSuperAdminDestination(page), 'website-analytics');
    assert.equal(workspaceIsLoading(page.component, {}), true);
    assert.equal(workspaceIsLoading(page.component, { report: {} }), false);
    assert.match(
        read('layouts/workspace-layout.tsx'),
        /page\.component === 'workspaces\/website-analytics' \|\|/,
    );
    assert.match(
        read('components/owner-workspace-shell.tsx'),
        /case 'website-analytics':\s*return websiteAnalytics\(\);/,
    );
    assert.match(
        read('components/super-admin-shell.tsx'),
        /'website-analytics': \{ icon: Globe, href: websiteAnalytics\(\) \}/,
    );
});

test('the page shows every required figure, labels visitors as an estimate and never invents data', () => {
    const page = read('pages/workspaces/website-analytics.tsx');
    for (const figure of [
        'Page views',
        'Unique visitors (estimate)',
        'Facebook / contact clicks',
        'Directions clicks',
        'Menu clicks',
        'Review submissions',
        'Visits over time',
    ]) {
        assert.ok(page.includes(figure), figure);
    }
    assert.match(page, /`≈ \$\{count\(totals\.visitors\)\}`/);
    assert.match(page, /Unique visitors are an estimate\./);
    assert.match(page, /No IP address/);
    /** Every number comes from the server report; the page holds no sample figures. */
    assert.doesNotMatch(page, /Math\.random|sample|placeholder data/i);
    assert.match(page, /only: \['report', 'errors'\]/);
    /** The shared trend chart is reused with visit wording instead of sales wording. */
    assert.match(page, /empty: 'No visits recorded in this period'/);
    assert.match(
        read('components/owner-analytics.tsx'),
        /\{text\?\.empty \?\? 'No sales in this period'\}/,
    );
});
