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

test('one brand mark component renders the official round emblem', () => {
    assert.match(logoIcon, /'\/images\/branding\/pongskilog-emblem\.png'/);
    assert.doesNotMatch(logoIcon, /<svg|<path/);
});

test('owner and super admin shells use the emblem in the sidebar, rail and mobile header', () => {
    for (const shell of [ownerShell, superAdminShell]) {
        assert.match(shell, /import AppLogoIcon from '@\/components\/app-logo-icon';/);
        assert.match(shell, /<AppLogoIcon alt="" className="size-11 shrink-0" \/>\s+<img\s+src="\/images\/branding\/logo\.png"/);
        assert.match(shell, /<AppLogoIcon className="size-11" \/>/);
        assert.match(shell, /<AppLogoIcon className="size-9 shrink-0 md:hidden" \/>/);
    }
    assert.match(layout, /<AppLogoIcon alt="" className="size-11 shrink-0" \/>/);
});

test('titles and the public landing page say Pongskilog, not Laravel', () => {
    assert.match(app, /VITE_APP_NAME \|\| 'Pongskilog'/);
    assert.doesNotMatch(welcome, /Laravel/);
    assert.match(welcome, /\/images\/branding\/icons\/icon-512\.png/);
});
