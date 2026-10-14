import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import type { TestContext } from 'node:test';
import ts from 'typescript';
import * as shippyLib from '../resources/js/lib/landing-shippy.ts';
import {
    SHIPPY_GREETING,
    SHIPPY_KEPT_MESSAGES,
    SHIPPY_MAX_HISTORY,
    SHIPPY_MAX_MESSAGE,
    SHIPPY_MAX_TURN,
    shippyAppend,
    shippyHistory,
    shippyQuestion,
    shippyReply,
    shippyUnavailable,
} from '../resources/js/lib/landing-shippy.ts';
import type { ShippyMessage } from '../resources/js/lib/landing-shippy.ts';

const FACEBOOK = 'https://www.facebook.com/profile.php?id=100094575655003';
const read = (path: string) =>
    readFileSync(new URL(`../resources/${path}`, import.meta.url), 'utf8');
const source = read('js/components/landing-shippy.tsx');
const settle = () => new Promise((resolve) => setImmediate(resolve));

test('a server reply keeps only text, this site’s own photos and safe links', () => {
    assert.deepEqual(
        shippyReply({
            text: '  Tapa Silog ay ₱100.  ',
            items: [
                {
                    name: 'Tapa Silog',
                    price: '₱100',
                    img: '/images/landing-v121-822fd717.webp',
                },
                {
                    name: 'Bulalo',
                    price: '₱150',
                    img: 'https://evil.example/x.png',
                },
                { name: 'Rice', price: '₱20', img: '/images/../../etc/passwd' },
                { name: 'Egg', price: '₱15', img: 'javascript:alert(1)' },
                { name: '', price: '₱1', img: null },
                'not an item',
                null,
            ],
            actions: [
                {
                    key: 'menu',
                    label: 'See the menu',
                    href: '#board',
                    external: false,
                },
                {
                    key: 'maps',
                    label: 'Open in Google Maps',
                    href: 'https://maps.app.goo.gl/nivpx3cxuP56wAXo8',
                    external: true,
                },
                { key: 'x', label: 'Click', href: 'javascript:alert(1)' },
                { key: 'y', label: 'Click', href: 'http://plain.example' },
                { key: 'z', label: 'Click', href: '//evil.example' },
                { key: 'w', label: 'Click', href: '/workspace' },
                { key: 'v', label: '', href: '#board' },
            ],
            source: 'ai',
            html: '<script>alert(1)</script>',
        }),
        {
            text: 'Tapa Silog ay ₱100.',
            items: [
                {
                    name: 'Tapa Silog',
                    price: '₱100',
                    img: '/images/landing-v121-822fd717.webp',
                },
                { name: 'Bulalo', price: '₱150', img: null },
                { name: 'Rice', price: '₱20', img: null },
                { name: 'Egg', price: '₱15', img: null },
            ],
            actions: [
                {
                    key: 'menu',
                    label: 'See the menu',
                    href: '#board',
                    external: false,
                },
                {
                    key: 'maps',
                    label: 'Open in Google Maps',
                    href: 'https://maps.app.goo.gl/nivpx3cxuP56wAXo8',
                    external: true,
                },
            ],
        },
    );
    for (const broken of [
        null,
        'text',
        42,
        [],
        {},
        { text: '   ' },
        { text: 7 },
    ])
        assert.equal(shippyReply(broken), null);
    /** Missing lists are simply empty: a reply is text first. */
    assert.deepEqual(shippyReply({ text: 'Hi' }), {
        text: 'Hi',
        items: [],
        actions: [],
    });
});

test('a failed request is answered on the page: a pause when too fast, otherwise the way to Facebook', () => {
    const slow = shippyUnavailable(429, FACEBOOK);
    assert.match(slow.text, /^Teka lang/);
    assert.match(shippyUnavailable(429, FACEBOOK, 'english').text, /^Hold on/);
    assert.match(
        shippyUnavailable(500, FACEBOOK, 'english').text,
        /^I can't answer right now\. Message PONGSKILOG on Facebook/,
    );
    assert.deepEqual(slow.actions, []);
    for (const status of [0, 404, 419, 500]) {
        const reply = shippyUnavailable(status, FACEBOOK);
        assert.match(reply.text, /Message mo ang PONGSKILOG sa Facebook/);
        assert.deepEqual(reply.actions, [
            {
                key: 'messenger',
                label: 'Message on Facebook',
                href: FACEBOOK,
                external: true,
            },
        ]);
    }
});

