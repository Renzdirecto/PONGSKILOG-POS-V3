import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    assessChannel,
    assessClose,
    closeAttemptSignature,
    correctionRefundBounds,
    formatDecimalPeso,
    formatPeso,
    normalizeMoneyInput,
    shortageMessage,
    signedCents,
    storeCloseError,
} from '../resources/js/lib/store-close.ts';

const source = (path: string) =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const flow = source('components/store-close-flow.tsx');
const dialog = source('components/store-session-details-dialog.tsx');
const workspace = source('layouts/workspace-layout.tsx');
const realtime = source('hooks/use-store-close-realtime.ts');
const closedRealtime = source('hooks/use-store-closed-realtime.ts');
const history = source('pages/workspaces/transaction-history.tsx');

test('money helpers stay exact, signed and server compatible', () => {
    assert.equal(signedCents('-250.00'), -25000n);
    assert.equal(signedCents('1400.5'), 140050n);
    assert.equal(formatPeso(123456789n), '₱1,234,567.89');
    assert.equal(formatDecimalPeso('-250.00'), '-₱250.00');
    assert.equal(normalizeMoneyInput(' 1,400 '), '1400.00');
    assert.equal(normalizeMoneyInput('0.5'), '0.50');
    assert.equal(normalizeMoneyInput('-1'), null);
    assert.equal(normalizeMoneyInput('1.005'), null);
    assert.equal(normalizeMoneyInput('1000000000000'), null);
    assert.equal(normalizeMoneyInput(''), null);
});

test('variance is actual minus expected for each independent channel', () => {
    assert.deepEqual(assessChannel('1400.00', ''), {
        state: 'pending',
        actual: null,
        variance: null,
    });
    assert.equal(assessChannel('1400.00', 'abc').state, 'invalid');
    assert.deepEqual(assessChannel('1400.00', '1400'), {
        state: 'exact',
        actual: 140000n,
        variance: 0n,
    });
    assert.equal(assessChannel('1400.00', '1300').variance, -10000n);
    assert.equal(assessChannel('1400.00', '1300').state, 'shortage');
    assert.equal(assessChannel('-250.00', '0').state, 'overage');
});

test('shortage blocks, overage needs a note, and channels never offset', () => {
    const expected = { cash: '1000.00', cashless: '500.00' };

    assert.equal(assessClose(expected, '1000', '500', '').canContinue, true);
    assert.equal(assessClose(expected, '1000', '', '').canContinue, false);

    const mixed = assessClose(expected, '900', '600', 'Counted twice');
    assert.equal(mixed.hasShortage, true);
    assert.equal(mixed.needsNote, false);
    assert.equal(mixed.canContinue, false);

    const overage = assessClose(expected, '1000', '525.50', '');
    assert.equal(overage.needsNote, true);
    assert.equal(overage.canContinue, false);
    assert.equal(
        assessClose(expected, '1000', '525.50', '  ok ').canContinue,
        false,
    );
    assert.equal(
        assessClose(expected, '1000', '525.50', 'Tip left in GCash')
            .canContinue,
        true,
    );
    assert.equal(
        shortageMessage('Cash', -10000n),
        'Closing Cash is ₱100.00 short. Review the count or missing transactions before closing.',
    );
});

test('refund source bounds collapse only when the source is deterministic', () => {
    assert.deepEqual(correctionRefundBounds(30000n, '300.00', '200.00'), {
        min: 10000n,
        max: 30000n,
    });
    assert.deepEqual(correctionRefundBounds(5000n, '0.00', '500.00'), {
        min: 0n,
        max: 0n,
    });
    assert.deepEqual(correctionRefundBounds(5000n, '500.00', '0.00'), {
        min: 5000n,
        max: 5000n,
    });
});

test('close errors keep blockers, conflicts and ambiguous network results distinct', () => {
    assert.equal(storeCloseError(new Error('offline')).kind, 'network');
    assert.equal(
        storeCloseError({
            response: {
                status: 422,
                data: JSON.stringify({
                    errors: {
                        kitchen: [
                            'All committed Kitchen orders must be Done before closing.',
                        ],
                    },
                }),
            },
        }).kind,
        'blocker',
    );
    assert.deepEqual(
        storeCloseError({
            response: {
                status: 422,
                data: {
                    errors: {
                        closing_note: [
                            'Add an explanation for the overage before closing.',
                        ],
                    },
                },
            },
        }),
        {
            kind: 'validation',
            message: 'Add an explanation for the overage before closing.',
        },
    );
    assert.equal(
        storeCloseError({
            response: {
                status: 409,
                data: {
                    message:
                        'The Store is already closed. Refresh to see the current Store state.',
                },
            },
        }).kind,
        'closed',
    );
    assert.equal(
        storeCloseError({ response: { status: 409, data: {} } }).kind,
        'conflict',
    );
    assert.equal(
        storeCloseError({ response: { status: 403 } }).kind,
        'forbidden',
    );
    assert.notEqual(
        closeAttemptSignature('s', '1.00', '0.00', null),
        closeAttemptSignature('s', '1.00', '0.00', 'Recount'),
    );
});

