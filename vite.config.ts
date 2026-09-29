import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import babel from '@rolldown/plugin-babel';
import tailwindcss from '@tailwindcss/vite';
import react, { reactCompilerPreset } from '@vitejs/plugin-react';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { VitePWA } from 'vite-plugin-pwa';
import { defineConfig, lazyPlugins } from 'vite-plus';

/**
 * Static public files the service worker precaches besides the fingerprinted build: the offline page and the brand
 * icons it and the installed app use. Each is revisioned by content, so an edited file replaces the cached copy.
 */
const PRECACHED_PUBLIC_FILES = [
    'offline.html',
    'images/branding/pongskilog-emblem.png',
    'images/branding/icons/icon-192.png',
    'images/branding/icons/icon-512.png',
    'images/branding/icons/icon-maskable-192.png',
    'images/branding/icons/icon-maskable-512.png',
    'apple-touch-icon.png',
];

function publicPrecacheEntries(): { url: string; revision: string }[] {
    return PRECACHED_PUBLIC_FILES.map((file) => ({
        url: `/${file}`,
        revision: createHash('sha256')
            .update(readFileSync(`public/${file}`))
            .digest('hex')
            .slice(0, 16),
    }));
}

export default defineConfig({
    plugins: lazyPlugins(() => [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.tsx'],
            refresh: true,
            fonts: [
                bunny('Poppins', { weights: [400, 500, 600, 700] }),
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        inertia(),
        react(),
        babel({
            presets: [reactCompilerPreset()],
        }),
        tailwindcss(),
        wayfinder({
            formVariants: true,
        }),
        /**
         * PWA Phase 1: a custom service worker (resources/js/service-worker/sw.ts) built after the app, served at /sw.js
         * by Laravel. Only production builds have one; the Vite dev server never registers it (no HMR caching).
         */
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js/service-worker',
            filename: 'sw.ts',
            outDir: 'public/build',
            registerType: 'prompt',
            injectRegister: false,
            manifest: false,
            devOptions: { enabled: false },
            injectManifest: {
                globDirectory: 'public/build',
                globPatterns: ['assets/**/*.{js,css,woff2}'],
                modifyURLPrefix: { '': '/build/' },
                /** Fingerprinted file names are their own version. */
                dontCacheBustURLsMatching: /^\/build\/assets\//,
                additionalManifestEntries: publicPrecacheEntries(),
                maximumFileSizeToCacheInBytes: 3 * 1024 * 1024,
            },
        }),
    ]),
    server: {
        host: '127.0.0.1',
        port: 5173,
        strictPort: true,
        hmr: {
            host: '127.0.0.1',
        },
        watch: {
            ignored: [
                '**/.agents/**',
                '**/.claude/**',
                '**/.cursor/**',
                '**/.junie/**',
                '**/vendor/**',
            ],
        },
    },
    lint: {
        ignorePatterns: [
            'vendor/**',
            'node_modules/**',
            'public/**',
            'bootstrap/ssr/**',
            'tailwind.config.js',
            'resources/js/actions/**',
            'resources/js/components/ui/*',
            'resources/js/routes/**',
            'resources/js/wayfinder/**',
        ],
        options: {
            denyWarnings: true,
            typeAware: true,
        },
    },
    fmt: {
        printWidth: 80,
        tabWidth: 4,
        singleQuote: true,
        semi: true,
        singleAttributePerLine: false,
        htmlWhitespaceSensitivity: 'css',
        ignorePatterns: [
            '.github/**',
            'composer.json',
            'resources/js/components/ui/*',
            'resources/views/mail/*',
        ],
        sortTailwindcss: {
            functions: ['clsx', 'cn', 'cva'],
            entryPoint: 'resources/css/app.css',
        },
    },
});