test('a question and its history are clipped to what the server accepts', () => {
    assert.equal(SHIPPY_MAX_MESSAGE, 200);
    assert.equal(SHIPPY_MAX_HISTORY, 4);
    assert.equal(SHIPPY_MAX_TURN, 420);
    assert.equal(shippyQuestion('   Magkano?  '), 'Magkano?');
    assert.equal(shippyQuestion('   '), '');
    assert.equal(shippyQuestion('a'.repeat(500)).length, 200);

    const messages: ShippyMessage[] = [
        { from: 'user', text: 'one' },
        { from: 'bot', text: 'two', items: [], actions: [] },
        { from: 'user', text: 'three' },
        {
            from: 'bot',
            text: 'b'.repeat(900),
            items: [{ name: 'Tapa Silog', price: '₱100', img: null }],
            actions: [],
        },
        { from: 'user', text: 'five' },
    ];
    /** The last four turns, oldest first, as text only: cards and buttons are never sent back. */
    assert.deepEqual(shippyHistory(messages), [
        { role: 'bot', text: 'two' },
        { role: 'user', text: 'three' },
        { role: 'bot', text: 'b'.repeat(420) },
        { role: 'user', text: 'five' },
    ]);
    assert.deepEqual(shippyHistory([]), []);
});

test('the conversation keeps only its most recent messages in memory', () => {
    let messages: ShippyMessage[] = [];
    for (let index = 0; index < SHIPPY_KEPT_MESSAGES + 7; index++)
        messages = shippyAppend(messages, { from: 'user', text: `${index}` });
    assert.equal(messages.length, SHIPPY_KEPT_MESSAGES);
    assert.equal(messages[0].text, '7');
    assert.equal(messages.at(-1)!.text, `${SHIPPY_KEPT_MESSAGES + 6}`);
});

type Element = { type: unknown; props: Record<string, any> };
function elements(value: any): Element[] {
    if (!value || typeof value !== 'object') return [];
    if (Array.isArray(value)) return value.flatMap(elements);
    return [value, ...elements(value.props?.children)];
}
function words(value: any): string {
    if (typeof value === 'string' || typeof value === 'number')
        return String(value);
    if (Array.isArray(value)) return value.map(words).join('');
    return value?.props ? words(value.props.children) : '';
}

/**
 * The chat with hand-driven React (state, refs and effects that re-run when their dependencies change), a pretend
 * server and a pretend browser. `narrow` is a phone; `loveInvite` is the tap-to-love invitation being on screen.
 */
