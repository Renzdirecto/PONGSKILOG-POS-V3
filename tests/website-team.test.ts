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
    /** v1.3.0 Batch 2: Shippy is the last tab, for the accounts that manage website content. */
    assert.deepEqual(
        keys({ analytics: true, reviews: true, team: true, shippy: true }),
        ['analytics', 'reviews', 'team', 'shippy'],
    );
    assert.match(
        page,
        /tab === 'shippy' && can\.shippy && shippy !== null && \(\s*<WebsiteShippySettings shippy=\{shippy\} \/>/,
    );
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

test('a photo is framed in the 3:4 card: zoom 1 just covers it and a drag never uncovers an edge', () => {
    const { clampTeamCrop, movedTeamCrop, teamCropFrame, teamCropRect } =
        teamLib;
    const landscape = { width: 4000, height: 3000 };
    const centred = { zoom: 1, x: 0.5, y: 0.5 };
    /** The full height of a wide photo, 3:4 out of its middle. */
    assert.deepEqual(teamCropRect(centred, landscape), {
        x: 875,
        y: 0,
        width: 2250,
        height: 3000,
    });
    /** Twice the zoom shows half the width and height around the same point. */
    assert.deepEqual(teamCropRect({ ...centred, zoom: 2 }, landscape), {
        x: 1437.5,
        y: 750,
        width: 1125,
        height: 1500,
    });
    /** The zoom stays between covering the card and four times that. */
    assert.equal(clampTeamCrop({ ...centred, zoom: 0.2 }, landscape).zoom, 1);
    assert.equal(clampTeamCrop({ ...centred, zoom: 9 }, landscape).zoom, 4);

    /** Dragging right shows more of the left side, and stops at the photo's edge. */
    const dragged = movedTeamCrop(centred, landscape, 0.2, 0);
    assert.ok(dragged.x < 0.5);
    assert.equal(
        teamCropRect(movedTeamCrop(centred, landscape, 5, 5), landscape).x,
        0,
    );
    assert.equal(
        teamCropRect(movedTeamCrop(centred, landscape, -5, 0), landscape).x,
        4000 - 2250,
    );
    /** A tall photo is already 3:4 wide enough: it can move up and down only. */
    const tall = { width: 1200, height: 2400 };
    assert.equal(movedTeamCrop(centred, tall, 1, 0).x, 0.5);
    assert.deepEqual(teamCropRect(movedTeamCrop(centred, tall, 0, 5), tall), {
        x: 0,
        y: 0,
        width: 1200,
        height: 1600,
    });

    /** The preview places the photo in percent of the card, so it fits any preview size. */
    assert.deepEqual(teamCropFrame(centred, landscape), {
        width: '177.778%',
        height: '100.000%',
        left: '-38.889%',
        top: '0.000%',
    });
    assert.deepEqual(teamLib.TEAM_PHOTO, { width: 810, height: 1080 });
});

test('the photo is cut to the card in the browser and the original never leaves it', () => {
    const dialog = read('components/team-photo-crop-dialog.tsx');
    assert.match(dialog, /canvas\.toBlob\(resolve, 'image\/jpeg', 0\.92\)/);
    assert.match(
        dialog,
        /Math\.min\(TEAM_PHOTO\.width, Math\.round\(rect\.width\)\)/,
    );
    assert.match(dialog, /touch-none/);
    assert.match(dialog, /setPointerCapture/);
    assert.match(dialog, /aria-label="Zoom"/);
    /** The preview card carries the public card's parts: role tab, shade, name, bio and role. */
    for (const part of [
        "card.code || 'ROLE'",
        "card.name.trim() || 'Name to follow'",
        "card.bio.trim() || 'Details to follow.'",
        "card.title || 'Role'",
    ])
        assert.ok(dialog.includes(part), part);
    assert.equal(teamLib.TEAM_PHOTO_SOURCE_BYTES, 20 * 1024 * 1024);
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
function editorHarness(
    card: TeamCard,
    reply: () => Promise<unknown>,
    place?: Record<string, unknown>,
) {
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
    const exports: Record<
        string,
        (props: { member: TeamCard; place?: unknown }) => unknown
    > = {};
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
                useMemo: (factory: () => unknown) => factory(),
                useEffect: () => {},
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
        return elements(exports.TeamMemberEditor({ member: card, place }));
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

test('a portrait is framed in the card before it is uploaded, removed right away, and a photo over 20 MB is refused', async () => {
    const h = editorHarness(member, async () => ({ saved: true }));
    const input = () => h.find('Choose a photo for the COO card');
    const crop = () =>
        h.render().find((node) => node.type === 'TeamPhotoCropDialog');
    assert.equal(input().props.accept, 'image/jpeg,image/png,image/webp');
    assert.ok(h.button('Change photo'));

    input().props.onChange({
        target: { files: [{ size: 20 * 1024 * 1024 + 1 }], value: 'big.jpg' },
    });
    assert.equal(words(h.message()), 'Choose a photo of at most 20 MB.');
    assert.equal(h.message().props.role, 'alert');
    assert.equal(crop(), undefined);

    /** Cancelling the framing sends nothing. */
    const photo = new File(['portrait'], 'me.jpg', { type: 'image/jpeg' });
    input().props.onChange({ target: { files: [photo], value: 'me.jpg' } });
    assert.equal(crop()!.props.file, photo);
    crop()!.props.onCancel();
    assert.equal(crop(), undefined);
    assert.equal(h.requests.length, 0);

    /** The preview card shows the card's current details, and only the framed portrait is uploaded. */
    h.find('bio').props.onChange({ target: { value: 'Bagong bio.' } });
    input().props.onChange({ target: { files: [photo], value: 'me.jpg' } });
    assert.deepEqual(crop()!.props.card, {
        code: 'COO',
        title: 'Chief Operating Officer',
        name: 'Shamarra Faustino',
        bio: 'Bagong bio.',
    });
    assert.equal(crop()!.props.saveLabel, 'Save photo');
    const framed = new File(['framed'], 'team-photo.jpg', {
        type: 'image/jpeg',
    });
    crop()!.props.onCropped(framed);
    assert.equal(crop(), undefined);
    await settle();
    assert.equal(h.requests[0].route, 'POST team/2/photo');
    assert.equal((h.requests[0].data as FormData).get('image'), framed);
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

test('moving a member earlier or later gives the whole new order, and the ends cannot move further', () => {
    assert.deepEqual(teamLib.movedTeamOrder([1, 2, 3, 4], 3, -1), [1, 3, 2, 4]);
    assert.deepEqual(teamLib.movedTeamOrder([1, 2, 3, 4], 3, 1), [1, 2, 4, 3]);
    assert.equal(teamLib.movedTeamOrder([1, 2, 3, 4], 1, -1), null);
    assert.equal(teamLib.movedTeamOrder([1, 2, 3, 4], 4, 1), null);
    assert.equal(teamLib.movedTeamOrder([1, 2, 3, 4], 9, 1), null);
    assert.equal(teamLib.TEAM_MAX_MEMBERS, 30);
});

/** The whole Team tab, or its add dialog, with hand-driven React state and a pretend server. */
function teamHarness(
    component: 'WebsiteTeamSettings' | 'AddTeamMemberDialog',
    props: Record<string, unknown>,
    reply: () => Promise<unknown> = async () => ({ saved: true }),
) {
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
    const requests: { route: string; data: any }[] = [];
    const reloads: { only: string[]; onFinish: () => void }[] = [];
    const toasts: string[] = [];
    let state = 0;
    let reference = 0;
    const named = new Proxy({}, { get: (_module, key) => key });
    const exports: Record<string, (props: any) => unknown> = {};
    new Function(
        'require',
        'exports',
        `${compiled}; exports.AddTeamMemberDialog = AddTeamMemberDialog;`,
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
                useMemo: (factory: () => unknown) => factory(),
                useEffect: () => {},
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
            return {
                update: (id: number) => `PUT team/${id}`,
                destroy: (id: number) => `DELETE team/${id}`,
                reorder: () => 'PUT team/order',
                store: () => 'POST team',
            };
        return named;
    }, exports);
    const render = () => {
        state = 0;
        reference = 0;
        return elements(exports[component](props));
    };
    return {
        render,
        requests,
        reloads,
        toasts,
        button: (text: string) =>
            render().find(
                (node) => node.type === 'Button' && words(node).includes(text),
            )!,
        cards: () =>
            render().filter((node) => typeof node.props.place === 'object'),
    };
}
const team: TeamCard[] = [
    { ...member, id: 1, code: 'CEO', name: null, img: null },
    member,
    { ...member, id: 3, code: 'CTO', name: 'Samantha Faustino' },
];

test('each member can be moved one place and the whole new order is saved at once', async () => {
    const h = teamHarness('WebsiteTeamSettings', { members: team });
    assert.deepEqual(
        h
            .cards()
            .map((card) => [
                card.props.member.code,
                card.props.place.position,
                card.props.place.total,
            ]),
        [
            ['CEO', 1, 3],
            ['COO', 2, 3],
            ['CTO', 3, 3],
        ],
    );
    assert.ok(
        words(h.render()).includes('3 of up to 30 members.'),
        'the count of members is shown',
    );

    h.cards()[1].props.place.onMove(-1);
    /** While the order is being saved every card's controls are locked, so two moves cannot cross. */
    assert.equal(h.cards()[0].props.place.locked, true);
    h.cards()[2].props.place.onMove(-1);
    await settle();
    assert.deepEqual(h.requests, [
        { route: 'PUT team/order', data: { ids: [2, 1, 3] } },
    ]);
    assert.deepEqual(h.toasts, ['Team order saved']);
    assert.deepEqual(
        h.reloads.map(({ only }) => only),
        [['team']],
    );
    h.reloads[0].onFinish();
    assert.equal(h.cards()[0].props.place.locked, false);

    /** The first member cannot move earlier: nothing is sent. */
    h.cards()[0].props.place.onMove(-1);
    await settle();
    assert.equal(h.requests.length, 1);
});

test('a card shows its place and offers Earlier, Later and Delete with labels that name the card', () => {
    const controls = (place: Record<string, unknown>) => {
        const h = editorHarness(member, async () => ({}), place);
        return { nodes: h.render(), labelled: h.find };
    };
    const moves: number[] = [];
    let removed = 0;
    const first = controls({
        position: 1,
        total: 3,
        locked: false,
        onMove: (direction: number) => moves.push(direction),
        onRemove: () => removed++,
    });
    assert.ok(words(first.nodes).includes('Place 1 of 3'));
    assert.equal(
        first.labelled('Move the COO card earlier').props.disabled,
        true,
    );
    assert.equal(
        first.labelled('Move the COO card later').props.disabled,
        false,
    );
    first.labelled('Move the COO card later').props.onClick();
    first.labelled('Delete the COO card').props.onClick();
    assert.deepEqual(moves, [1]);
    assert.equal(removed, 1);

    const last = controls({
        position: 3,
        total: 3,
        locked: false,
        onMove() {},
        onRemove() {},
    });
    assert.equal(last.labelled('Move the COO card later').props.disabled, true);
    const locked = controls({
        position: 2,
        total: 3,
        locked: true,
        onMove() {},
        onRemove() {},
    });
    assert.equal(locked.labelled('Delete the COO card').props.disabled, true);
});

test('deleting a member asks for confirmation and only then deletes', async () => {
    const h = teamHarness('WebsiteTeamSettings', { members: team });
    const dialog = () => h.render().find((node) => node.type === 'Dialog')!;
    assert.equal(dialog().props.open, false);

    h.cards()[2].props.place.onRemove();
    assert.equal(dialog().props.open, true);
    assert.equal(h.requests.length, 0);
    const text = words(dialog());
    assert.ok(text.includes('Delete this team member?'));
    assert.ok(text.includes('Samantha Faustino'));
    assert.ok(text.includes('This cannot be undone.'));

    /** Closing the dialog cancels: nothing is deleted. */
    dialog().props.onOpenChange(false);
    assert.equal(dialog().props.open, false);
    assert.equal(h.requests.length, 0);

    h.cards()[2].props.place.onRemove();
    const confirm = elements(dialog()).find((node) => node.type === 'Button')!;
    assert.equal(confirm.props.variant, 'destructive');
    confirm.props.onClick();
    await settle();
    assert.deepEqual(h.requests, [{ route: 'DELETE team/3', data: undefined }]);
    assert.deepEqual(h.toasts, ['Team member deleted']);
    assert.equal(dialog().props.open, false);
});

test('Add team member opens a form, and a full team cannot add another', () => {
    const h = teamHarness('WebsiteTeamSettings', { members: team });
    assert.equal(h.button('Add team').props.disabled, false);
    assert.equal(
        h.render().some((node) => node.props.onClose !== undefined),
        false,
    );
    h.button('Add team').props.onClick();
    assert.equal(
        h.render().filter((node) => node.props.onClose !== undefined).length,
        1,
    );

    const full = teamHarness('WebsiteTeamSettings', {
        members: Array.from({ length: 30 }, (_, index) => ({
            ...member,
            id: index + 1,
        })),
    });
    assert.equal(full.button('Add team').props.disabled, true);
});

test('a new member is sent with their details and optional photo in one request', async () => {
    let closed = 0;
    const h = teamHarness('AddTeamMemberDialog', { onClose: () => closed++ });
    const field = (id: string) =>
        h.render().find((node) => node.props.id === `team-new-${id}`)!;
    assert.equal(h.button('Add team member').props.disabled, true);
    assert.ok(words(h.render()).includes('Required: a role label and a role.'));

    field('label').props.onChange({ target: { value: ' CREW ' } });
    field('title').props.onChange({ target: { value: 'Kitchen Crew' } });
    field('name').props.onChange({ target: { value: 'Ana Reyes' } });
    const picker = h
        .render()
        .find(
            (node) =>
                node.props['aria-label'] ===
                'Choose a photo for the new team member',
        )!;
    picker.props.onChange({
        target: { files: [{ size: 20 * 1024 * 1024 + 1 }], value: 'big.jpg' },
    });
    assert.ok(words(h.render()).includes('Choose a photo of at most 20 MB.'));
    /** A chosen photo is framed in the card first; only the framed portrait is kept for the request. */
    const chosen = new File(['portrait'], 'ana.jpg', { type: 'image/jpeg' });
    picker.props.onChange({ target: { files: [chosen], value: 'ana.jpg' } });
    const crop = () =>
        h.render().find((node) => node.type === 'TeamPhotoCropDialog');
    assert.equal(crop()!.props.file, chosen);
    assert.equal(crop()!.props.saveLabel, 'Use photo');
    assert.deepEqual(crop()!.props.card, {
        code: ' CREW ',
        title: 'Kitchen Crew',
        name: 'Ana Reyes',
        bio: '',
    });
    const photo = new File(['framed'], 'team-photo.jpg', {
        type: 'image/jpeg',
    });
    crop()!.props.onCropped(photo);
    assert.equal(crop(), undefined);
    assert.ok(words(h.render()).includes('Framed and ready.'));
    assert.ok(
        h
            .render()
            .some(
                (node) =>
                    node.type === 'img' &&
                    node.props.alt === 'New member photo as framed',
            ),
    );
    h.render()
        .find((node) => node.type === 'SettingSwitch')!
        .props.onChange(false);

    const form = () => h.render().find((node) => node.type === 'form')!;
    form().props.onSubmit({ preventDefault() {} });
    form().props.onSubmit({ preventDefault() {} });
    await settle();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].route, 'POST team');
    const sent = h.requests[0].data as FormData;
    assert.deepEqual(
        [
            sent.get('role_label'),
            sent.get('role_title'),
            sent.get('name'),
            sent.get('bio'),
            sent.get('is_visible'),
        ],
        ['CREW', 'Kitchen Crew', 'Ana Reyes', '', '0'],
    );
    assert.equal(sent.get('image'), photo);
    assert.deepEqual(h.toasts, ['Team member added']);
    assert.deepEqual(
        h.reloads.map(({ only }) => only),
        [['team']],
    );
    /** The dialog closes once the refreshed team has arrived. */
    assert.equal(closed, 0);
    h.reloads[0].onFinish();
    assert.equal(closed, 1);
});

test('a new member the server refuses keeps the form open with the reason', async () => {
    let closed = 0;
    const h = teamHarness(
        'AddTeamMemberDialog',
        { onClose: () => closed++ },
        async () => {
            throw new Error(
                'The team already has 30 members. Delete one before adding another.',
            );
        },
    );
    const field = (id: string) =>
        h.render().find((node) => node.props.id === `team-new-${id}`)!;
    field('label').props.onChange({ target: { value: 'CREW' } });
    field('title').props.onChange({ target: { value: 'Kitchen Crew' } });
    h.render()
        .find((node) => node.type === 'form')!
        .props.onSubmit({ preventDefault() {} });
    await settle();
    const message = h
        .render()
        .find((node) => node.type === 'p' && node.props.role === 'alert')!;
    assert.equal(
        words(message),
        'The team already has 30 members. Delete one before adding another.',
    );
    assert.deepEqual(h.toasts, []);
    assert.equal(closed, 0);
    assert.equal(field('label').props.value, 'CREW');
    assert.equal(h.button('Add team member').props.disabled, false);
});
