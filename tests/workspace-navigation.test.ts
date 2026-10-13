import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
import { instantWorkspaceProps, workspaceIsLoading } from '../resources/js/lib/workspace-navigation.ts';

test('instant workspace visits wait for authoritative data instead of showing empty operational state', () => {
    for (const component of [
        'workspaces/show',
        'workspaces/kitchen',
        'workspaces/transaction-history',
        'workspaces/reports',
        'workspaces/cashier-dashboard',
    ]) {
        assert.equal(
            workspaceIsLoading(component, { auth: {}, branchContext: {} }),
            true,
        );
    }
});

test('cached workspace data renders without flashing a skeleton and ignores optional edit data', () => {
    assert.equal(
        workspaceIsLoading('workspaces/transaction-history', {
            transactions: { data: [] },
            metrics: {},
            filters: {},
            scope: null,
        }),
        false,
    );
    assert.equal(
        workspaceIsLoading('workspaces/show', {
            catalog: { products: [] },
            store: { canOpen: false },
        }),
        false,
    );
    assert.equal(
        workspaceIsLoading('workspaces/kitchen', {
            kitchenBoard: { tickets: [] },
        }),
        false,
    );
    assert.equal(
        workspaceIsLoading('workspaces/reports', {
            report: {},
            analytics: {},
            kitchenNow: {},
            filters: {},
        }),
        false,
    );
});

test('other pages retain their existing rendering contract', () => {
    assert.equal(workspaceIsLoading('catalog/products', {}), false);
    assert.equal(workspaceIsLoading('welcome', {}), false);
});

test('instant target props preserve shared authentication and branch context without mutating them', () => {
    const auth = { user: { id: 1 }, permissions: ['pos.access'] };
    const branchContext = { current: { id: 'main' } };
    const shared = { auth, branchContext, storeContext: { isOpen: true } };
    const target = instantWorkspaceProps(shared, { workspace: 'Cashier / POS' });
    assert.equal(target.auth, auth);
    assert.equal(target.branchContext, branchContext);
    assert.equal(target.storeContext, shared.storeContext);
    assert.equal(target.workspace, 'Cashier / POS');
    assert.equal('workspace' in shared, false);
    assert.deepEqual(instantWorkspaceProps(shared), shared);
});

test('ready cache entries bypass the placeholder swap, while unavailable entries keep the instant shell', () => {
    const source = readFileSync(new URL('../resources/js/components/workspace-link.tsx', import.meta.url), 'utf8');
    const ast = ts.createSourceFile('workspace-link.tsx', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
    let callback = '';
    const find = (node: ts.Node) => {
        if (ts.isJsxAttribute(node) && node.name.getText(ast) === 'onBefore' && node.initializer && ts.isJsxExpression(node.initializer)) callback = node.initializer.expression?.getText(ast) ?? '';
        ts.forEachChild(node, find);
    };
    find(ast);
    assert.ok(callback);
    const code = ts.transpileModule(`const callback = ${callback};`, { compilerOptions: { target: ts.ScriptTarget.ESNext } }).outputText;
    for (const cached of [null, { inFlight: true }, { inFlight: false, expiresAt: 0 }, { inFlight: false, expiresAt: Date.now() + 60_000 }]) {
        const before = new Function('props', 'router', `${code}; return callback;`)({ href: '/workspaces/kitchen' }, { getCached: () => cached });
        const visit = { component: 'workspaces/kitchen' };
        before(visit);
        assert.equal(visit.component, cached && !cached.inFlight && 'expiresAt' in cached && cached.expiresAt > Date.now() ? null : 'workspaces/kitchen');
    }
});

test('the link callback uses shared props from the second Inertia argument and drops the previous workspace data', () => {
    const source = readFileSync(new URL('../resources/js/components/workspace-link.tsx', import.meta.url), 'utf8');
    const ast = ts.createSourceFile('workspace-link.tsx', source, ts.ScriptTarget.Latest, true, ts.ScriptKind.TSX);
    let callback = '';
    const find = (node: ts.Node) => {
        if (ts.isJsxAttribute(node) && node.name.getText(ast) === 'pageProps' && node.initializer && ts.isJsxExpression(node.initializer)) callback = node.initializer.expression?.getText(ast) ?? '';
        ts.forEachChild(node, find);
    };
    find(ast);
    assert.ok(callback);
    const code = ts.transpileModule(`const callback = ${callback};`, { compilerOptions: { target: ts.ScriptTarget.ESNext } }).outputText;
    const pageProps = new Function('instantWorkspaceProps', 'target', `${code}; return callback;`)(instantWorkspaceProps, { pageProps: { workspace: 'Cashier / POS' } });
    const shared = { auth: { user: { id: 1 } }, branchContext: { current: { id: 'main' } } };
    const props = pageProps({ catalog: { products: ['old'] } }, shared);
    assert.deepEqual(props, { ...shared, workspace: 'Cashier / POS' });
    assert.equal('catalog' in props, false);
});
