import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    canRetryStoreSessionLoad,
    storeSessionLoadFailure,
    storeSessionLoadMessage,
} from '../resources/js/lib/store-session.ts';
import {
    isExpenseWriteOnline,
    storeExpenseError,
} from '../resources/js/lib/store-session-expense.ts';

const dialog = readFileSync(
    new URL(
        '../resources/js/components/store-session-details-dialog.tsx',
        import.meta.url,
    ),
    'utf8',
);
const workspace = readFileSync(
    new URL('../resources/js/layouts/workspace-layout.tsx', import.meta.url),
    'utf8',
);
const realtime = readFileSync(
    new URL(
        '../resources/js/hooks/use-store-expense-realtime.ts',
        import.meta.url,
    ),
    'utf8',
);

test('STORE OPEN launches the reusable current-session expense surface', () => {
    assert.match(workspace, /onClick=\{openStoreSessionDetails\}/);
    assert.match(
        workspace,
        /storeSessionRequest\.get\(\s*currentStoreSession\.url\(\)/,
    );
    assert.match(workspace, /setStoreSession\(detail\)/);
    assert.match(workspace, /setStoreSessionLoadState\('loaded'\)/);
    assert.match(workspace, /<StoreSessionDetailsDialog/);
    assert.match(dialog, /Current Store Session/);
    assert.match(dialog, /Purchases & expenses/);
    assert.match(dialog, /Add expense \/ purchase/);
    assert.match(dialog, /Restock inventory/);
    assert.match(dialog, /View private receipt/);
    assert.match(dialog, /h-\[min\(92svh,780px\)\]/);
    /** Phase 15 extends this same surface; Close Store never becomes a navigation page. */
    assert.match(dialog, /view === 'close' && \(\s*<StoreCloseFlow/);
    assert.doesNotMatch(workspace, /label: 'Close Store'/);
});

test('current-session load failures distinguish HTTP and network outcomes', () => {
    assert.equal(
        storeSessionLoadFailure({ response: { status: 404 } }),
        'not_found',
    );
    assert.equal(
        storeSessionLoadFailure({ response: { status: 403 } }),
        'forbidden',
    );
    assert.equal(
        storeSessionLoadFailure({ response: { status: 419 } }),
        'session_expired',
    );
    assert.equal(
        storeSessionLoadFailure({ response: { status: 401 } }),
        'session_expired',
    );
    assert.equal(
        storeSessionLoadFailure({ response: { status: 500 } }),
        'error',
    );
    assert.equal(
        storeSessionLoadFailure({ name: 'HttpNetworkError' }),
        'offline',
    );
    assert.equal(
        storeSessionLoadMessage('not_found'),
        'This Store Session is no longer available.',
    );
    assert.equal(
        storeSessionLoadMessage('forbidden'),
        'You do not have permission to view this Store Session.',
    );
    assert.equal(
        storeSessionLoadMessage('session_expired'),
        'Your session has expired. Sign in or refresh before continuing.',
    );
    assert.equal(
        storeSessionLoadMessage('offline'),
        'Unable to load the Store Session. Check your connection and try again.',
    );
    assert.equal(
        storeSessionLoadMessage('error'),
        'Store Session details could not be loaded. Try again.',
    );
    assert.equal(canRetryStoreSessionLoad('offline'), true);
    assert.equal(canRetryStoreSessionLoad('error'), true);
    assert.equal(canRetryStoreSessionLoad('not_found'), false);
    assert.match(dialog, />\s*Retry\s*</);
});

test('expense form keeps a stable attempt key and submits multipart data through Wayfinder', () => {
    assert.match(dialog, /useState\(createClientUuid\)/);
    assert.match(dialog, /data\.append\('idempotency_key', attempt\)/);
    assert.match(dialog, /new FormData\(\)/);
    assert.match(dialog, /\.\.\.store\(\)/);
    assert.match(dialog, /accept="image\/jpeg,image\/png,image\/webp"/);
});

test('expense realtime uses a branch-private compact event and recovery refreshes', () => {
    assert.match(realtime, /`branch\.\$\{branchId\}\.store-session`/);
    assert.match(realtime, /\['\.store\.expense_recorded'\]/);
    assert.match(realtime, /createBranchEventGuard\(branchId\)/);
    assert.match(realtime, /connectionStatus === 'connected'/);
    assert.match(realtime, /window\.addEventListener\('online', recover\)/);
});

test('expense errors preserve conflict and validation feedback', () => {
    assert.match(
        storeExpenseError({ response: { status: 409 } }),
        /already used with different details/,
    );
    assert.equal(
        storeExpenseError({
            response: {
                status: 422,
                data: { errors: { amount: ['Enter a valid amount.'] } },
            },
        }),
        'Enter a valid amount.',
    );
});

test('expense writes require browser connectivity but not Echo connectivity', () => {
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: { onLine: true },
    });
    assert.equal(isExpenseWriteOnline(), true);
    assert.match(dialog, /Live updates are unavailable/);
    assert.match(dialog, /You can still record an expense/);
    Object.defineProperty(globalThis, 'navigator', {
        configurable: true,
        value: { onLine: false },
    });
    assert.equal(isExpenseWriteOnline(), false);
});
