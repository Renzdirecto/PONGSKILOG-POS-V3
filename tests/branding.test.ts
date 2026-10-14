import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const logoIcon = source('components/app-logo-icon.tsx');
const ownerShell = source('components/owner-workspace-shell.tsx');
const superAdminShell = source('components/super-admin-shell.tsx');
const layout = source('layouts/workspace-layout.tsx');
const app = source('app.tsx');
const welcome = source('pages/welcome.tsx');
const landing = source('components/landing-experience.jsx');

test('one brand mark component renders the official round emblem', () => {
    assert.match(logoIcon, /'\/images\/branding\/pongskilog-emblem\.png'/);
    assert.doesNotMatch(logoIcon, /<svg|<path/);
});

test('owner and super admin sidebars keep only the wordmark; the emblem stays in the mobile header', () => {
    for (const shell of [ownerShell, superAdminShell]) {
        assert.match(
            shell,
            /import AppLogoIcon from '@\/components\/app-logo-icon';/,
        );
        assert.doesNotMatch(
            shell,
            /<AppLogoIcon alt="" className="size-11 shrink-0" \/>/,
        );
        assert.doesNotMatch(shell, /<AppLogoIcon className="size-11" \/>/);
        assert.match(
            shell,
            /src="\/images\/branding\/logo\.png"\s+alt="PONGSKILOG"\s+className="w-\[168px\]"/,
        );
        assert.match(
            shell,
            /src="\/images\/branding\/logo\.png"\s+alt="PONGSKILOG"\s+className="max-w-\[70px\]"/,
        );
        assert.match(
            shell,
            /<AppLogoIcon className="size-9 shrink-0 md:hidden" \/>/,
        );
    }
    assert.match(
        layout,
        /<AppLogoIcon alt="" className="size-11 shrink-0" \/>/,
    );
});

test('titles and the public landing page say Pongskilog, not Laravel', () => {
    assert.match(app, /VITE_APP_NAME \|\| 'Pongskilog'/);
    assert.doesNotMatch(welcome, /Laravel/);
    assert.match(landing, /alt="PONGSKILOG"/);
});

test('the account settings shell carries no Laravel starter kit links', () => {
    for (const shell of [
        source('components/app-sidebar.tsx'),
        source('components/app-header.tsx'),
    ]) {
        assert.doesNotMatch(shell, /react-starter-kit|laravel\.com\/docs/);
    }
});

test('public landing keeps the supplied sections and a route-aware staff entry', () => {
    for (const id of ['name', 'board', 'lemonade', 'visit']) {
        assert.ok(landing.includes(`id="${id}"`));
    }
    assert.match(source('components/customer-reviews.tsx'), /id="reviews"/);
    assert.match(landing, /<CustomerReviews/);
    assert.doesNotMatch(landing, /id="menu"|The whole menu/);
    assert.match(welcome, /auth\.user\s*\? dashboard\(\)\s*: login\(\)/);
    assert.match(welcome, /Open workspace/);
    assert.match(welcome, /Login/);
    assert.doesNotMatch(
        welcome + landing,
        /__bundler|text\/x-dc|<sc-if|<sc-for|pongskilog\.example/,
    );
});
