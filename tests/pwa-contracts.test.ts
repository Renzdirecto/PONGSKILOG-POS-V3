import assert from 'node:assert/strict';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { test } from 'node:test';

const file = (path: string): string =>
    readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const js = (path: string): string => file(`resources/js/${path}`);

/** Width and height from a PNG's IHDR chunk. */
function pngSize(path: string): [number, number] {
    const bytes = readFileSync(new URL(`../public/${path}`, import.meta.url));

    return [bytes.readUInt32BE(16), bytes.readUInt32BE(20)];
}

test('the manifest installs PONGSKILOG as a standalone app with any and maskable icons', () => {
    const manifest = JSON.parse(file('public/manifest.webmanifest')) as Record<string, unknown> & {
        icons: { src: string; sizes: string; purpose: string }[];
    };

    assert.equal(manifest.name, 'PONGSKILOG POS');
    assert.equal(manifest.short_name, 'PONGSKILOG');
    assert.equal(manifest.id, '/');
    assert.equal(manifest.start_url, '/workspace');
    assert.equal(manifest.scope, '/');
    assert.equal(manifest.display, 'standalone');
    assert.equal(manifest.lang, 'en-PH');
    assert.equal(manifest.prefer_related_applications, false);
    assert.equal(manifest.theme_color, '#111111');
    assert.equal('orientation' in manifest, false, 'POS phones and Kitchen tablets choose their own orientation');
    assert.deepEqual(manifest.categories, ['business', 'productivity', 'food']);

    for (const purpose of ['any', 'maskable']) {
        for (const size of [192, 512]) {
            const icon = manifest.icons.find((entry) => entry.purpose === purpose && entry.sizes === `${size}x${size}`);
            assert.ok(icon, `${purpose} ${size}`);
            assert.deepEqual(pngSize(icon.src.slice(1)), [size, size], icon.src);
        }
    }
    assert.deepEqual(pngSize('apple-touch-icon.png'), [180, 180]);
});

