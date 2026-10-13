import { router } from '@inertiajs/react';
import { FileUp, Pencil, Plus, Send, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    controlClass,
    primaryActionClass,
} from '@/components/catalog-ui';
import { OwnerStatusBadge, ownerPanelClass } from '@/components/owner-ui';
import { SettingSwitch } from '@/components/receipt-settings';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { shippyReply } from '@/lib/landing-shippy';
import type { ShippyReply } from '@/lib/landing-shippy';
import { qrError, qrRequest } from '@/lib/qr-http';
import {
    NEW_SHIPPY_FAQ,
    shippyDocumentProblem,
    shippyDocumentSize,
    shippyFaqForm,
    shippyFaqPayload,
    shippyFaqProblem,
    shippySource,
} from '@/lib/website-shippy';
import type {
    ShippyDocumentRow,
    ShippyFaqForm,
    ShippyFaqRow,
    ShippyLimits,
    WebsiteShippy,
} from '@/lib/website-shippy';
import { test } from '@/routes/workspaces/website/shippy';
import {
    destroy as destroyDocument,
    replace as replaceDocument,
    store as storeDocuments,
    update as updateDocument,
} from '@/routes/workspaces/website/shippy/documents';
import {
    destroy as destroyFaq,
    store as storeFaq,
    update as updateFaq,
} from '@/routes/workspaces/website/shippy/faqs';

const headingClass = 'text-sm font-bold tracking-[0.08em] uppercase';
const updated = (value: string | null): string =>
    value === null
        ? 'Not yet'
        : new Date(value).toLocaleString('en-PH', {
              timeZone: 'Asia/Manila',
              dateStyle: 'medium',
              timeStyle: 'short',
          });

/**
 * Saves one change to Shippy's knowledge and reads the Shippy figures again. The server drops Shippy's prepared
 * knowledge with every saved change, so the next question, here or on the landing page, already uses it.
 */
function useKnowledgeChange() {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const run = async (
        request: () => Promise<unknown>,
        message: string,
        done?: () => void,
    ) => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            await request();
            toast.success(message);
            router.reload({
                only: ['shippy'],
                onFinish: () => {
                    setBusy(false);
                    done?.();
                },
            });
        } catch (reason) {
            setError(qrError(reason).message);
            setBusy(false);
        }
    };
    return { busy, error, setError, run };
}

/**
 * Website → Shippy → Test Shippy: one question sent through the same answer engine a guest's question uses, shown
 * with where the answer came from and how long the server took. Nothing typed here is saved.
 */
