import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import ts from 'typescript';
import type { TeamCard } from '../resources/js/lib/landing.ts';
import { websiteTabs } from '../resources/js/lib/website-analytics.ts';
import * as teamLib from '../resources/js/lib/website-team.ts';
import {
    TEAM_LIMITS,
    teamCardChanged,
} from '../resources/js/lib/website-team.ts';

const read = (path: string) =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const page = read('pages/workspaces/website-analytics.tsx');
const source = read('components/website-team-settings.tsx');
const member: TeamCard = {
    id: 2,
    code: 'COO',
    title: 'Chief Operating Officer',
    name: 'Shamarra Faustino',
    bio: null,
    img: '/images/landing-v130-a82ec97c.webp',
    visible: true,
};

test('Website offers only the tabs the server allows, in a fixed order', () => {
    const keys = (can: Parameters<typeof websiteTabs>[0]) =>
        websiteTabs(can).map(([key]) => key);
    assert.deepEqual(
        websiteTabs({ analytics: true, reviews: true, team: true }).map(
            ([, label]) => label,
        ),
        ['Analytics', 'Reviews', 'Team'],
    );
    assert.deepEqual(keys({ analytics: true, reviews: false, team: false }), [
        'analytics',
    ]);
    assert.deepEqual(keys({ analytics: false, reviews: true, team: true }), [
        'reviews',
        'team',
    ]);
    assert.match(page, /<Head title="Website" \/>/);
    assert.match(page, /<OwnerPage\s+title="Website"/);
    /** The first tab the account may use opens, and a tab without access never renders its content. */
    assert.match(
        page,
        /useState<WebsiteTab>\(tabs\[0\]\?\.\[0\] \?\? 'analytics'\)/,
    );
    assert.match(
        page,
        /tab === 'team' && can\.team && \(\s*<WebsiteTeamSettings members=\{team\} \/>/,
    );
    assert.match(
        page,
        /const analytics = tab === 'analytics' && report !== null;/,
    );
});

test('Heart Taps shows the period total beside the landing page all-time total inside the existing chart', () => {
    assert.ok(page.includes("'Heart taps'"));
    assert.match(page, /count\(totals\.heart_taps\)/);
    assert.match(
        page,
        /count\(report\.heart_taps_all_time\)\} all-time on the landing page/,
    );
    assert.match(
        page,
        /countLabel\(hearts\.get\(bucket\.key\) \?\? 0, 'heart tap'\)/,
    );
    /** No extra chart, timer or poll: heart taps arrive with the same report the page already refreshes. */
    assert.equal(page.split('<TrendChart').length - 1, 1);
    assert.doesNotMatch(page + source, /usePoll|setInterval|useEcho/);
});

