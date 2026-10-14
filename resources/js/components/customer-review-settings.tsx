import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { ReviewCard } from '@/components/customer-reviews';
import { controlClass } from '@/components/catalog-ui';
import { ownerPanelClass } from '@/components/owner-ui';
import type { CustomerReview, ReviewBranch } from '@/lib/customer-reviews';
import { qrRequest, qrError, qrAborted } from '@/lib/qr-http';
import { index, update, destroy } from '@/routes/branches/reviews';

type ReviewPage = {
    reviews: CustomerReview[];
    page: number;
    last_page: number;
    total: number;
};
export function CustomerReviewSettings({
    branches,
}: {
    branches: ReviewBranch[];
}) {
    const [branchId, setBranchId] = useState(branches[0]?.id ?? '');
    const [section, setSection] = useState('pending');
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [result, setResult] = useState<ReviewPage | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [removing, setRemoving] = useState<CustomerReview | null>(null);
    useEffect(() => {
        if (!branchId) return;
        const controller = new AbortController();
        setResult(null);
        setError('');
        void qrRequest<ReviewPage>(
            index(branchId, { query: { section, page } }),
            undefined,
            controller.signal,
        )
            .then(setResult)
            .catch((failure) => {
                if (!controller.signal.aborted && !qrAborted(failure))
                    setError(qrError(failure).message);
            });
        return () => controller.abort();
    }, [branchId, section, page, revision]);
    const moderate = async (review: CustomerReview, status: string) => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            await qrRequest(update({ branch: branchId, review: review.id }), {
                status,
            });
            setRevision((value) => value + 1);
        } catch (failure) {
            setError(qrError(failure).message);
        } finally {
            setBusy(false);
        }
    };
    return (
        <section
            aria-label="Reviews settings"
            className={`${ownerPanelClass} owner-reviews flex flex-col gap-4 p-4 sm:p-5`}
        >
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 className="text-lg font-bold">Customer Reviews</h2>
                    <p className="text-muted-foreground text-sm">
                        Approve or reject public submissions and read private
                        feedback.
                    </p>
                </div>
                <label className="flex flex-col gap-1 text-sm">
                    Branch
                    <select
                        className={controlClass}
                        value={branchId}
                        onChange={(event) => {
                            setBranchId(event.target.value);
                            setPage(1);
                        }}
                    >
                        {branches.map((branch) => (
                            <option key={branch.id} value={branch.id}>
                                {branch.name}
                            </option>
                        ))}
                    </select>
                </label>
            </div>
            <div
                role="tablist"
                aria-label="Review status"
                className="flex flex-wrap gap-2"
            >
                {['pending', 'approved', 'rejected', 'private'].map((value) => (
                    <Button
                        key={value}
                        role="tab"
                        aria-selected={section === value}
                        variant={section === value ? 'default' : 'outline'}
                        disabled={busy}
                        onClick={() => {
                            setSection(value);
                            setPage(1);
                        }}
                    >
                        {value[0].toUpperCase() + value.slice(1)}
                    </Button>
                ))}
            </div>
            {section === 'private' && (
                <p className="rounded-lg border p-3 text-sm">
                    Private feedback is visible only to the Owner and Super
                    Admin and cannot be approved or published. New customer
                    permission requires a new public submission.
                </p>
            )}
            {error && (
                <p role="alert" className="text-sm text-red-600">
                    {error}{' '}
                    <button
                        type="button"
                        className="underline"
                        onClick={() => setRevision((value) => value + 1)}
                    >
                        Retry
                    </button>
                </p>
            )}
            {!branchId ? (
                <p>No branches available.</p>
            ) : result === null && !error ? (
                <p role="status" className="animate-pulse">
                    Loading reviews...
                </p>
            ) : result?.reviews.length === 0 ? (
                <p className="text-muted-foreground py-8 text-center">
                    No {section} reviews.
                </p>
            ) : (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {result?.reviews.map((review) => (
                        <div
                            key={review.id}
                            className="flex min-w-0 flex-col gap-2"
                        >
                            <ReviewCard review={review} management />
                            <div className="flex flex-wrap gap-2">
                                {section !== 'private' && (
                                    <>
                                        <Button
                                            disabled={
                                                busy ||
                                                review.status === 'approved'
                                            }
                                            onClick={() =>
                                                void moderate(
                                                    review,
                                                    'approved',
                                                )
                                            }
                                        >
                                            Approve
                                        </Button>
                                        <Button
                                            variant="outline"
                                            disabled={
                                                busy ||
                                                review.status === 'rejected'
                                            }
                                            onClick={() =>
                                                void moderate(
                                                    review,
                                                    'rejected',
                                                )
                                            }
                                        >
                                            Reject
                                        </Button>
                                    </>
                                )}
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() => setRemoving(review)}
                                >
                                    Remove
                                </Button>
                            </div>
                        </div>
                    ))}
                </div>
            )}
            {result && result.last_page > 1 && (
                <div className="flex items-center gap-3">
                    <Button
                        variant="outline"
                        disabled={page <= 1 || busy}
                        onClick={() => setPage((value) => value - 1)}
                    >
                        Previous
                    </Button>
                    <span className="text-sm">
                        Page {result.page} of {result.last_page}
                    </span>
                    <Button
                        variant="outline"
                        disabled={page >= result.last_page || busy}
                        onClick={() => setPage((value) => value + 1)}
                    >
                        Next
                    </Button>
                </div>
            )}
            <Dialog
                open={removing !== null}
                onOpenChange={(value) => {
                    if (!value && !busy) setRemoving(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Remove this review?</DialogTitle>
                        <DialogDescription>
                            This removes the review and its images, including
                            any public display. This cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <Button
                        variant="destructive"
                        disabled={busy}
                        onClick={async () => {
                            if (!removing || busy) return;
                            setBusy(true);
                            try {
                                await qrRequest(
                                    destroy({
                                        branch: branchId,
                                        review: removing.id,
                                    }),
                                );
                                setRemoving(null);
                                setRevision((value) => value + 1);
                            } catch (failure) {
                                setError(qrError(failure).message);
                                setRemoving(null);
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {busy ? 'Removing...' : 'Remove review and images'}
                    </Button>
                </DialogContent>
            </Dialog>
        </section>
    );
}