function shippyHarness(
    t: TestContext,
    options: {
        narrow?: boolean;
        seen?: boolean;
        loveInvite?: boolean;
        reply?: (data: any) => Promise<unknown>;
    } = {},
) {
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'] });
    const compiled = ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ESNext,
            jsx: ts.JsxEmit.ReactJSX,
            esModuleInterop: true,
        },
    }).outputText;
    const states: any[] = [];
    const refs: { current: any }[] = [];
    let pending: { effect: () => any; deps?: unknown[] }[] = [];
    const ran: { deps?: unknown[]; cleanup?: () => void }[] = [];
    const requests: { route: unknown; data: any }[] = [];
    const sections: string[] = [];
    const stored = new Map<string, string>(
        options.seen ? [['pongskilog.shippyTeaser', '1']] : [],
    );
    const listening: Record<string, (event?: any) => void> = {};
    const page = {
        loveInvite: options.loveInvite ?? false,
        overflow: '',
        active: null as any,
    };
    let state = 0;
    let reference = 0;
    const previous = {
        window: (globalThis as any).window,
        document: (globalThis as any).document,
        sessionStorage: (globalThis as any).sessionStorage,
    };
    Object.assign(globalThis, {
        sessionStorage: {
            getItem: (key: string) => stored.get(key) ?? null,
            setItem: (key: string, value: string) => stored.set(key, value),
        },
        window: {
            innerHeight: 740,
            matchMedia: (query: string) => ({
                matches: query.includes('699px')
                    ? (options.narrow ?? false)
                    : false,
            }),
            addEventListener: (name: string, listener: () => void) => {
                listening[`window:${name}`] = listener;
            },
            removeEventListener: (name: string) => {
                delete listening[`window:${name}`];
            },
        },
        document: {
            querySelector: (selector: string) =>
                selector === '.love-invite' && page.loveInvite ? {} : null,
            documentElement: {
                style: {
                    get overflow() {
                        return page.overflow;
                    },
                    set overflow(value: string) {
                        page.overflow = value;
                    },
                },
            },
            get activeElement() {
                return page.active;
            },
            addEventListener: (name: string, listener: () => void) => {
                listening[name] = listener;
            },
            removeEventListener: (name: string) => {
                delete listening[name];
            },
        },
    });
    const exports: Record<string, (props: any) => unknown> = {};
    new Function('require', 'exports', compiled)((name: string) => {
        if (name === 'react')
            return {
                useState: (initial: unknown) => {
                    const index = state++;
                    if (!(index in states)) states[index] = initial;
                    return [
                        states[index],
                        (next: unknown) => {
                            states[index] =
                                typeof next === 'function'
                                    ? next(states[index])
                                    : next;
                        },
                    ];
                },
                useRef: (value: unknown) => {
                    const index = reference++;
                    refs[index] ??= { current: value };
                    return refs[index];
                },
                useEffect: (effect: () => any, deps?: unknown[]) =>
                    pending.push({ effect, deps }),
            };
        if (name === 'react/jsx-runtime')
            return {
                jsx: (type: unknown, props: unknown) => ({ type, props }),
                jsxs: (type: unknown, props: unknown) => ({ type, props }),
            };
        if (name === '@/lib/landing') return { FB: FACEBOOK };
        if (name === '@/lib/landing-shippy') return shippyLib;
        if (name === '@/routes/shippy/messages')
            return {
                store: () => ({ url: '/shippy/messages', method: 'post' }),
            };
        if (name === '@/lib/qr-http')
            return {
                qrRequest: (route: unknown, data: any) => {
                    requests.push({ route, data });
                    return (
                        options.reply ??
                        (async () => ({
                            reply: {
                                text: `Sagot sa: ${data.message}`,
                                items: [],
                                actions: [],
                            },
                        }))
                    )(data);
                },
                qrError: (failure: { status?: number }) => ({
                    status: failure.status ?? 0,
                    message: '',
                }),
            };
        throw new Error(`Unexpected import ${name}`);
    }, exports);
    /** Renders, then runs the effects whose dependencies changed, as React does after a commit. */
    const render = () => {
        state = 0;
        reference = 0;
        pending = [];
        const tree = elements(
            exports.LandingShippy({
                presets: ['Open ba kayo ngayon?', 'Magkano ang tapsilog?'],
                onSection: (id: string) => sections.push(id),
            }),
        );
        pending.forEach(({ effect, deps }, index) => {
            const before = ran[index];
            if (
                before &&
                deps &&
                before.deps &&
                deps.length === before.deps.length &&
                deps.every((value, at) => Object.is(value, before.deps![at]))
            )
                return;
            before?.cleanup?.();
            ran[index] = { deps, cleanup: effect() ?? undefined };
        });
        return tree;
    };
    const find = (label: string) =>
        render().find((node) => node.props['aria-label'] === label);
    return {
        render,
        find,
        requests,
        sections,
        stored,
        listening,
        page,
        refs,
        byClass: (name: string) =>
            render().filter((node) => node.props.className === name),
        async wait(milliseconds: number) {
            t.mock.timers.tick(milliseconds);
            await settle();
        },
        /** Opens the chat as a visitor does and gives the panel the elements a browser would. */
        open() {
            find('Chat with Shippy')!.props.onClick();
            const field = { focused: 0, focus: () => field.focused++ };
            const panel = { style: {} as Record<string, string> };
            refs[0].current = panel;
            refs[1].current = { scrollHeight: 900, scrollTo() {} };
            refs[2].current = field;
            render();
            return { field, panel };
        },
        dispose() {
            ran.forEach((effect) => effect?.cleanup?.());
            Object.assign(globalThis, previous);
        },
    };
}

