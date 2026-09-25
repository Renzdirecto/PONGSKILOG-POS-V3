import { router, useHttp } from '@inertiajs/react';
import { Copy } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    OperationsDialog,
    opsButtonClass,
    opsInputClass,
    opsLabelClass,
    opsPrimaryClass,
} from '@/components/operations-ui';
import {
    NEVER_COPIED,
    nothingToCopy,
    SETUP_COPY_SECTIONS,
    setupCopyLines,
    type SetupCopyPreview,
    type SetupCopySection,
} from '@/lib/operations-setup-copy';
import {
    preview as setupCopyPreview,
    store as setupCopyStore,
} from '@/routes/operations/setup-copy';
import { requiredOutline } from '@/lib/required-field';
import type { OperationsContext } from '@/types/operations';

/**
 * Operations › Copy setup from another Branch. Configuration only (Plans, Ingredients, Recipes and add-on effects) for
 * the selected Branch; the server reviews a dry run first and writes exactly that on confirm. Existing setup here is
 * kept unless Replace is chosen. Stock and history are never copied.
 */
export function OperationsSetupCopyButton({
    operations,
    label = 'Copy setup from another Branch',
    primary = false,
}: {
    operations: OperationsContext;
    label?: string;
    primary?: boolean;
}) {
    const [open, setOpen] = useState(false);
    if (
        !operations.branch ||
        !operations.can_configure ||
        operations.copy_sources.length === 0
    ) {
        return null;
    }

    return (
        <>
            <button
                type="button"
                className={primary ? opsPrimaryClass : opsButtonClass}
                onClick={() => setOpen(true)}
            >
                <Copy className="size-4" /> {label}
            </button>
            {open && (
                <OperationsSetupCopyDialog
                    operations={operations}
                    onClose={() => setOpen(false)}
                />
            )}
        </>
    );
}

