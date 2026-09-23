import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    customRangeError,
    reportQuery,
    SESSION_RESULTS,
} from '../resources/js/lib/reports.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const page = source('pages/workspaces/reports.tsx');
const ownerShell = source('components/owner-workspace-shell.tsx');
const layout = source('layouts/workspace-layout.tsx');

test('changing the period clears the Store Session and keeps a shareable query', () => {
    assert.deepEqual(
        reportQuery({ date: 'yesterday', session: 'a' }, { date: 'month' }),
        { date: 'month' },
    );
    assert.deepEqual(
        reportQuery(
            { date: 'custom', from: '2026-09-01', to: '2026-09-05' },
            {
                date: 'today',
            },
        ),
        {},
    );
    assert.deepEqual(
        reportQuery(
            { date: 'custom', from: '2026-09-01', to: '2026-09-05' },
            { session: 'b' },
        ),
        { date: 'custom', from: '2026-09-01', to: '2026-09-05', session: 'b' },
    );
    assert.deepEqual(reportQuery({ session: 'b' }, { session: undefined }), {});
});

test('custom ranges are pre-checked with the server limits', () => {
    assert.equal(customRangeError('2026-08-01', '2026-08-31'), null);
    assert.equal(
        customRangeError('2026-08-01', '2026-09-01'),
        'Choose a custom range of 31 days or fewer.',
    );
    assert.equal(
        customRangeError('2026-09-23', '2026-09-22'),
        'The end date must be on or after the start date.',
    );
    assert.equal(
        customRangeError('', '2026-09-22'),
        'Choose both a start and an end date.',
    );
    assert.equal(
        customRangeError('2026-02-30', '2026-03-01'),
        'Choose both a start and an end date.',
    );
});

test('session status is text as well as colour', () => {
    assert.deepEqual(
        Object.values(SESSION_RESULTS).map(({ label }) => label),
        ['Live', 'Balanced', 'Overage', 'Shortage', 'Not available'],
    );
});

test('the report only displays server money and exposes no mutation', () => {
    assert.doesNotMatch(page, /parseFloat|Number\(|BigInt\(|signedCents\(/);
    assert.doesNotMatch(
        page,
        /router\.(post|put|patch|delete)|useForm|method="post"/,
    );
    assert.doesNotMatch(
        page,
        /Close Store<\/|Void<\/|Add expense|Adjust inventory|Settle/,
    );
    assert.match(page, /const peso = formatDecimalPeso;/);
});

test('filters, dialog and tables are labelled for assistive technology', () => {
    assert.match(page, /aria-label="Business date"/);
    assert.match(page, /<span className=\{labelClass\}>Store Session<\/span>/);
    assert.match(page, /type="date"/);
    assert.match(page, /aria-pressed=\{activePreset === key\}/);
    assert.match(page, /<DialogTitle[\s\S]{0,80}Store Session/);
    assert.match(page, /<DialogDescription/);
    assert.match(page, /scope="col"/);
    assert.match(page, /LIVE · figures are provisional/);
});

test('Owner and Super Admin render the same report inside their management shells', () => {
    assert.match(
        ownerShell,
        /label: 'Reports',[\s\S]{0,120}href: reports\(\),\s+active: isReports,/,
    );
    assert.match(
        ownerShell,
        /const isReports = page\.component === 'workspaces\/reports';/,
    );
    assert.match(
        ownerShell,
        /label: 'Transactions',[\s\S]{0,160}unavailableReason:/,
    );
    assert.match(layout, /page\.component === 'workspaces\/reports' \|\|/);
});