test('Shippy starts as a small launcher at the corner and invites once per browser tab', async (t) => {
    const h = shippyHarness(t);
    try {
        const launcher = h.find('Chat with Shippy')!;
        assert.equal(launcher.type, 'button');
        assert.equal(launcher.props['aria-haspopup'], 'dialog');
        assert.equal(launcher.props.className, 'shippy-launcher');
        /** The mascot is the resized copy of public/images/shippy.png. */
        assert.equal(
            elements(launcher).find((node) => node.type === 'img')!.props.src,
            '/images/landing-v130-6e8c6348.webp',
        );
        assert.equal(h.byClass('shippy-teaser').length, 0);
        await h.wait(3199);
        assert.equal(h.byClass('shippy-teaser').length, 0);
        await h.wait(1);
        const [teaser] = h.byClass('shippy-teaser');
        assert.equal(
            words(teaser),
            'Gutom ka na? Tanong ka kay Shippy tungkol sa menu at presyo.',
        );

        h.find('Dismiss')!.props.onClick();
        assert.equal(h.byClass('shippy-teaser').length, 0);
        assert.equal(h.stored.get('pongskilog.shippyTeaser'), '1');
    } finally {
        h.dispose();
    }
});

test('the invitation does not return in a tab that already saw it', async (t) => {
    const h = shippyHarness(t, { seen: true });
    try {
        await h.wait(10000);
        assert.equal(h.byClass('shippy-teaser').length, 0);
        assert.ok(h.find('Chat with Shippy'));
    } finally {
        h.dispose();
    }
});

test('on a phone the invitation waits until the tap-to-love one is gone', async (t) => {
    const h = shippyHarness(t, { narrow: true, loveInvite: true });
    try {
        await h.wait(3200);
        assert.equal(h.byClass('shippy-teaser').length, 0);
        await h.wait(3000);
        assert.equal(h.byClass('shippy-teaser').length, 0);
        h.page.loveInvite = false;
        await h.wait(3000);
        assert.equal(h.byClass('shippy-teaser').length, 1);
    } finally {
        h.dispose();
    }
});

test('the open chat is a labelled dialog with the preset questions, and Escape closes it and returns focus', async (t) => {
    const h = shippyHarness(t);
    try {
        const { field } = h.open();
        const [panel] = h.byClass('shippy-panel');
        assert.equal(panel.props.role, 'dialog');
        assert.equal(panel.props['aria-label'], 'Chat with Shippy');
        /** The launcher gives way to the panel; on a desktop the input is ready for typing. */
        assert.equal(h.find('Chat with Shippy')!.props.role, 'dialog');
        assert.equal(field.focused, 1);
        assert.equal(h.page.overflow, '');
        assert.equal(h.stored.get('pongskilog.shippyTeaser'), '1');
        const log = h.find('Conversation with Shippy')!;
        assert.equal(log.props.role, 'log');
        assert.equal(log.props['aria-live'], 'polite');
        assert.equal(words(h.byClass('shippy-theirs')[0]), SHIPPY_GREETING);
        assert.deepEqual(
            elements(h.find('Suggested questions')).flatMap((node) =>
                node.type === 'button' ? [words(node)] : [],
            ),
            ['Open ba kayo ngayon?', 'Magkano ang tapsilog?'],
        );
        const input = h.render().find((node) => node.type === 'input')!;
        assert.equal(input.props.maxLength, 200);
        assert.equal(input.props.id, 'shippy-question');
        assert.equal(h.find('Send')!.props.disabled, true);
        assert.ok(h.find('Close chat'));
        assert.ok(h.find('Start over'));

        /** Escape pressed inside another open dialog (a review, the Privacy Notice) belongs to that dialog. */
        h.page.active = { closest: () => ({ other: true }) };
        h.listening.keydown({ key: 'Escape' });
        assert.equal(h.byClass('shippy-panel').length, 1);

        h.page.active = { closest: () => null };
        const launcher = { focused: 0, focus: () => launcher.focused++ };
        h.refs[3].current = launcher;
        h.listening.keydown({ key: 'Enter' });
        assert.equal(h.byClass('shippy-panel').length, 1);
        h.listening.keydown({ key: 'Escape' });
        assert.equal(h.byClass('shippy-panel').length, 0);
        assert.equal(launcher.focused, 1);
        assert.equal(h.listening.keydown, undefined);
    } finally {
        h.dispose();
    }
});