test('a team card offers Save only when something really changed', () => {
    const form = {
        role_label: 'COO',
        role_title: 'Chief Operating Officer',
        name: 'Shamarra Faustino',
        bio: '',
        is_visible: true,
    };
    assert.equal(teamCardChanged(form, member), false);
    /** The server trims text and stores an empty bio as none. */
    assert.equal(teamCardChanged({ ...form, bio: '   ' }, member), false);
    assert.equal(teamCardChanged({ ...form, name: 'Sham' }, member), true);
    assert.equal(teamCardChanged({ ...form, bio: 'Hi' }, member), true);
    assert.equal(teamCardChanged({ ...form, role_label: 'OPS' }, member), true);
    assert.equal(teamCardChanged({ ...form, role_title: 'Ops' }, member), true);
    assert.equal(teamCardChanged({ ...form, is_visible: false }, member), true);
    assert.deepEqual(TEAM_LIMITS, {
        role_label: 12,
        role_title: 60,
        name: 60,
        bio: 120,
    });
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

/** One card of Website → Team with hand-driven React state and a pretend server. */
function editorHarness(card: TeamCard, reply: () => Promise<unknown>) {
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
    const requests: { route: string; data: unknown }[] = [];
    const reloads: { only: string[]; onFinish: () => void }[] = [];
    const toasts: string[] = [];
    let state = 0;
    let reference = 0;
    const named = new Proxy({}, { get: (_module, key) => key });
    const exports: Record<string, (props: { member: TeamCard }) => unknown> =
        {};
    new Function(
        'require',
        'exports',
        `${compiled}; exports.TeamMemberEditor = TeamMemberEditor;`,
    )((name: string) => {
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
            };
        if (name === 'react/jsx-runtime')
            return {
                jsx: (type: unknown, props: unknown) => ({ type, props }),
                jsxs: (type: unknown, props: unknown) => ({ type, props }),
            };
        if (name === '@inertiajs/react')
            return {
                router: {
                    reload: (options: (typeof reloads)[number]) =>
                        reloads.push(options),
                },
            };
        if (name === 'sonner')
            return {
                toast: { success: (message: string) => toasts.push(message) },
            };
        if (name === '@/lib/website-team') return teamLib;
        if (name === '@/lib/qr-http')
            return {
                qrRequest: (route: string, data: unknown) => {
                    requests.push({ route, data });
                    return reply();
                },
                qrError: (failure: Error) => ({ message: failure.message }),
            };
        if (name === '@/routes/workspaces/website/team')
            return { update: (id: number) => `PUT team/${id}` };
        if (name === '@/routes/workspaces/website/team/photo')
            return {
                store: (id: number) => `POST team/${id}/photo`,
                destroy: (id: number) => `DELETE team/${id}/photo`,
            };
        return named;
    }, exports);
    const render = () => {
        state = 0;
        reference = 0;
        return elements(exports.TeamMemberEditor({ member: card }));
    };
    return {
        render,
        requests,
        reloads,
        toasts,
        find: (label: string) =>
            render().find(
                (node) =>
                    node.props.id === `team-${card.id}-${label}` ||
                    node.props['aria-label'] === label,
            )!,
        button: (text: string) =>
            render().find(
                (node) => node.type === 'Button' && words(node).includes(text),
            )!,
        message: () =>
            render().find((node) => node.type === 'p' && 'role' in node.props)!,
    };
}
const settle = () => new Promise((resolve) => setImmediate(resolve));

test('saving a team card sends its details once and refreshes only the team', async () => {
    const h = editorHarness(member, async () => ({ saved: true }));
    assert.equal(h.button('Save card').props.disabled, true);
    h.find('name').props.onChange({ target: { value: 'Sham Faustino' } });
    h.find('bio').props.onChange({
        target: { value: 'Nasa kusina gabi-gabi.' },
    });
    h.render()
        .find((node) => node.type === 'SettingSwitch')!
        .props.onChange(false);
    assert.equal(h.button('Save card').props.disabled, false);
    assert.equal(words(h.message()), 'Unsaved changes.');

    const form = h.render().find((node) => node.type === 'form')!;
    assert.equal(form.props['aria-label'], 'COO card');
    let prevented = false;
    form.props.onSubmit({
        preventDefault() {
            prevented = true;
        },
    });
    /** A second submit while the first is still saving is ignored. */
    h.render()
        .find((node) => node.type === 'form')!
        .props.onSubmit({ preventDefault() {} });
    await settle();
    assert.ok(prevented);
    assert.deepEqual(h.requests, [
        {
            route: 'PUT team/2',
            data: {
                role_label: 'COO',
                role_title: 'Chief Operating Officer',
                name: 'Sham Faustino',
                bio: 'Nasa kusina gabi-gabi.',
                is_visible: false,
            },
        },
    ]);
    assert.deepEqual(h.toasts, ['Team card saved']);
    assert.deepEqual(
        h.reloads.map(({ only }) => only),
        [['team']],
    );
});

test('a card without a role label or role cannot be saved and says what is required', () => {
    const h = editorHarness(member, async () => ({ saved: true }));
    h.find('label').props.onChange({ target: { value: '  ' } });
    assert.equal(h.find('label').props['aria-invalid'], true);
    assert.equal(h.find('label').props.maxLength, 12);
    assert.equal(h.button('Save card').props.disabled, true);
    assert.equal(words(h.message()), 'Required: a role label and a role.');
    h.render()
        .find((node) => node.type === 'form')!
        .props.onSubmit({ preventDefault() {} });
    assert.equal(h.requests.length, 0);
    /** Name and bio are optional, so they are never marked. */
    assert.equal('aria-invalid' in h.find('name').props, false);
    assert.equal(h.find('bio').props.maxLength, 120);
});

test('a portrait is uploaded, changed or removed right away and one over 5 MB is refused before sending', async () => {
    const h = editorHarness(member, async () => ({ saved: true }));
    const input = () => h.find('Choose a photo for the COO card');
    assert.equal(input().props.accept, 'image/jpeg,image/png,image/webp');
    assert.ok(h.button('Change photo'));

    input().props.onChange({
        target: { files: [{ size: 5 * 1024 * 1024 + 1 }], value: 'big.jpg' },
    });
    assert.equal(words(h.message()), 'Choose an image of at most 5 MB.');
    assert.equal(h.message().props.role, 'alert');
    assert.equal(h.requests.length, 0);

    const photo = new File(['portrait'], 'me.jpg', { type: 'image/jpeg' });
    input().props.onChange({ target: { files: [photo], value: 'me.jpg' } });
    await settle();
    assert.equal(h.requests[0].route, 'POST team/2/photo');
    assert.equal((h.requests[0].data as FormData).get('image'), photo);
    assert.deepEqual(h.toasts, ['Team photo saved']);
    h.reloads[0].onFinish();

    h.button('Remove photo').props.onClick();
    await settle();
    assert.equal(h.requests[1].route, 'DELETE team/2/photo');
    assert.deepEqual(h.toasts, ['Team photo saved', 'Team photo removed']);

    /** A card with no portrait offers Upload and no Remove. */
    const empty = editorHarness({ ...member, img: null }, async () => ({}));
    assert.ok(empty.button('Upload photo'));
    assert.equal(empty.button('Remove photo'), undefined);
    assert.ok(empty.render().some((node) => words(node) === 'Photo to follow'));
});

test('a change the server refuses shows its reason and does not pretend to be saved', async () => {
    const h = editorHarness(member, async () => {
        throw new Error(
            'The role label field must not be greater than 12 characters.',
        );
    });
    h.find('name').props.onChange({ target: { value: 'Someone Else' } });
    h.render()
        .find((node) => node.type === 'form')!
        .props.onSubmit({ preventDefault() {} });
    await settle();
    assert.equal(
        words(h.message()),
        'The role label field must not be greater than 12 characters.',
    );
    assert.equal(h.message().props.role, 'alert');
    assert.deepEqual(h.toasts, []);
    assert.equal(h.reloads.length, 0);
    /** The form stays open with what was typed, ready to fix and save again. */
    assert.equal(h.find('name').props.value, 'Someone Else');
    assert.equal(h.button('Save card').props.disabled, false);
});