test('Close Store extends the Current Store Session dialog behind the store permission', () => {
    assert.match(dialog, /view === 'close' && \(\s*<StoreCloseFlow/);
    assert.match(dialog, /\{canCloseStore && \(/);
    assert.match(dialog, /Review &amp; Close Store/);
    assert.match(dialog, /if \(!next && closeBusy\)/);
    assert.match(
        workspace,
        /canCloseStore=\{auth\.permissions\.includes\(\s*'store\.open_close',?\s*\)\}/,
    );
    assert.doesNotMatch(workspace, /label: 'Close Store'/);
});

test('the flow never prefills counts and submits only server-validated values', () => {
    assert.doesNotMatch(flow, /setCashInput\(reconciliation/);
    assert.match(flow, /Values are not prefilled/);
    assert.match(flow, /idempotency_key: attempt\.key/);
    assert.match(flow, /store_session_id: preview\.store_session\.id/);
    assert.doesNotMatch(flow, /expected_cash_amount:/);
    assert.match(flow, /Closing Store…/);
    assert.match(flow, /Confirm Close Store/);
    assert.match(flow, /failure\.kind !== 'network'/);
    assert.match(
        flow,
        /You are offline\. Reconnect before closing the Store\./,
    );
    assert.match(flow, /Live updates unavailable/);
    assert.match(flow, /Already counted in Sales above/);
    assert.match(flow, /Recheck/);
});

test('realtime only invalidates and refetches authoritative close state', () => {
    assert.match(realtime, /`branch\.\$\{branchId\}\.pos`/);
    assert.match(realtime, /STORE_CLOSE_POS_EVENTS/);
    assert.match(realtime, /\['\.store\.expense_recorded'\]/);
    assert.match(realtime, /window\.addEventListener\('online', recover\)/);
    assert.match(closedRealtime, /\['\.store\.closed'\]/);
    assert.match(workspace, /<StoreClosedListener/);
    assert.match(workspace, /router\.reload\(\)/);
});

test('mixed-method lower-total edits capture an explicit refund source', () => {
    assert.match(history, /refund_cash_amount: refundChoiceRequired/);
    assert.match(history, /Returned in Cash/);
    assert.match(history, /disabled=\{processing \|\| !refundChoiceValid\}/);
});

test('POS state is reloaded only after the server confirms the close', () => {
    const reloads = flow.match(/router\.reload\(\)/g) ?? [];
    assert.equal(reloads.length, 2);
    assert.match(flow, /onClosed\(closed\);\s*router\.reload\(\);/);
    assert.match(flow, /failure\.kind === 'closed'\) \{\s*router\.reload\(\);/);
    assert.match(
        flow,
        /Any unsent POS cart on this device will be\s+cleared\./,
    );
});

test('the confirmation footer keeps both actions within a 360px dialog', () => {
    assert.match(flow, /grid-cols-\[auto_minmax\(0,1fr\)\]/);
    assert.match(dialog, /w-\[calc\(100%-16px\)\]/);
});

test('session purchases collapse behind a dropdown below the closing count', () => {
    assert.match(flow, /<SessionPurchases session=\{session\} \/>/);
    assert.match(flow, /aria-expanded=\{open\}/);
    assert.match(flow, /<ChevronDown/);
    assert.match(flow, /useState\(false\)/);
    assert.ok(
        flow.indexOf('<SessionPurchases') >
            flow.indexOf('Actual closing count'),
    );
    assert.match(dialog, /<StoreCloseFlow\s+session=\{session\}/);
});

test('pre-close checks are shown only while a blocker remains', () => {
    assert.match(flow, /\{preview && blockers && !preview\.ready && \(/);
    assert.match(flow, /unclaimed QR \$\{/);
});

test('final confirmation summarizes each channel with its own variance state', () => {
    assert.match(flow, /<ChannelSummary\s+label="Cash"/);
    assert.match(flow, /<ChannelSummary\s+label="Cashless"/);
    assert.match(flow, /Please review the session summary before closing\./);
    assert.match(flow, /aria-label="Edit overage explanation"/);
    assert.match(flow, /This will close the current store session/);
});

test('Store Closed POS page keeps Open Store permission-gated and Browse available', () => {
    const page = source('components/cashier-store.tsx');
    assert.match(page, /<ClosedStoreIllustration \/>/);
    assert.match(page, /\{canOpen && \(\s*<Button/);
    assert.match(page, /Browse Read-Only/);
    assert.match(page, /Ready when you are/);
    assert.match(page, /aria-hidden="true"/);
});