test('a preset travels without the conversation and a typed follow-up carries the turns before it', async (t) => {
    const h = shippyHarness(t);
    try {
        h.open();
        const preset = () =>
            elements(h.find('Suggested questions')).filter(
                (node) => node.type === 'button',
            );
        preset()[1].props.onClick();
        /** The question shows at once, the typing dots follow and the buttons wait for the answer. */
        assert.equal(
            words(h.byClass('shippy-mine')[0]),
            'Magkano ang tapsilog?',
        );
        assert.ok(h.find('Shippy is typing'));
        assert.equal(preset()[0].props.disabled, true);
        await h.wait(500);
        await h.wait(0);
        assert.equal(h.find('Shippy is typing'), undefined);
        assert.deepEqual(h.requests[0], {
            route: { url: '/shippy/messages', method: 'post' },
            data: { message: 'Magkano ang tapsilog?', history: [] },
        });
        assert.equal(
            words(h.byClass('shippy-theirs').at(-1)),
            'Sagot sa: Magkano ang tapsilog?',
        );

        const input = () => h.render().find((node) => node.type === 'input')!;
        input().props.onChange({ target: { value: '  May kanin ba yun?  ' } });
        assert.equal(h.find('Send')!.props.disabled, false);
        let prevented = false;
        h.render()
            .find((node) => node.type === 'form')!
            .props.onSubmit({
                preventDefault() {
                    prevented = true;
                },
            });
        assert.ok(prevented);
        assert.equal(input().props.value, '');
        /** A second send while Shippy is still answering is ignored. */
        h.render()
            .find((node) => node.type === 'form')!
            .props.onSubmit({ preventDefault() {} });
        await h.wait(500);
        await h.wait(0);
        assert.equal(h.requests.length, 2);
        assert.deepEqual(h.requests[1].data, {
            message: 'May kanin ba yun?',
            history: [
                { role: 'user', text: 'Magkano ang tapsilog?' },
                { role: 'bot', text: 'Sagot sa: Magkano ang tapsilog?' },
            ],
        });

        h.find('Start over')!.props.onClick();
        assert.equal(h.byClass('shippy-mine').length, 0);
        assert.equal(h.byClass('shippy-theirs').length, 1);
    } finally {
        h.dispose();
    }
});

test('a language the guest asked for travels with every later question until Start over', async (t) => {
    const h = shippyHarness(t, {
        reply: async (data) => ({
            reply: {
                text: `Sagot sa: ${data.message}`,
                items: [],
                actions: [],
                preference: /english/i.test(data.message)
                    ? 'english'
                    : (data.preference ?? null),
            },
        }),
    });
    try {
        h.open();
        const send = async (question: string) => {
            h.render()
                .find((node) => node.type === 'input')!
                .props.onChange({ target: { value: question } });
            h.render()
                .find((node) => node.type === 'form')!
                .props.onSubmit({ preventDefault() {} });
            await h.wait(500);
            await h.wait(0);
        };
        await send('Talk in English');
        await send('Magkano ang tapsilog?');
        h.find('Start over')!.props.onClick();
        await send('Magkano ang bulalo?');

        assert.deepEqual(
            h.requests.map((request) => request.data.preference),
            [undefined, 'english', undefined],
        );
    } finally {
        h.dispose();
    }
});

