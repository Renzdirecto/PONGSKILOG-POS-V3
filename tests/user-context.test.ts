import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    createUserContextEventGuard,
    revalidationOutcome,
    staffChannelFor,
    USER_CONTEXT_EVENT,
    userContextChannel,
} from '../resources/js/lib/user-context.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const userContextHook = source('hooks/use-user-context-realtime.ts');
const invalidationHook = source('hooks/use-invalidation-refresh.ts');

test('the account listens on its own private channel for the context signal', () => {
    assert.equal(userContextChannel(7), 'App.Models.User.7');
    assert.equal(USER_CONTEXT_EVENT, '.user.context_changed');
});

test('a revoked page goes to the workspace and an ended session goes to login', () => {
    assert.equal(revalidationOutcome(403), 'workspace');
    assert.equal(revalidationOutcome(404), 'workspace');
    assert.equal(revalidationOutcome(401), 'login');
    assert.equal(revalidationOutcome(419), 'login');
    assert.equal(revalidationOutcome(500), 'ignore');
    assert.match(
        userContextHook,
        /outcome === 'workspace'\)\s*\{\s*router\.visit\(workspace\.url\(\)/,
    );
    assert.match(userContextHook, /return false;/);
});

test('a context signal is accepted once and only for this account', () => {
    const accept = createUserContextEventGuard(7);

    assert.equal(accept({ user_id: 8, event_id: 'a' }), false);
    assert.equal(accept({ user_id: 7, event_id: 'a' }), true);
    assert.equal(accept({ user_id: 7, event_id: 'a' }), false);
    assert.equal(accept({ user_id: 7, event_id: 'b' }), true);
});

test('staff pages listen on the business channel or only the selected branch channel', () => {
    assert.equal(staffChannelFor(false, null), 'staff');
    assert.equal(staffChannelFor(false, 'main'), 'staff');
    assert.equal(staffChannelFor(true, 'main'), 'branch.main.staff');
    assert.equal(staffChannelFor(true, null), null);
});

test('realtime revalidation never polls and always refetches after a reconnect', () => {
    for (const hook of [userContextHook, invalidationHook]) {
        assert.doesNotMatch(hook, /setInterval/);
        assert.match(hook, /shouldRefetchCatalogAfterConnectionChange/);
        assert.match(hook, /createRealtimeRefresh/);
    }
});
