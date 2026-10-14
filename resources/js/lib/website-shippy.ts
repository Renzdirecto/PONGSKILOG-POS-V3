/** One period of Shippy's usage, from `ShippyUsage::summary()`. */
export type ShippyUsageTotals = {
    /** Questions guests asked, however they were answered. */
    messages: number;
    /** Requests really sent to Gemini. */
    requests: number;
    /** Gemini requests that failed or whose answer could not be used. */
    errors: number;
    input_tokens: number;
    output_tokens: number;
    /** Tokens at the model's list price: an estimate, never a bill. */
    estimated_usd: number;
};

/** What Website → Shippy shows. The API key is never part of it: only whether one is configured. */
export type WebsiteShippy = {
    enabled: boolean;
    configured: boolean;
    model: string;
    usage: {
        today: ShippyUsageTotals;
        month: ShippyUsageTotals;
        days: number;
        daily_limit: number;
    };
    faqs: ShippyFaqRow[];
    documents: ShippyDocumentRow[];
    limits: ShippyLimits;
};

/** An estimated cost in US dollars; a real but tiny amount is never rounded down to zero. */
export function shippyCostLabel(usd: number): string {
    if (usd <= 0) return '$0.00';
    return usd < 0.01 ? 'under $0.01' : `$${usd.toFixed(2)}`;
}

/** An FAQ the Owner answered for Shippy, from `ShippyFaq::row()`. */
export type ShippyFaqRow = {
    id: number;
    question: string;
    answer: string;
    category: string | null;
    alternatives: string[];
    enabled: boolean;
};

/** An uploaded Markdown document, from `ShippyDocument::row()`: its name, size and state, never its text. */
export type ShippyDocumentRow = {
    id: number;
    filename: string;
    bytes: number;
    sections: number;
    enabled: boolean;
    updated_at: string | null;
};

/** The server's limits for Shippy's knowledge, so the forms stop where the server would. */
export type ShippyLimits = {
    faqs: number;
    alternatives: number;
    category: number;
    question: number;
    answer: number;
    documents: number;
    document_kilobytes: number;
};

export type ShippyFaqForm = {
    question: string;
    answer: string;
    category: string;
    /** The other wordings, one per line. */
    alternatives: string;
    is_enabled: boolean;
};

export const NEW_SHIPPY_FAQ: ShippyFaqForm = {
    question: '',
    answer: '',
    category: '',
    alternatives: '',
    is_enabled: true,
};

export function shippyFaqForm(faq: ShippyFaqRow): ShippyFaqForm {
    return {
        question: faq.question,
        answer: faq.answer,
        category: faq.category ?? '',
        alternatives: faq.alternatives.join('\n'),
        is_enabled: faq.enabled,
    };
}

/** The other wordings typed one per line, as the list the server stores. */
export function shippyAlternatives(lines: string): string[] {
    return [
        ...new Set(
            lines
                .split('\n')
                .map((line) => line.trim())
                .filter((line) => line !== ''),
        ),
    ];
}

/** An FAQ form as the request body. */
export function shippyFaqPayload(form: ShippyFaqForm) {
    return {
        question: form.question.trim(),
        answer: form.answer.trim(),
        category: form.category.trim(),
        alternatives: shippyAlternatives(form.alternatives),
        is_enabled: form.is_enabled,
    };
}

/** Why an FAQ form cannot be saved yet, or an empty text when it can. */
export function shippyFaqProblem(
    form: ShippyFaqForm,
    limits: ShippyLimits,
): string {
    if (!form.question.trim() || !form.answer.trim())
        return 'Required: a question and its answer.';
    const wordings = shippyAlternatives(form.alternatives);
    if (wordings.length > limits.alternatives)
        return `At most ${limits.alternatives} other wordings.`;
    if (wordings.some((wording) => wording.length > limits.question))
        return `Each other wording may be at most ${limits.question} characters.`;
    return '';
}

/** Why a chosen file cannot be uploaded as a document, or an empty text when it can. The server checks again. */
export function shippyDocumentProblem(
    file: { name: string; size: number },
    limits: ShippyLimits,
): string {
    if (!/\.(md|markdown)$/i.test(file.name))
        return `${file.name} is not a Markdown (.md) file.`;
    if (file.size > limits.document_kilobytes * 1024)
        return `${file.name} is larger than ${limits.document_kilobytes} KB.`;
    return file.size === 0 ? `${file.name} is empty.` : '';
}

/** A file's name as the server stores it (`ShippyKnowledgeController::read()`), so it can be found in the list. */
export function shippyStoredName(name: string): string {
    const base = name.replace(/\\/g, '/').split('/').pop() ?? '';
    return Array.from(base.replace(/[^\p{L}\p{N} ._()-]+/gu, ' ').trim())
        .slice(-120)
        .join('');
}

/**
 * Whether every chosen file is in the documents the server lists, by its stored name and exact size: how an upload
 * whose answer never arrived is confirmed, instead of guessing that it failed.
 */
export function shippyUploadSaved(
    documents: ShippyDocumentRow[],
    files: { name: string; size: number }[],
): boolean {
    return files.every((file) =>
        documents.some(
            (document) =>
                document.filename.toLowerCase() ===
                    shippyStoredName(file.name).toLowerCase() &&
                document.bytes === file.size,
        ),
    );
}

export function shippyDocumentSize(bytes: number): string {
    return bytes < 1024 ? `${bytes} B` : `${(bytes / 1024).toFixed(1)} KB`;
}

/** Where an answer came from (`ShippyAssistant::reply()`), as Test Shippy names it. Only Gemini is an AI answer. */
export type ShippySource = {
    label: string;
    ai: boolean;
    tone: 'green' | 'blue' | 'amber';
    detail: string;
};

const SHIPPY_SOURCES: Record<string, ShippySource> = {
    menu: {
        label: 'Menu / Business',
        ai: false,
        tone: 'green',
        detail: 'From the published menu, hours and location.',
    },
    faq: {
        label: 'Instant FAQ',
        ai: false,
        tone: 'green',
        detail: 'Your approved answer, exactly as written.',
    },
    markdown: {
        label: 'Markdown',
        ai: false,
        tone: 'green',
        detail: 'From a section of an uploaded document.',
    },
    ai: {
        label: 'Gemini',
        ai: true,
        tone: 'blue',
        detail: 'Written by Gemini from the facts and your matching notes, or your approved Tagalog answer put into English.',
    },
    fallback: {
        label: 'Fallback',
        ai: false,
        tone: 'amber',
        detail: "Shippy's own rules: a question back when the question is unfinished, a language switch, no info about a person, or the safe answer when nothing local was sure and Gemini could not answer.",
    },
};

/** The language a reply was written in, and whether the guest asked for it, as Test Shippy says it. */
export function shippyLanguageLabel(
    language: unknown,
    preference: unknown,
): string {
    const name = language === 'english' ? 'English' : 'Taglish';
    return preference === language
        ? `${name} reply (asked for ${name})`
        : `${name} reply`;
}

export function shippySource(source: unknown): ShippySource {
    return (
        (typeof source === 'string' ? SHIPPY_SOURCES[source] : undefined) ?? {
            label: 'Unknown',
            ai: false,
            tone: 'amber',
            detail: 'The server did not say where this answer came from.',
        }
    );
}