test('a reply is rendered as plain text with its product cards and approved buttons', async (t) => {
    const h = shippyHarness(t, {
        reply: async () => ({
            reply: {
                text: '<img src=x onerror=alert(1)> Tapa Silog ay ₱100.',
                items: [
                    {
                        name: 'Tapa Silog',
                        price: '₱100',
                        img: '/images/landing-v121-822fd717.webp',
                    },
                    {
                        name: 'Mon, Tue, Thu to Sat',
                        price: '6:00 PM',
                        img: null,
                    },
                ],
                actions: [
                    {
                        key: 'menu',
                        label: 'See the menu',
                        href: '#board',
                        external: false,
                    },
                    {
                        key: 'maps',
                        label: 'Open in Google Maps',
                        href: 'https://maps.app.goo.gl/nivpx3cxuP56wAXo8',
                        external: true,
                    },
                    { key: 'x', label: 'Free', href: 'javascript:alert(1)' },
                ],
            },
        }),
    });
    try {
        h.open();
        elements(h.find('Suggested questions'))
            .find((node) => node.type === 'button')!
            .props.onClick();
        await h.wait(500);
        await h.wait(0);
        const answer = h.byClass('shippy-theirs').at(-1)!;
        /** The markup arrives as characters in a text node: React escapes it, nothing is injected. */
        assert.equal(
            elements(answer).find((node) => node.type === 'p')!.props.children,
            '<img src=x onerror=alert(1)> Tapa Silog ay ₱100.',
        );
        assert.doesNotMatch(source, /dangerouslySetInnerHTML|innerHTML/);
        const cards = elements(answer).filter((node) => node.type === 'li');
        assert.deepEqual(
            cards.map((card) => [
                words(elements(card).find((node) => node.type === 'span')),
                words(elements(card).find((node) => node.type === 'b')),
                elements(card).find((node) => node.type === 'img')?.props.src ??
                    null,
            ]),
            [
                ['Tapa Silog', '₱100', '/images/landing-v121-822fd717.webp'],
                ['Mon, Tue, Thu to Sat', '6:00 PM', null],
            ],
        );
        const links = elements(answer).filter((node) => node.type === 'a');
        assert.deepEqual(
            links.map((link) => [
                link.props.href,
                link.props.target,
                link.props.rel,
            ]),
            [
                ['#board', undefined, 'noopener noreferrer'],
                [
                    'https://maps.app.goo.gl/nivpx3cxuP56wAXo8',
                    '_blank',
                    'noopener noreferrer',
                ],
            ],
        );

        /** A button to a section scrolls the page there; an outside link is left to the browser. */
        let prevented = 0;
        links[1].props.onClick({ preventDefault: () => prevented++ });
        assert.equal(prevented, 0);
        links[0].props.onClick({ preventDefault: () => prevented++ });
        await h.wait(40);
        assert.equal(prevented, 1);
        assert.deepEqual(h.sections, ['board']);
        assert.equal(h.byClass('shippy-panel').length, 1);
    } finally {
        h.dispose();
    }
});

test('a request that fails still answers: a pause when too fast, otherwise the Facebook link', async (t) => {
    let status = 429;
    const h = shippyHarness(t, {
        reply: async () => {
            throw { status };
        },
    });
    try {
        h.open();
        const ask = () =>
            elements(h.find('Suggested questions'))
                .find((node) => node.type === 'button')!
                .props.onClick();
        ask();
        await h.wait(500);
        await h.wait(0);
        assert.match(words(h.byClass('shippy-theirs').at(-1)), /^Teka lang/);

        status = 500;
        ask();
        await h.wait(500);
        await h.wait(0);
        const answer = h.byClass('shippy-theirs').at(-1)!;
        assert.match(words(answer), /Hindi ako makasagot ngayon/);
        assert.equal(
            elements(answer).find((node) => node.type === 'a')!.props.href,
            FACEBOOK,
        );
        assert.equal(h.find('Shippy is typing'), undefined);
    } finally {
        h.dispose();
    }
});