test('the offline page is static and branded: a reconnect action, no data and no transaction controls', () => {
    const offline = file('public/offline.html');

    assert.match(offline, /PONGSKILOG/);
    assert.match(offline, /You're offline\./);
    assert.match(offline, /Reconnect to continue\./);
    assert.match(offline, /<button type="button" id="retry">Reconnect<\/button>/);
    assert.match(offline, /addEventListener\('online', reconnect\)/);
    assert.doesNotMatch(offline, /<form|<input|data-page|₱|csrf|fetch\(|localStorage|indexedDB/i);
});

test('the service worker caches only the build, icons and offline page, and never touches writes', () => {
    const worker = js('service-worker/sw.ts');

    assert.match(worker, /precacheAndRoute\(self\.__WB_MANIFEST\)/);
    assert.match(worker, /cleanupOutdatedCaches\(\)/);
    assert.match(worker, /new NavigationRoute\(/);
    assert.match(worker, /new NetworkOnly\(\)/);
    assert.match(worker, /matchPrecache\(OFFLINE_PAGE\)/);
    assert.equal(worker.match(/registerRoute\(/g)?.length, 1, 'only navigations are routed');
    assert.doesNotMatch(worker, /CacheFirst|StaleWhileRevalidate|NetworkFirst|caches\.open|cache\.put/);
    assert.doesNotMatch(worker, /BackgroundSync|workbox-background-sync|sync'|Queue/);
    assert.doesNotMatch(worker, /clients\.claim/);
    assert.match(worker, /data as \{ type\?: unknown \} \| null\)\?\.type === 'SKIP_WAITING'\) \{\s+void self\.skipWaiting\(\);/);
    assert.equal(worker.match(/skipWaiting/g)?.length, 1, 'a new version only takes over when the app asks');
});

test('push notifications use fixed texts and a validated same-origin target', () => {
    const worker = js('service-worker/sw.ts');

    assert.match(worker, /notificationContent\(parsePushPayload\(event\.data\?\.text\(\)\)\)/);
    assert.match(worker, /silent: focused/);
    assert.match(worker, /tag: content\.tag/);
    assert.match(worker, /notificationTarget\(\s+\(event\.notification\.data/);
    assert.match(worker, /self\.clients\.openWindow\(target\)/);
    assert.match(worker, /postMessage\(\{ type: OPEN_TARGET_MESSAGE, url: target \}\)/);
});

test('the build injects a custom service worker only for production, with a precache of fingerprinted assets', () => {
    const vite = file('vite.config.ts');

    assert.match(vite, /strategies: 'injectManifest'/);
    assert.match(vite, /srcDir: 'resources\/js\/service-worker'/);
    assert.match(vite, /injectRegister: false/);
    assert.match(vite, /manifest: false/);
    assert.match(vite, /devOptions: \{ enabled: false \}/);
    assert.match(vite, /globPatterns: \['assets\/\*\*\/\*\.\{js,css,woff2\}'\]/);
    assert.match(vite, /'offline\.html'/);
});

test('the runtime registers the worker without HTTP caching and never in the Vite dev server', () => {
    const runtime = js('lib/pwa-runtime.ts');

    assert.match(runtime, /navigator\.serviceWorker\.register\(\s+serviceWorker\.url\(\),\s+\{ scope: '\/', updateViaCache: 'none' \}/);
    assert.match(runtime, /if \(import\.meta\.env\.DEV\) \{[\s\S]+?existing\.unregister\(\)/);
    assert.match(runtime, /return component\.startsWith\('qr\/'\) \|\| component === 'public-receipt';/);
    assert.match(runtime, /if \(started \|\| isPublicCustomerSurface\(options\.component\)\)/);
});

test('one wrapped HTTP client guards every Inertia request and counts writes in flight', () => {
    const runtime = js('lib/pwa-runtime.ts');

    assert.match(runtime, /const inner = http\.getClient\(\);\s+http\.setClient\(\{/);
    assert.match(runtime, /writeDecision\(\{[\s\S]+?serverWritesAllowed: connectivity\.allowsServerWrites\(\)/);
    assert.match(runtime, /throw new OfflineWriteBlockedError\(config\.url\)/);
    assert.match(runtime, /toast\.error\(OFFLINE_WRITE_MESSAGE, \{\s+id: 'pwa-offline-write',/);
    assert.match(runtime, /router\.on\('networkError', \(event\) =>\s+isOfflineWriteBlock\(event\.detail\.error\) \? false : undefined/);
    assert.match(runtime, /'A change is still being saved\.'/);
    assert.match(runtime, /fetch\('\/up', \{\s+cache: 'no-store',\s+credentials: 'omit'/);
    assert.match(runtime, /onHttpException: \(response\) => \{[\s\S]+?handleRevalidationException\(response\)/);
});

test('asset-version reloads, installs and permission prompts only happen safely and on request', () => {
    const runtime = js('lib/pwa-runtime.ts');

    assert.match(runtime, /router\.on\('location', \(event\) => \{[\s\S]+?versionChange[\s\S]+?blockers\.length > 0\) \{[\s\S]+?return false;/);
    assert.match(runtime, /addEventListener\('beforeinstallprompt', \(event\) => \{\s+event\.preventDefault\(\);/);
    assert.equal(runtime.match(/Notification\.requestPermission\(\)/g)?.length, 1);
    assert.match(runtime, /enablePush: async \(\) => \{[\s\S]+?Notification\.requestPermission\(\)/);
    assert.match(runtime, /userVisibleOnly: true/);
    assert.doesNotMatch(runtime, /skipWaiting|clients\.claim/);
});

test('logout unsubscribes this browser from push first, then logs out as before', () => {
    const runtime = js('lib/pwa-runtime.ts');

    assert.match(runtime, /visit\.url\.pathname !== logout\.url\(\)/);
    assert.match(runtime, /subscription\?\.unsubscribe\(\);[\s\S]+?PUSH_CLEANUP_TIMEOUT_MS,\s+\)\.finally\(\(\) => router\.post\(logout\.url\(\)\)\)/);
});

test('nothing private is persisted on the device', () => {
    const runtime = js('lib/pwa-runtime.ts');
    const sources = readdirSync(new URL('../resources/js/lib/', import.meta.url))
        .filter((name) => name.startsWith('pwa-'))
        .map((name) => js(`lib/${name}`))
        .concat(js('service-worker/sw.ts'), js('hooks/use-pwa.ts'));

    for (const source of sources) {
        assert.doesNotMatch(source, /indexedDB|IDBDatabase|caches\.open/);
    }
    assert.equal(runtime.match(/window\.localStorage/g)?.length, 2, 'only the last top-level route');
    assert.match(runtime, /LAST_ROUTE_STORAGE_KEY/);
});

test('the connectivity state never polls once online', () => {
    const connectivity = js('lib/pwa-connectivity.ts');

    assert.doesNotMatch(connectivity, /setInterval|usePoll/);
    assert.match(connectivity, /snapshot\.phase === 'online' \|\|\s+!environment\.isNavigatorOnline\(\) \|\|\s+!environment\.isDocumentVisible\(\)/);
});

test('the HTML shell is installable, safe-area ready and keeps public customer pages out', () => {
    const blade = file('resources/views/app.blade.php');

    assert.match(blade, /content="width=device-width, initial-scale=1, viewport-fit=cover"/);
    assert.match(blade, /@unless \(request\(\)->routeIs\('qr\.\*', 'kiosk\.\*', 'receipt\.\*'\)\)\s+<link rel="manifest" href="\/manifest\.webmanifest">/);
    assert.match(blade, /<meta name="apple-mobile-web-app-capable" content="yes">/);
    assert.match(blade, /<meta name="apple-mobile-web-app-status-bar-style" content="black">/);
    assert.match(blade, /@media \(display-mode: standalone\)[\s\S]+#pwa-boot \{/);
    assert.match(blade, /animation: pwa-boot-out 0\.2s ease 8s forwards/);
});

test('screens show the status where it stays visible and hold updates over an order in progress', () => {
    const pos = js('components/cashier-pos.tsx');

    assert.match(pos, /useUpdateBlocker\(\s+lines\.length > 0 \|\|\s+saved !== null \|\|\s+attempt !== null \|\|\s+payLaterAttempt !== null \|\|\s+payment\.processing \|\|\s+payLater\.processing \|\|\s+editing !== null \|\|/);
    assert.match(pos, /'Finish or clear the current order first\.'/);
    assert.match(pos, /if \(!serverWritesAllowed\(\)\) \{\s+setPaymentError\(/);
    assert.match(pos, /if \(!serverWritesAllowed\(\)\) \{\s+setPayLaterError\(/);
    assert.match(js('pages/workspaces/kitchen.tsx'), /\{fullscreen && \(\s+<div className="flex justify-end px-3 not-empty:pb-2\.5 md:px-4">\s+<PwaStatus \/>/);
    assert.match(js('pages/workspaces/customer-display.tsx'), /<PwaStatus tone="dark" \/>/);
    for (const shell of ['layouts/workspace-layout.tsx', 'components/owner-workspace-shell.tsx', 'components/super-admin-shell.tsx']) {
        assert.match(js(shell), /<PwaStatus \/>/, shell);
    }
    for (const menu of ['components/owner-workspace-shell.tsx', 'components/super-admin-shell.tsx', 'components/pos-profile-controls.tsx', 'components/user-menu-content.tsx']) {
        assert.match(js(menu), /<PwaAppMenuItem/, menu);
    }
    assert.match(js('app.tsx'), /captureInstallPrompt\(\);/);
    assert.match(js('app.tsx'), /<PwaRuntime\s+component=\{page\.component\}/);
});

const builtWorker = new URL('../public/build/sw.js', import.meta.url);

test(
    'the production build precaches only fingerprinted assets, icons and the offline page',
    { skip: existsSync(builtWorker) ? false : 'run npm run build first' },
    () => {
        const worker = readFileSync(builtWorker, 'utf8');
        const start = worker.indexOf('[{"revision"');
        let depth = 0;
        let end = start;
        for (let index = start; index < worker.length; index += 1) {
            if (worker[index] === '[') depth += 1;
            if (worker[index] === ']' && --depth === 0) {
                end = index + 1;
                break;
            }
        }
        const urls = (JSON.parse(worker.slice(start, end)) as { url: string }[]).map((entry) => entry.url);
        const staticFiles = urls.filter((url) => !url.startsWith('/build/'));

        assert.ok(urls.length > 20);
        assert.ok(statSync(builtWorker).size > 0);
        assert.ok(urls.includes('/offline.html'));
        assert.ok(urls.includes('/images/branding/icons/icon-192.png'));
        assert.deepEqual(
            urls.filter((url) => url.startsWith('/build/') && !/^\/build\/assets\/[^/]+\.(js|css|woff2)$/.test(url)),
            [],
        );
        assert.deepEqual(
            staticFiles.filter((url) => !/^\/(offline\.html|apple-touch-icon\.png|images\/branding\/[\w/.-]+\.png)$/.test(url)),
            [],
        );
        assert.doesNotMatch(worker, /clients\.claim|BackgroundSync/);
    },
);
