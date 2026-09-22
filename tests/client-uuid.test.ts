import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createClientUuid } from '../resources/js/lib/client-uuid.ts';

test('client IDs use native UUID generation when available', (context) => {
    const expected = 'b9f3b28c-7ce0-49ba-9c54-7316f97cd8c3';
    context.mock.getter(globalThis, 'crypto', () => ({
        randomUUID: () => expected,
    }));

    assert.equal(createClientUuid(), expected);
});

for (const [byte, expected] of [
    [0, '00000000-0000-4000-8000-000000000000'],
    [255, 'ffffffff-ffff-4fff-bfff-ffffffffffff'],
] as const) {
    test(`LAN HTTP client IDs remain valid UUIDs with random byte ${byte}`, (context) => {
        context.mock.getter(globalThis, 'crypto', () => ({
            getRandomValues: (bytes: Uint8Array) => bytes.fill(byte),
        }));

        assert.equal(createClientUuid(), expected);
    });
}
