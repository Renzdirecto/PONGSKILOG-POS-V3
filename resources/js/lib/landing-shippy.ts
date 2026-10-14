/** Mirrors `ShippyAssistant`: the longest question, the earlier turns sent along and the longest turn. */
export const SHIPPY_MAX_MESSAGE = 200;
export const SHIPPY_MAX_HISTORY = 4;
export const SHIPPY_MAX_TURN = 420;
/** The conversation lives in memory only: it is gone on a reload and never written to the browser's storage. */
export const SHIPPY_KEPT_MESSAGES = 40;

/** A product card or an hours row: the name and price come from the server, the photo from the landing page's own images. */
export type ShippyItem = { name: string; price: string; img: string | null };
/** A button the server approved: an anchor on this page or one of the business's own links. */
export type ShippyAction = {
    key: string;
    label: string;
    href: string;
    external: boolean;
};
export type ShippyReply = {
    text: string;
    items: ShippyItem[];
    actions: ShippyAction[];
};
export type ShippyMessage =
    | { from: 'user'; text: string }
    | ({ from: 'bot' } & ShippyReply);

export const SHIPPY_GREETING =
    "Hi! Ako si Shippy. Ano'ng gusto mong malaman tungkol sa PONGSKILOG?";

const text = (value: unknown): string =>
    typeof value === 'string' ? value.trim() : '';
const list = (value: unknown): Record<string, unknown>[] =>
    Array.isArray(value)
        ? value.filter(
              (entry): entry is Record<string, unknown> =>
                  typeof entry === 'object' && entry !== null,
          )
        : [];

/**
 * A server reply reduced to what the chat may render. The server already checks everything; this is the second lock:
 * text stays text, a photo is one of this site's own images and a button opens an anchor here or an https address.
 */
export function shippyReply(value: unknown): ShippyReply | null {
    const reply = list([value])[0];
    const words = text(reply?.text);
    if (!reply || !words) return null;
    return {
        text: words,
        items: list(reply.items)
            .map((item) => ({
                name: text(item.name),
                price: text(item.price),
                img:
                    typeof item.img === 'string' &&
                    /^\/images\/[\w.-]+$/.test(item.img)
                        ? item.img
                        : null,
            }))
            .filter((item) => item.name !== ''),
        actions: list(reply.actions)
            .map((action) => ({
                key: text(action.key),
                label: text(action.label),
                href: text(action.href),
                external: action.external === true,
            }))
            .filter(
                (action) =>
                    action.label !== '' &&
                    (/^#[\w-]+$/.test(action.href) ||
                        action.href.startsWith('https://')),
            ),
    };
}

/** The language a guest asked Shippy for ("Talk in English", "Taglish ulit"), as the server sends it back. */
export type ShippyPreference = 'english' | 'taglish' | null;

export function shippyPreference(value: unknown): ShippyPreference {
    return value === 'english' || value === 'taglish' ? value : null;
}

/**
 * What Shippy says when the answer never arrived: a pause when asked too fast, otherwise the way to a person
 * (`facebook` is the page's own Facebook link), in English when the guest asked for English.
 */
export function shippyUnavailable(
    status: number,
    facebook: string,
    preference: ShippyPreference = null,
): ShippyReply {
    const english = preference === 'english';
    if (status === 429)
        return {
            text: english
                ? 'Hold on, that was fast. Try again in a minute.'
                : 'Teka lang, ang bilis ng questions. Try ulit after one minute.',
            items: [],
            actions: [],
        };
    return {
        text: english
            ? "I can't answer right now. Message PONGSKILOG on Facebook to be sure."
            : 'Hindi ako makasagot ngayon. Message mo ang PONGSKILOG sa Facebook para sure.',
        items: [],
        actions: [
            {
                key: 'messenger',
                label: 'Message on Facebook',
                href: facebook,
                external: true,
            },
        ],
    };
}

/** The few earlier turns sent with a new question, oldest first and clipped, so a follow-up keeps its context. */
export function shippyHistory(
    messages: ShippyMessage[],
): { role: 'user' | 'bot'; text: string }[] {
    return messages.slice(-SHIPPY_MAX_HISTORY).map((message) => ({
        role: message.from,
        text: message.text.slice(0, SHIPPY_MAX_TURN),
    }));
}

/** A question as it is sent: trimmed and no longer than the server accepts. */
export function shippyQuestion(raw: string): string {
    return raw.trim().slice(0, SHIPPY_MAX_MESSAGE);
}

/** The conversation with a new message added, keeping only the most recent ones. */
export function shippyAppend(
    messages: ShippyMessage[],
    message: ShippyMessage,
): ShippyMessage[] {
    return [...messages, message].slice(-SHIPPY_KEPT_MESSAGES);
}