test('on a phone the chat fills the visible screen, stops the page scrolling and closes before going to a section', async (t) => {
    const h = shippyHarness(t, {
        narrow: true,
        reply: async () => ({
            reply: {
                text: 'Ito ang menu.',
                items: [],
                actions: [
                    {
                        key: 'reviews',
                        label: 'See the reviews',
                        href: '#reviews',
                        external: false,
                    },
                ],
            },
        }),
    });
    try {
        const { field, panel } = h.open();
        /** No keyboard is forced open on a phone, and the panel follows the visible height. */
        assert.equal(field.focused, 0);
        assert.equal(panel.style.top, '0px');
        assert.equal(panel.style.height, '740px');
        assert.equal(h.page.overflow, 'hidden');

        elements(h.find('Suggested questions'))
            .find((node) => node.type === 'button')!
            .props.onClick();
        await h.wait(500);
        await h.wait(0);
        elements(h.byClass('shippy-theirs').at(-1))
            .find((node) => node.type === 'a')!
            .props.onClick({ preventDefault() {} });
        assert.equal(h.byClass('shippy-panel').length, 0);
        assert.equal(h.page.overflow, '');
        await h.wait(40);
        assert.deepEqual(h.sections, ['reviews']);
    } finally {
        h.dispose();
    }
});

test('the conversation is never written to the browser: only that the invitation was seen', () => {
    assert.doesNotMatch(source, /localStorage|indexedDB|document\.cookie/);
    assert.equal(source.split('sessionStorage.setItem').length - 1, 1);
    assert.match(source, /sessionStorage\.setItem\(TEASED, '1'\)/);
    /** The chat talks only to this site's own endpoint; the Gemini key and address never reach the browser. */
    assert.doesNotMatch(source, /generativelanguage|gemini|api[_-]?key/i);
    assert.match(source, /from '@\/routes\/shippy\/messages'/);
});

test('the chat is styled as a corner launcher, a floating desktop panel and a full-screen phone panel', () => {
    const css = read('css/landing.css');
    const rule = (selector: string, from = css) =>
        new RegExp(
            `${selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')} \\{([^}]*)\\}`,
        ).exec(from)?.[1] ?? '';
    const launcher = rule('.landing-v3 .shippy');
    assert.match(launcher, /position: fixed;/);
    assert.match(
        launcher,
        /right: calc\(16px \+ env\(safe-area-inset-right\)\);/,
    );
    assert.match(
        launcher,
        /bottom: calc\(42px \+ env\(safe-area-inset-bottom\)\);/,
    );
    const panel = rule('.landing-v3 .shippy-panel');
    assert.match(panel, /width: 392px;/);
    assert.match(panel, /height: min\(660px, calc\(100dvh - 40px\)\);/);
    /** Under the review and Privacy Notice dialogs, over the tap-to-love chef. */
    assert.match(panel, /z-index: 85;/);
    assert.match(rule('.landing-v3 .love'), /z-index: 74;/);
    /** 16px keeps a phone browser from zooming into the field. */
    assert.match(rule('.landing-v3 .shippy-form input'), /font-size: 16px;/);
    assert.match(rule('.landing-v3 .shippy-chips button'), /height: 44px;/);
    assert.match(rule('.landing-v3 .shippy-actions a'), /min-height: 44px;/);

    const phone = css.slice(css.lastIndexOf('@media (max-width: 699px)'));
    const full = rule('.landing-v3 .shippy-panel', phone);
    assert.match(full, /width: 100%;/);
    assert.match(full, /height: 100dvh;/);
    assert.match(full, /border-radius: 0;/);
    assert.match(
        css,
        /@media \(prefers-reduced-motion: reduce\) \{\s+\.landing-v3 \.shippy-teaser,/,
    );
});
