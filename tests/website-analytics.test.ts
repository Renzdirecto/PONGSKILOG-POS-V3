import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { activeManagementDestination } from '../resources/js/lib/management-navigation.ts';
import { activeSuperAdminDestination } from '../resources/js/lib/super-admin-navigation.ts';
import {
    WEBSITE_PERIODS,
    websiteAnalyticsQuery,
    websiteEventDue,
    websiteLinkEvent,
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