export function ShippyTest() {
    const [question, setQuestion] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [result, setResult] = useState<{
        asked: string;
        reply: ShippyReply;
        source: string;
        milliseconds: number;
    } | null>(null);
    const ask = async () => {
        const asked = question.trim();
        if (busy || !asked) return;
        setBusy(true);
        setError('');
        try {
            const answer = await qrRequest<{
                reply?: { source?: unknown };
                milliseconds?: unknown;
            }>(test(), { message: asked });
            const reply = shippyReply(answer.reply);
            if (!reply) throw new Error('unreadable');
            setResult({
                asked,
                reply,
                source:
                    typeof answer.reply?.source === 'string'
                        ? answer.reply.source
                        : '',
                milliseconds:
                    typeof answer.milliseconds === 'number'
                        ? answer.milliseconds
                        : 0,
            });
        } catch (reason) {
            setResult(null);
            setError(
                reason instanceof Error && reason.message === 'unreadable'
                    ? 'Shippy returned an answer that could not be read.'
                    : qrError(reason).message,
            );
        }
        setBusy(false);
    };
    const source = result ? shippySource(result.source) : null;

    return (
        <div className={`${ownerPanelClass} flex flex-col gap-3 p-4 sm:p-5`}>
            <h3 className={headingClass}>Test Shippy</h3>
            <p className="text-muted-foreground text-sm">
                Ask what a guest would ask. This is the real answer, with where
                it came from and how long the server took. It works while the
                chat is switched off.
            </p>
            <form
                aria-label="Test Shippy"
                className="flex flex-wrap gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    void ask();
                }}
            >
                <Input
                    aria-label="Question for Shippy"
                    value={question}
                    maxLength={200}
                    placeholder="May delivery ba kayo?"
                    onChange={(event) => setQuestion(event.target.value)}
                    className={`${controlClass} min-h-11 min-w-[200px] flex-1`}
                />
                <Button
                    type="submit"
                    className={`${primaryActionClass} min-h-11`}
                    disabled={busy || !question.trim()}
                >
                    {busy ? (
                        <Spinner />
                    ) : (
                        <Send className="size-3.5" aria-hidden="true" />
                    )}{' '}
                    Ask Shippy
                </Button>
            </form>
            {error && (
                <p role="alert" className="text-xs font-semibold text-red-700">
                    {error}
                </p>
            )}
            {result && source && (
                <div
                    aria-live="polite"
                    className="flex flex-col gap-2 rounded-[12px] border border-neutral-200 bg-neutral-50 p-3"
                >
                    <div className="flex flex-wrap items-center gap-2">
                        <OwnerStatusBadge tone={source.tone}>
                            {source.label}
                        </OwnerStatusBadge>
                        <OwnerStatusBadge tone="outline">
                            {source.ai ? 'AI answer' : 'Local answer'}
                        </OwnerStatusBadge>
                        <span className="text-xs font-semibold text-neutral-700 tabular-nums">
                            {result.milliseconds.toLocaleString('en-PH')} ms
                        </span>
                    </div>
                    <p className="text-xs text-neutral-600">
                        “{result.asked}” · {source.detail}
                    </p>
                    <p className="text-sm leading-6 break-words whitespace-pre-wrap">
                        {result.reply.text}
                    </p>
                    {result.reply.items.length > 0 && (
                        <ul className="flex flex-col gap-1 text-[12.5px]">
                            {result.reply.items.map((item) => (
                                <li
                                    key={item.name}
                                    className="flex justify-between gap-3 border-b border-neutral-200 pb-1 last:border-0"
                                >
                                    <span>{item.name}</span>
                                    <span className="font-semibold">
                                        {item.price}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                    {result.reply.actions.length > 0 && (
                        <p className="text-xs text-neutral-600">
                            Buttons shown to the guest:{' '}
                            {result.reply.actions
                                .map((action) => action.label)
                                .join(', ')}
                        </p>
                    )}
                </div>
            )}
            <p className="text-xs text-neutral-600">
                Nothing typed here is saved. A Gemini answer counts against the
                same daily limit as guests&apos; questions; the other sources
                call no AI.
            </p>
        </div>
    );
}

/** Website → Shippy → Knowledge: the questions the Owner answered once, which Shippy then answers without Gemini. */
export function ShippyFaqs({
    faqs,
    limits,
}: {
    faqs: ShippyFaqRow[];
    limits: ShippyLimits;
}) {
    const [editing, setEditing] = useState<ShippyFaqRow | 'new' | null>(null);
    const [removing, setRemoving] = useState<ShippyFaqRow | null>(null);
    const { busy, error, run } = useKnowledgeChange();

    return (
        <div className={`${ownerPanelClass} flex flex-col gap-3 p-4 sm:p-5`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-[220px] flex-1">
                    <h3 className={headingClass}>Knowledge · FAQs</h3>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Answer a question once and Shippy gives your answer as
                        written, right away and without AI. Add other wordings
                        guests use, in English or Tagalog. Menu prices, hours
                        and the address always come from the published menu.
                    </p>
                    <p
                        role={error ? 'alert' : undefined}
                        className={`mt-1 text-xs ${error ? 'font-semibold text-red-700' : 'text-neutral-600'}`}
                    >
                        {error ||
                            `${faqs.length} of up to ${limits.faqs} FAQs.`}
                    </p>
                </div>
                <Button
                    type="button"
                    className={`${primaryActionClass} min-h-11`}
                    disabled={busy || faqs.length >= limits.faqs}
                    onClick={() => setEditing('new')}
                >
                    <Plus className="size-3.5" aria-hidden="true" /> Add FAQ
                </Button>
            </div>
            {faqs.length === 0 ? (
                <p className="rounded-[12px] border border-dashed border-neutral-300 p-4 text-center text-sm text-neutral-600">
                    No FAQs yet. Start with what guests ask most: delivery,
                    payment, parking, reservations.
                </p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {faqs.map((faq) => (
                        <li
                            key={faq.id}
                            className="flex flex-col gap-2 rounded-[12px] border border-neutral-200 p-3"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <OwnerStatusBadge
                                    tone={faq.enabled ? 'green' : 'neutral'}
                                >
                                    {faq.enabled ? 'Active' : 'Off'}
                                </OwnerStatusBadge>
                                {faq.category && (
                                    <OwnerStatusBadge tone="outline">
                                        {faq.category}
                                    </OwnerStatusBadge>
                                )}
                            </div>
                            <p className="text-sm font-bold break-words">
                                {faq.question}
                            </p>
                            <p className="text-sm leading-6 break-words text-neutral-700">
                                {faq.answer}
                            </p>
                            {faq.alternatives.length > 0 && (
                                <p className="text-xs break-words text-neutral-600">
                                    Also asked as:{' '}
                                    {faq.alternatives.join(' · ')}
                                </p>
                            )}
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className={`${actionClass} min-h-11`}
                                    disabled={busy}
                                    aria-label={`Edit the FAQ “${faq.question}”`}
                                    onClick={() => setEditing(faq)}
                                >
                                    <Pencil
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />{' '}
                                    Edit
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className={`${actionClass} min-h-11`}
                                    disabled={busy}
                                    aria-label={`Turn ${faq.enabled ? 'off' : 'on'} the FAQ “${faq.question}”`}
                                    onClick={() =>
                                        void run(
                                            () =>
                                                qrRequest(updateFaq(faq.id), {
                                                    ...shippyFaqPayload(
                                                        shippyFaqForm(faq),
                                                    ),
                                                    is_enabled: !faq.enabled,
                                                }),
                                            faq.enabled
                                                ? 'FAQ turned off'
                                                : 'FAQ turned on',
                                        )
                                    }
                                >
                                    {faq.enabled ? 'Turn off' : 'Turn on'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className={`${actionClass} min-h-11 text-red-700`}
                                    disabled={busy}
                                    aria-label={`Delete the FAQ “${faq.question}”`}
                                    onClick={() => setRemoving(faq)}
                                >
                                    <Trash2
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />{' '}
                                    Delete
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            {editing !== null && (
                <ShippyFaqDialog
                    faq={editing === 'new' ? null : editing}
                    limits={limits}
                    onClose={() => setEditing(null)}
                />
            )}
            <Dialog
                open={removing !== null}
                onOpenChange={(value) => {
                    if (!value && !busy) setRemoving(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this FAQ?</DialogTitle>
                        <DialogDescription>
                            “{removing?.question}” and its answer are deleted
                            and Shippy stops using it at once. This cannot be
                            undone. To only pause it, turn it off instead.
                        </DialogDescription>
                    </DialogHeader>
                    <Button
                        variant="destructive"
                        disabled={busy}
                        onClick={() => {
                            if (!removing) return;
                            const id = removing.id;
                            void run(
                                () => qrRequest(destroyFaq(id)),
                                'FAQ deleted',
                                () => setRemoving(null),
                            );
                        }}
                    >
                        {busy ? 'Deleting...' : 'Delete FAQ'}
                    </Button>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/** The form of a new or edited FAQ. */
function ShippyFaqDialog({
    faq,
    limits,
    onClose,
}: {
    faq: ShippyFaqRow | null;
    limits: ShippyLimits;
    onClose: () => void;
}) {
    const [form, setForm] = useState<ShippyFaqForm>(
        faq ? shippyFaqForm(faq) : NEW_SHIPPY_FAQ,
    );
    const { busy, error, run } = useKnowledgeChange();
    const problem = shippyFaqProblem(form, limits);
    const set = (values: Partial<ShippyFaqForm>) =>
        setForm((current) => ({ ...current, ...values }));

    return (
        <Dialog
            open
            onOpenChange={(value) => {
                if (!value && !busy) onClose();
            }}
        >
            <DialogContent className="max-h-[90svh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{faq ? 'Edit FAQ' : 'Add FAQ'}</DialogTitle>
                    <DialogDescription>
                        Guests read the answer exactly as you write it here.
                    </DialogDescription>
                </DialogHeader>
                <form
                    aria-label={faq ? 'Edit FAQ' : 'New FAQ'}
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (problem) return;
                        const payload = shippyFaqPayload(form);
                        void run(
                            () =>
                                qrRequest(
                                    faq ? updateFaq(faq.id) : storeFaq(),
                                    payload,
                                ),
                            faq ? 'FAQ saved' : 'FAQ added',
                            onClose,
                        );
                    }}
                >
                    <div className="space-y-1.5">
                        <Label htmlFor="shippy-faq-question">Question</Label>
                        <Input
                            id="shippy-faq-question"
                            value={form.question}
                            maxLength={limits.question}
                            required
                            placeholder="May delivery ba kayo?"
                            aria-invalid={!form.question.trim()}
                            onChange={(event) =>
                                set({ question: event.target.value })
                            }
                            className={controlClass}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="shippy-faq-answer">
                            Approved answer{' '}
                            <span className="font-normal text-neutral-500">
                                {Array.from(form.answer).length}/{limits.answer}
                            </span>
                        </Label>
                        <textarea
                            id="shippy-faq-answer"
                            value={form.answer}
                            maxLength={limits.answer}
                            rows={4}
                            required
                            aria-invalid={!form.answer.trim()}
                            onChange={(event) =>
                                set({ answer: event.target.value })
                            }
                            className={`${controlClass} h-auto resize-none py-2 leading-5`}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="shippy-faq-alternatives">
                            Other wordings{' '}
                            <span className="font-normal text-neutral-500">
                                optional, one per line, up to{' '}
                                {limits.alternatives}
                            </span>
                        </Label>
                        <textarea
                            id="shippy-faq-alternatives"
                            value={form.alternatives}
                            rows={3}
                            placeholder={
                                'Do you deliver?\nNagdedeliver ba kayo?'
                            }
                            onChange={(event) =>
                                set({ alternatives: event.target.value })
                            }
                            className={`${controlClass} h-auto resize-none py-2 leading-5`}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="shippy-faq-category">
                            Category{' '}
                            <span className="font-normal text-neutral-500">
                                optional
                            </span>
                        </Label>
                        <Input
                            id="shippy-faq-category"
                            value={form.category}
                            maxLength={limits.category}
                            placeholder="Orders"
                            onChange={(event) =>
                                set({ category: event.target.value })
                            }
                            className={controlClass}
                        />
                    </div>
                    <SettingSwitch
                        checked={form.is_enabled}
                        disabled={busy}
                        onChange={(is_enabled) => set({ is_enabled })}
                        label="Shippy uses this FAQ"
                        description="Off keeps the FAQ here without Shippy answering from it."
                    />
                    <p
                        role={error ? 'alert' : undefined}
                        className={`text-xs ${error || problem ? 'font-semibold text-red-700' : 'text-neutral-600'}`}
                    >
                        {error || problem || 'Ready to save.'}
                    </p>
                    <Button
                        type="submit"
                        className={`${primaryActionClass} min-h-11`}
                        disabled={busy || problem !== ''}
                    >
                        {busy && <Spinner />} {faq ? 'Save FAQ' : 'Add FAQ'}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * Website → Shippy → Documents: Markdown notes Shippy reads as reference text. Only the name, size and state come
 * back from the server; a document's text is never sent to this page or served anywhere.
 */
export function ShippyDocuments({
    documents,
    limits,
}: {
    documents: ShippyDocumentRow[];
    limits: ShippyLimits;
}) {
    const upload = useRef<HTMLInputElement>(null);
    const swap = useRef<HTMLInputElement>(null);
    const [replacing, setReplacing] = useState<ShippyDocumentRow | null>(null);
    const [removing, setRemoving] = useState<ShippyDocumentRow | null>(null);
    const { busy, error, setError, run } = useKnowledgeChange();
    const full = documents.length >= limits.documents;
    const usable = (files: File[]): boolean => {
        const problem = files
            .map((file) => shippyDocumentProblem(file, limits))
            .find((reason) => reason !== '');
        setError(problem ?? '');
        return files.length > 0 && problem === undefined;
    };

    return (
        <div className={`${ownerPanelClass} flex flex-col gap-3 p-4 sm:p-5`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-[220px] flex-1">
                    <h3 className={headingClass}>Documents</h3>
                    <p className="text-muted-foreground mt-1 text-sm">
                        Upload Markdown (.md) notes: house rules, policies,
                        how-tos. A question that matches a heading gets that
                        section right away; otherwise only the matching sections
                        are given to Gemini as reference. A line that prices a
                        menu item is ignored: the published menu is the only
                        source of prices.
                    </p>
                    <p
                        role={error ? 'alert' : undefined}
                        className={`mt-1 text-xs ${error ? 'font-semibold text-red-700' : 'text-neutral-600'}`}
                    >
                        {error ||
                            `${documents.length} of up to ${limits.documents} documents, ${limits.document_kilobytes} KB each. Documents are private: guests never get the file.`}
                    </p>
                </div>
                <input
                    ref={upload}
                    type="file"
                    multiple
                    accept=".md,.markdown,text/markdown"
                    className="hidden"
                    aria-label="Choose Markdown documents to upload"
                    onChange={(event) => {
                        const files = Array.from(event.target.files ?? []);
                        event.target.value = '';
                        if (!usable(files)) return;
                        if (
                            documents.length + files.length >
                            limits.documents
                        ) {
                            setError(
                                `Shippy keeps at most ${limits.documents} documents.`,
                            );
                            return;
                        }
                        const payload = new FormData();
                        files.forEach((file) =>
                            payload.append('files[]', file),
                        );
                        void run(
                            () => qrRequest(storeDocuments(), payload),
                            files.length === 1
                                ? 'Document uploaded'
                                : `${files.length} documents uploaded`,
                        );
                    }}
                />
                <input
                    ref={swap}
                    type="file"
                    accept=".md,.markdown,text/markdown"
                    className="hidden"
                    aria-label="Choose the Markdown file that replaces a document"
                    onChange={(event) => {
                        const files = Array.from(event.target.files ?? []);
                        event.target.value = '';
                        if (!replacing || !usable(files)) return;
                        const payload = new FormData();
                        payload.set('file', files[0]);
                        void run(
                            () =>
                                qrRequest(
                                    replaceDocument(replacing.id),
                                    payload,
                                ),
                            'Document replaced',
                        );
                    }}
                />
                <Button
                    type="button"
                    className={`${primaryActionClass} min-h-11`}
                    disabled={busy || full}
                    onClick={() => upload.current?.click()}
                >
                    {busy ? (
                        <Spinner />
                    ) : (
                        <FileUp className="size-3.5" aria-hidden="true" />
                    )}{' '}
                    Upload .md files
                </Button>
            </div>
            {documents.length === 0 ? (
                <p className="rounded-[12px] border border-dashed border-neutral-300 p-4 text-center text-sm text-neutral-600">
                    No documents yet.
                </p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {documents.map((document) => (
                        <li
                            key={document.id}
                            className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-[12px] border border-neutral-200 p-3"
                        >
                            <div className="min-w-[180px] flex-1">
                                <p className="text-sm font-bold break-all">
                                    {document.filename}
                                </p>
                                <p className="text-xs text-neutral-600">
                                    Updated {updated(document.updated_at)} ·{' '}
                                    {document.sections}{' '}
                                    {document.sections === 1
                                        ? 'section'
                                        : 'sections'}{' '}
                                    · {shippyDocumentSize(document.bytes)}
                                </p>
                            </div>
                            <OwnerStatusBadge
                                tone={document.enabled ? 'green' : 'neutral'}
                            >
                                {document.enabled ? 'Active' : 'Off'}
                            </OwnerStatusBadge>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className={`${actionClass} min-h-11`}
                                    disabled={busy}
                                    aria-label={`Replace ${document.filename}`}
                                    onClick={() => {
                                        setReplacing(document);
                                        swap.current?.click();
                                    }}
                                >
                                    Replace
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className={`${actionClass} min-h-11`}
                                    disabled={busy}
                                    aria-label={`Turn ${document.enabled ? 'off' : 'on'} ${document.filename}`}
                                    onClick={() =>
                                        void run(
                                            () =>
                                                qrRequest(
                                                    updateDocument(document.id),
                                                    {
                                                        is_enabled:
                                                            !document.enabled,
                                                    },
                                                ),
                                            document.enabled
                                                ? 'Document turned off'
                                                : 'Document turned on',
                                        )
                                    }
                                >
                                    {document.enabled ? 'Turn off' : 'Turn on'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className={`${actionClass} min-h-11 text-red-700`}
                                    disabled={busy}
                                    aria-label={`Delete ${document.filename}`}
                                    onClick={() => setRemoving(document)}
                                >
                                    <Trash2
                                        className="size-3.5"
                                        aria-hidden="true"
                                    />{' '}
                                    Delete
                                </Button>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
            <Dialog
                open={removing !== null}
                onOpenChange={(value) => {
                    if (!value && !busy) setRemoving(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this document?</DialogTitle>
                        <DialogDescription>
                            {removing?.filename} is deleted and Shippy stops
                            using it at once. This cannot be undone. To only
                            pause it, turn it off instead.
                        </DialogDescription>
                    </DialogHeader>
                    <Button
                        variant="destructive"
                        disabled={busy}
                        onClick={() => {
                            if (!removing) return;
                            const id = removing.id;
                            void run(
                                () => qrRequest(destroyDocument(id)),
                                'Document deleted',
                                () => setRemoving(null),
                            );
                        }}
                    >
                        {busy ? 'Deleting...' : 'Delete document'}
                    </Button>
                </DialogContent>
            </Dialog>
        </div>
    );
}

/** Everything the Owner teaches Shippy and the place to try it, shown under the chat switch. */
export function WebsiteShippyKnowledge({ shippy }: { shippy: WebsiteShippy }) {
    return (
        <>
            <ShippyTest />
            <ShippyFaqs faqs={shippy.faqs} limits={shippy.limits} />
            <ShippyDocuments
                documents={shippy.documents}
                limits={shippy.limits}
            />
        </>
    );
}
