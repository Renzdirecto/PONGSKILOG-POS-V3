/**
 * Web Push message content, shared by the service worker and the app. A push carries only a type, a stable tag, a
 * same-app page and (for Branch signals) the Branch name; every visible text is fixed here, so a lock screen never
 * shows customer, item, money, Staff or audit details, and a crafted payload cannot choose what is displayed.
 */
export type PushMessageType = 'kitchen.new_order' | 'order.ready' | 'admin.alert';

/** The only pages a notification may open (same origin, exact paths). The server authorizes them on arrival. */
export const PUSH_TARGETS: Readonly<Record<PushMessageType, string>> = {
    'kitchen.new_order': '/workspaces/kitchen',
    'order.ready': '/workspaces/cashier',
    'admin.alert': '/workspaces/super-admin/notifications',
};

/** Anything unknown opens the workspace, which routes the account to its own landing page. */
export const DEFAULT_PUSH_TARGET = '/workspace';

export type ParsedPush = {
    type: PushMessageType | null;
    tag: string;
    branch: string | null;
    url: string | null;
};

export type NotificationContent = {
    title: string;
    body: string;
    tag: string;
    url: string;
};

const TEXTS: Readonly<Record<PushMessageType, { title: string; body: string }>> =
    {
        'kitchen.new_order': {
            title: 'PONGSKILOG · New Kitchen Order',
            body: 'A new order is waiting in Kitchen.',
        },
        'order.ready': {
            title: 'PONGSKILOG · Order Ready',
            body: 'An order is ready to serve.',
        },
        'admin.alert': {
            title: 'PONGSKILOG · Important Alert',
            body: 'Open PONGSKILOG to review the alert.',
        },
    };

function isPushMessageType(value: unknown): value is PushMessageType {
    return typeof value === 'string' && Object.hasOwn(PUSH_TARGETS, value);
}

/** Printable text only, collapsed and bounded; used for the Branch name and the tag. */
function cleanText(value: unknown, maxLength: number): string | null {
    if (typeof value !== 'string') {
        return null;
    }
    const printable = Array.from(value, (character) => {
        const code = character.charCodeAt(0);

        return code < 32 || code === 127 ? ' ' : character;
    }).join('');
    const text = printable.replace(/\s+/g, ' ').trim();

    return text === '' ? null : text.slice(0, maxLength);
}

/** Reads a push payload defensively: malformed or unexpected data still yields a safe, generic notification. */
export function parsePushPayload(text: string | null | undefined): ParsedPush {
    let data: Record<string, unknown> = {};
    try {
        const parsed: unknown = text ? JSON.parse(text) : null;
        if (typeof parsed === 'object' && parsed !== null) {
            data = parsed as Record<string, unknown>;
        }
    } catch {
        data = {};
    }

    return {
        type: isPushMessageType(data.type) ? data.type : null,
        tag: cleanText(data.tag, 120) ?? 'pongskilog',
        branch: cleanText(data.branch, 40),
        url: typeof data.url === 'string' ? data.url : null,
    };
}

export function notificationContent(push: ParsedPush): NotificationContent {
    if (push.type === null) {
        return {
            title: 'PONGSKILOG',
            body: 'Open PONGSKILOG for the latest update.',
            tag: push.tag,
            url: DEFAULT_PUSH_TARGET,
        };
    }
    const text = TEXTS[push.type];

    return {
        title: text.title,
        body: push.branch ? `${text.body} · ${push.branch}` : text.body,
        tag: push.tag,
        url: PUSH_TARGETS[push.type],
    };
}

/**
 * The page a notification tap may open: a same-origin path from the allowlist (query and fragment dropped), or the
 * workspace. External or unknown URLs are never opened.
 */
export function notificationTarget(url: unknown, origin: string): string {
    if (typeof url !== 'string' || url === '') {
        return DEFAULT_PUSH_TARGET;
    }
    try {
        const target = new URL(url, origin);
        const allowed = Object.values(PUSH_TARGETS);

        return target.origin === origin && allowed.includes(target.pathname)
            ? target.pathname
            : DEFAULT_PUSH_TARGET;
    } catch {
        return DEFAULT_PUSH_TARGET;
    }
}

/** A message the service worker posts to open windows when a notification is tapped. */
export const OPEN_TARGET_MESSAGE = 'pongskilog:open-target';
