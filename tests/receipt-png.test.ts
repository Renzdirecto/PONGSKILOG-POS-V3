import assert from 'node:assert/strict';
import { test } from 'node:test';
import { receiptPng } from '../resources/js/lib/receipt-png.ts';

test('PNG export uses only the receipt bounds and includes wrapped text and the decoded logo', async (t) => {
    const drawn: string[] = [];
    const points: number[][] = [];
    let decoded = false;
    class FakeElement {
        childNodes: unknown[] = [];
        getBoundingClientRect() {
            return { left: 20, top: 100, width: 360, height: 900 };
        }
        querySelectorAll() {
            return [logo];
        }
    }
    class FakeImage extends FakeElement {
        async decode() {
            decoded = true;
        }
    }
    class FakeText {
        textContent = 'REF: MAIN-092226-0001\n₱150.00';
    }
    const logo = new FakeImage();
    const card = new FakeElement();
    card.childNodes = [logo, new FakeText()];
    let offset = 0;
    const context = {
        scale: () => {},
        fillRect: () => {},
        beginPath: () => {},
        roundRect: () => {},
        fill: () => {},
        stroke: () => {},
        drawImage: () => {
            assert.equal(decoded, true);
            drawn.push('logo');
        },
        measureText: () => ({
            fontBoundingBoxAscent: 10,
            fontBoundingBoxDescent: 3,
        }),
        fillText: (text: string, x: number, y: number) => {
            drawn.push(text);
            points.push([x, y]);
        },
    };
    const canvas = {
        width: 0,
        height: 0,
        getContext: () => context,
        toBlob: (callback: (blob: Blob | null) => void, type: string) =>
            callback(new Blob(['png'], { type })),
    };
    const globals = {
        Element: FakeElement,
        HTMLImageElement: FakeImage,
        Text: FakeText,
        getComputedStyle: () => ({
            display: 'block',
            visibility: 'visible',
            backgroundColor: '#fff',
            color: '#111',
            fontSize: '13px',
            fontFamily: 'Arial',
            fontStyle: 'normal',
            fontWeight: '400',
        }),
        document: {
            fonts: { ready: Promise.resolve() },
            createElement: () => canvas,
            createRange: () => ({
                setStart: (_: unknown, start: number) => {
                    offset = start;
                },
                setEnd: () => {},
                getBoundingClientRect: () => ({
                    left: 40 + (offset % 10) * 7,
                    top: 120 + Math.floor(offset / 10) * 20,
                    width: 7,
                    height: 16,
                }),
            }),
        },
    };
    for (const [key, value] of Object.entries(globals)) {
        const previous = Object.getOwnPropertyDescriptor(globalThis, key);
        Object.defineProperty(globalThis, key, { configurable: true, value });
        t.after(() => {
            if (previous) Object.defineProperty(globalThis, key, previous);
            else Reflect.deleteProperty(globalThis, key);
        });
    }

    const result = await receiptPng(card as unknown as HTMLElement);

    assert.equal(result.type, 'image/png');
    assert.equal(canvas.width, 720);
    assert.equal(canvas.height, 1800);
    assert.equal(drawn.join(''), 'logoREF:MAIN-092226-0001₱150.00');
    assert.ok(points.every(([x, y]) => x >= 0 && x < 360 && y >= 0 && y < 900));
    assert.ok(new Set(points.map(([, y]) => y)).size > 1);

    canvas.toBlob = (callback) => callback(null);
    await assert.rejects(
        receiptPng(card as unknown as HTMLElement),
        /Unable to save receipt image/,
    );
});