function OperationsSetupCopyDialog({
    operations,
    onClose,
}: {
    operations: OperationsContext;
    onClose: () => void;
}) {
    const request = useHttp<Record<string, never>, SetupCopyPreview>({});
    const [sourceId, setSourceId] = useState('');
    const [sections, setSections] = useState<SetupCopySection[]>(
        SETUP_COPY_SECTIONS.map((section) => section.key),
    );
    const [replace, setReplace] = useState(false);
    const [review, setReview] = useState<SetupCopyPreview | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const destination = operations.branch;
    const lines = review ? setupCopyLines(review.result) : null;

    const toggle = (key: SetupCopySection) => {
        setReview(null);
        setSections((current) =>
            current.includes(key)
                ? current.filter((item) => item !== key)
                : [...current, key],
        );
    };
    const load = () => {
        setError(null);
        request
            .get(
                setupCopyPreview.url({
                    query: {
                        source_branch_id: sourceId,
                        sections,
                        replace: replace ? 1 : 0,
                    },
                }),
                { headers: { Accept: 'application/json' } },
            )
            .then(setReview)
            .catch(() =>
                setError(
                    'The setup of that Branch could not be reviewed. Choose another Branch.',
                ),
            );
    };
    const confirm = () =>
        router.post(
            setupCopyStore.url(),
            { source_branch_id: sourceId, sections, replace },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onClose,
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ??
                            'The setup could not be copied.',
                    ),
            },
        );

    return (
        <OperationsDialog
            open
            onClose={onClose}
            busy={processing}
            kicker={`Operations · ${destination?.code ?? ''}`}
            title="Copy setup from another Branch"
            description={`Copies configuration into ${destination?.name ?? 'this Branch'} once. Afterwards each Branch changes on its own.`}
            footer={
                <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-2">
                    <button
                        type="button"
                        className={opsButtonClass}
                        onClick={review ? () => setReview(null) : onClose}
                        disabled={processing}
                    >
                        {review ? 'Back' : 'Cancel'}
                    </button>
                    {review ? (
                        <button
                            type="button"
                            className={opsPrimaryClass}
                            disabled={
                                processing || nothingToCopy(review.result)
                            }
                            onClick={confirm}
                        >
                            {processing ? 'Copying…' : 'Confirm copy'}
                        </button>
                    ) : (
                        <button
                            type="button"
                            className={opsPrimaryClass}
                            disabled={
                                sourceId === '' ||
                                sections.length === 0 ||
                                request.processing
                            }
                            onClick={load}
                        >
                            {request.processing ? 'Reviewing…' : 'Review copy'}
                        </button>
                    )}
                </div>
            }
        >
            {!review ? (
                <div className="space-y-4">
                    <label className="block space-y-1.5">
                        <span className={opsLabelClass}>Copy from</span>
                        <select
                            className={`${opsInputClass} aria-invalid:border-[#b91c1c]`}
                            aria-invalid={sourceId === ''}
                            aria-describedby="setup-copy-source-required"
                            value={sourceId}
                            onChange={(event) =>
                                setSourceId(event.target.value)
                            }
                        >
                            <option value="">Choose a Branch</option>
                            {operations.copy_sources.map((source) => (
                                <option key={source.id} value={source.id}>
                                    {source.name} · {source.code}
                                </option>
                            ))}
                        </select>
                        {sourceId === '' && (
                            <span
                                id="setup-copy-source-required"
                                className="block text-[11.5px] text-[#b91c1c]"
                            >
                                Required · choose the Branch to copy from.
                            </span>
                        )}
                    </label>
                    <fieldset
                        className="space-y-1.5"
                        aria-invalid={sections.length === 0}
                        aria-describedby="setup-copy-sections-required"
                    >
                        <legend className={opsLabelClass}>What to copy</legend>
                        {SETUP_COPY_SECTIONS.map((section) => (
                            <label
                                key={section.key}
                                className={`flex min-h-12 cursor-pointer items-start gap-3 rounded-xl p-3 ${requiredOutline(sections.length === 0, sections.includes(section.key))}`}
                            >
                                <input
                                    type="checkbox"
                                    className="mt-0.5 size-4 accent-[#111]"
                                    checked={sections.includes(section.key)}
                                    onChange={() => toggle(section.key)}
                                />
                                <span className="text-[12.5px] leading-5">
                                    <span className="block font-semibold">
                                        {section.label}
                                    </span>
                                    <span className="text-[#767676]">
                                        {section.help}
                                    </span>
                                </span>
                            </label>
                        ))}
                        {sections.length === 0 && (
                            <p
                                id="setup-copy-sections-required"
                                className="text-[11.5px] text-[#b91c1c]"
                            >
                                Required · choose at least one part to copy.
                            </p>
                        )}
                    </fieldset>
                    <fieldset className="space-y-1.5">
                        <legend className={opsLabelClass}>
                            When {destination?.code} already has it
                        </legend>
                        {(
                            [
                                [
                                    false,
                                    `Keep ${destination?.code}'s setup (skip)`,
                                    'Recommended. Only missing setup is added.',
                                ],
                                [
                                    true,
                                    'Replace with the copied setup',
                                    'Overwrites matching Plans, Ingredient settings and Recipes. Affects future sales only; past sales keep their recipes and costs.',
                                ],
                            ] as const
                        ).map(([value, label, help]) => (
                            <label
                                key={label}
                                className={`flex min-h-12 cursor-pointer items-start gap-3 rounded-xl border p-3 ${replace === value ? 'border-[#111]' : 'border-[#e5e5e5]'}`}
                            >
                                <input
                                    type="radio"
                                    name="setup-copy-mode"
                                    className="mt-0.5 size-4 accent-[#111]"
                                    checked={replace === value}
                                    onChange={() => {
                                        setReview(null);
                                        setReplace(value);
                                    }}
                                />
                                <span className="text-[12.5px] leading-5">
                                    <span className="block font-semibold">
                                        {label}
                                    </span>
                                    <span
                                        className={
                                            value
                                                ? 'text-[#b91c1c]'
                                                : 'text-[#767676]'
                                        }
                                    >
                                        {help}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    {error && (
                        <p role="alert" className="text-[12.5px] text-red-700">
                            {error}
                        </p>
                    )}
                </div>
            ) : (
                <div className="space-y-3 text-[13px] leading-6">
                    <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 rounded-xl bg-[#f5f5f5] p-3">
                        <dt className="text-[#767676]">Source</dt>
                        <dd className="font-semibold">
                            {review.source.name} · {review.source.code}
                        </dd>
                        <dt className="text-[#767676]">Destination</dt>
                        <dd className="font-semibold">
                            {review.destination.name} ·{' '}
                            {review.destination.code}
                        </dd>
                        <dt className="text-[#767676]">Products</dt>
                        <dd>
                            {review.result.products} sold at both Branches
                            {review.result.not_in_destination > 0 &&
                                ` · ${review.result.not_in_destination} not in ${review.destination.code} (skipped)`}
                        </dd>
                    </dl>
                    <div>
                        <p className="font-semibold">Will copy</p>
                        {lines && lines.copies.length > 0 ? (
                            <ul className="list-disc pl-5">
                                {lines.copies.map((line) => (
                                    <li key={line}>{line}</li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-[#767676]">
                                Nothing new: {review.destination.code} already
                                has this setup.
                            </p>
                        )}
                    </div>
                    {lines && lines.kept.length > 0 && (
                        <div>
                            <p className="font-semibold">
                                Kept at {review.destination.code}
                            </p>
                            <ul className="list-disc pl-5 text-[#555]">
                                {lines.kept.map((line) => (
                                    <li key={line}>{line}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    {review.result.skipped.length > 0 && (
                        <div className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-amber-900">
                            <p className="font-semibold">Not copied</p>
                            <ul className="list-disc pl-5">
                                {review.result.skipped.map((line) => (
                                    <li key={line}>{line}</li>
                                ))}
                            </ul>
                        </div>
                    )}
                    <div>
                        <p className="font-semibold">Will NOT copy</p>
                        <ul className="list-disc pl-5 text-[#555]">
                            {NEVER_COPIED.map((line) => (
                                <li key={line}>{line}</li>
                            ))}
                        </ul>
                    </div>
                    {replace && (
                        <p
                            role="note"
                            className="rounded-xl border border-red-200 bg-red-50 p-3 text-[12.5px] text-red-800"
                        >
                            Replacing configuration affects future sales only.
                            Historical sales remain unchanged.
                        </p>
                    )}
                </div>
            )}
        </OperationsDialog>
    );
}
