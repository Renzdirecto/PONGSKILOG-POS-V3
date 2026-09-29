import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { isCustomerFacingPage } from '../resources/js/lib/appearance.ts';
import { releaseLabel } from '../resources/js/lib/release.ts';

const source = (path: string) =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const root = (path: string) =>
    readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('appearance is Light or Dark only, Light by default, never the OS preference', () => {
    const hook = source('hooks/use-appearance.tsx');
    const tabs = source('components/appearance-tabs.tsx');
    const blade = root('resources/views/app.blade.php');

    assert.match(hook, /export type Appearance = 'light' \| 'dark';/);
    assert.doesNotMatch(hook, /prefers-color-scheme|'system'/);
    assert.match(hook, /let currentAppearance: Appearance = 'light';/);
    assert.match(
        hook,
        /getItem\(STORAGE_KEY\) === 'dark'\s*\?\s*'dark'\s*:\s*'light'/,
    );
    assert.doesNotMatch(tabs, /System|Monitor/);
    assert.doesNotMatch(blade, /prefers-color-scheme/);
    assert.match(blade, /\(\$appearance \?\? 'light'\) === 'dark'/);
    assert.match(blade, /data-appearance-lock="light"/);
});

test('customer-facing pages are locked to Light', () => {
    for (const page of [
        'welcome',
        'qr/show',
        'public-receipt',
        'workspaces/customer-display',
        'customer-screen',
        'pickup',
    ]) {
        assert.equal(isCustomerFacingPage(page), true, page);
    }
    for (const page of ['workspaces/show', 'settings/profile', 'operations/plans']) {
        assert.equal(isCustomerFacingPage(page), false, page);
    }
    assert.match(source('app.tsx'), /<AppearanceLock component=\{page\.component\} \/>/);
});

test('the Dark adapter maps the shared light palette once and leaves theme-static surfaces alone', () => {
    const css = root('resources/css/dark-theme.css');
    const app = root('resources/css/app.css');

    assert.match(app, /@import '\.\/dark-theme\.css';/);
    for (const selector of [
        'html.dark .bg-white:not(.theme-static, .theme-static *)',
        'html.dark .text-\\[\\#767676\\]:not(.theme-static, .theme-static *)',
        'html.dark .border-\\[\\#e5e5e5\\]:not(.theme-static, .theme-static *)',
        'html.dark .text-neutral-500:not(.theme-static, .theme-static *)',
    ]) {
        assert.ok(css.includes(selector), selector);
    }
    /** Shell chrome that is already dark opts out instead of being repainted. */
    for (const shell of [
        'components/owner-workspace-shell.tsx',
        'components/super-admin-shell.tsx',
        'layouts/workspace-layout.tsx',
    ]) {
        assert.match(source(shell), /theme-static/);
    }
});

test('the Account pages let staff change only their Preferred Name and photo', () => {
    const profile = source('pages/settings/profile.tsx');
    const layout = source('layouts/account-layout.tsx');

    assert.match(profile, /name="preferred_name"/);
    assert.doesNotMatch(profile, /name="(name|email|employee_id|position)"/);
    assert.match(profile, /Managed by your administrator/);
    /** A photo picker, never a camera-only capture input. */
    assert.doesNotMatch(profile, /capture=/);
    for (const label of ['Profile', 'Security', 'Appearance', 'Sign out', 'Back to workspace']) {
        assert.ok(layout.includes(label), label);
    }
    assert.match(layout, /openPwaAppDialog\(\)/);
    assert.match(source('app.tsx'), /case name\.startsWith\('settings\/'\):\s*return AccountLayout;/);
    assert.match(source('components/pos-profile-controls.tsx'), /Account &amp; preferences/);
});

test('the release label shows version and build, never an invented value', () => {
    assert.equal(
        releaseLabel({ name: 'PONGSKILOG POS', version: 'v1.0.0', build: '3f9c2ab' }),
        'v1.0.0 · Build 3f9c2ab',
    );
    assert.equal(
        releaseLabel({ name: 'PONGSKILOG POS', version: 'development', build: null }),
        'development',
    );
    assert.equal(releaseLabel(null), 'Unknown version');
    assert.match(source('components/pwa-app-dialog.tsx'), /aria-label="Release"/);
});
