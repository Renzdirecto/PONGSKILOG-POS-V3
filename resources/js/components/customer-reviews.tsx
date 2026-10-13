import '../../css/customer-reviews.css';
import { useEffect, useRef, useState } from 'react';
import { useHttp } from '@inertiajs/react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { FormErrors } from '@/components/catalog-ui';
import { createClientUuid } from '@/lib/client-uuid';
import {
    REVIEW_NAME_MAX,
    advanceReviewScroll,
    reviewCharacterCount,
    reviewDateLabel,
    reviewFileError,
    reviewSubmitFailure,
    reviewSwipeStep,
} from '@/lib/customer-reviews';
import type {
    CustomerReview,
    ReviewBranch,
    ReviewImage,
} from '@/lib/customer-reviews';
import { qrError } from '@/lib/qr-http';
import { FB } from '@/lib/landing';
import { store } from '@/routes/reviews';

/**
 * Full-size review photos in a modal. Every image still comes from the authorized review-image route the card uses,
 * so a photo that stops being public stops loading here too; no object path or download link is exposed.
 */
function ReviewPhotoViewer({
    photos,
    name,
    index,
    onIndex,
    onClose,
}: {
    photos: ReviewImage[];
    name: string;
    index: number;
    onIndex: (index: number) => void;
    onClose: () => void;
}) {
    const swipe = useRef<{ x: number; y: number } | null>(null);
    const many = photos.length > 1;
    const photo = photos[index];
    const step = (direction: number) =>
        onIndex((index + direction + photos.length) % photos.length);
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                className="review-dialog review-viewer"
                overlayClassName="review-overlay review-viewer-overlay"
                showCloseButton={false}
                aria-describedby={undefined}
                onKeyDown={(event) => {
                    if (
                        many &&
                        (event.key === 'ArrowRight' ||
                            event.key === 'ArrowLeft')
                    ) {
                        event.preventDefault();
                        step(event.key === 'ArrowRight' ? 1 : -1);
                    }
                }}
                onTouchStart={(event) => {
                    const touch = event.touches[0];
                    swipe.current = { x: touch.clientX, y: touch.clientY };
                }}
                onTouchEnd={(event) => {
                    const start = swipe.current;
                    const touch = event.changedTouches[0];
                    swipe.current = null;
                    if (!start || !many) return;
                    const direction = reviewSwipeStep(
                        touch.clientX - start.x,
                        touch.clientY - start.y,
                    );
                    if (direction) step(direction);
                }}
            >
                <DialogTitle className="sr-only">
                    {`Photo ${index + 1} of ${photos.length} shared by ${name}`}
                </DialogTitle>
                <button
                    type="button"
                    className="review-viewer-close"
                    aria-label="Close photo"
                    onClick={onClose}
                >
                    <svg
                        width="20"
                        height="20"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        strokeWidth="2.2"
                        strokeLinecap="round"
                        aria-hidden="true"
                    >
                        <path d="M18 6L6 18M6 6l12 12" />
                    </svg>
                </button>
                <img
                    key={photo.url}
                    src={photo.url}
                    alt={`Customer photo ${index + 1} shared by ${name}`}
                    width={photo.width}
                    height={photo.height}
                    decoding="async"
                    draggable={false}
                />
                {many && (
                    <>
                        {(
                            [
                                [-1, 'Previous photo', 'M15 18l-6-6 6-6'],
                                [1, 'Next photo', 'M9 18l6-6-6-6'],
                            ] as const
                        ).map(([direction, label, path]) => (
                            <button
                                key={label}
                                type="button"
                                className={
                                    direction < 0
                                        ? 'review-viewer-prev'
                                        : 'review-viewer-next'
                                }
                                aria-label={label}
                                onClick={() => step(direction)}
                            >
                                <svg
                                    width="22"
                                    height="22"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2.2"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    aria-hidden="true"
                                >
                                    <path d={path} />
                                </svg>
                            </button>
                        ))}
                        <span
                            className="review-viewer-count"
                            aria-hidden="true"
                        >
                            {index + 1} / {photos.length}
                        </span>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}

export function ReviewCard({
    review,
    management = false,
}: {
    review: CustomerReview;
    management?: boolean;
}) {
    const [expanded, setExpanded] = useState(management);
    const [viewing, setViewing] = useState<number | null>(null);
    const avatar = review.images.find((image) => image.kind === 'profile');
    const photos = review.images.filter((image) => image.kind === 'review');
    const long = reviewCharacterCount(review.description) > 120;
    return (
        <article className="review-card">
            <header className="review-head">
                {avatar ? (
                    <img
                        src={avatar.url}
                        width="36"
                        height="36"
                        alt=""
                        loading="lazy"
                        decoding="async"
                    />
                ) : (
                    <span aria-hidden="true">
                        {Array.from(review.preferred_name)[0]}
                    </span>
                )}
                {/* Names saved before the 10-character limit stay whole in the data; the card only clips them visually. */}
                <strong title={review.preferred_name}>
                    {review.preferred_name}
                </strong>
                <div
                    className="review-stars"
                    role="img"
                    aria-label={`${review.rating} out of 5 stars`}
                >
                    {'★'.repeat(review.rating)}
                    <span aria-hidden="true">
                        {'☆'.repeat(5 - review.rating)}
                    </span>
                </div>
            </header>
            <div className="review-body">
                <p className="review-quote">
                    {long && !expanded
                        ? `${Array.from(review.description).slice(0, 120).join('')}…`
                        : review.description}
                </p>
                {long && (
                    <button
                        type="button"
                        className="review-more"
                        aria-expanded={expanded}
                        onClick={() => setExpanded(!expanded)}
                    >
                        {expanded ? 'See Less' : 'See More'}
                    </button>
                )}
                {photos.length > 0 && (
                    <div className="review-photos">
                        {photos.map((image, index) => (
                            <button
                                key={image.url}
                                type="button"
                                aria-label={`View photo ${index + 1} of ${photos.length}`}
                                onClick={() => setViewing(index)}
                            >
                                <img
                                    src={image.url}
                                    alt={`Customer photo ${index + 1} shared by ${review.preferred_name}`}
                                    width={image.width}
                                    height={image.height}
                                    loading="lazy"
                                    decoding="async"
                                    draggable={false}
                                />
                            </button>
                        ))}
                    </div>
                )}
                {review.purchased_items && (
                    <div className="review-purchases">
                        <span>Ordered - self-reported</span>
                        <p>{review.purchased_items}</p>
                    </div>
                )}
            </div>
            <footer className="review-foot">
                <time dateTime={review.date}>
                    {reviewDateLabel(review.date)}
                </time>
                {review.visit_type && (
                    <span>
                        {review.visit_type === 'dine_in'
                            ? 'Dine-in'
                            : 'Takeout'}
                    </span>
                )}
            </footer>
            {viewing !== null && (
                <ReviewPhotoViewer
                    photos={photos}
                    name={review.preferred_name}
                    index={viewing}
                    onIndex={setViewing}
                    onClose={() => setViewing(null)}
                />
            )}
        </article>
    );
}

export function CustomerReviews({
    reviews,
    branches,
    noticeVersion,
}: {
    reviews: CustomerReview[];
    branches: ReviewBranch[];
    noticeVersion: string;
}) {
    const [open, setOpen] = useState(false);
    const [automatic, setAutomatic] = useState(true);
    const rail = useRef<HTMLDivElement>(null);
    const interaction = useRef({
        hovered: false,
        focused: false,
        touched: false,
        until: 0,
    });
    const drag = useRef<{ x: number; scroll: number; moved: boolean } | null>(
        null,
    );
    useEffect(() => {
        const element = rail.current;
        if (!element || reviews.length < 2 || !automatic) return;
        const media = window.matchMedia('(prefers-reduced-motion: reduce)');
        let offset = element.scrollLeft;
        let frame = 0,
            previous = 0,
            direction = 1,
            visible = true;
        const tick = (time: number) => {
            const elapsed = previous ? time - previous : 0;
            previous = time;
            const pause = interaction.current;
            if (
                visible &&
                !document.hidden &&
                !media.matches &&
                !pause.hovered &&
                !pause.focused &&
                !pause.touched &&
                time > pause.until
            ) {
                const next = advanceReviewScroll(
                    offset,
                    element.scrollWidth - element.clientWidth,
                    elapsed,
                    direction,
                );
                offset = next.position;
                element.scrollLeft = offset;
                direction = next.direction;
            } else {
                offset = element.scrollLeft;
            }
            frame = requestAnimationFrame(tick);
        };
        const start = () => {
            cancelAnimationFrame(frame);
            previous = 0;
            if (!media.matches && !document.hidden)
                frame = requestAnimationFrame(tick);
        };
        const observer = new IntersectionObserver((entries) => {
            visible = entries.some((entry) => entry.isIntersecting);
        });
        observer.observe(element);
        media.addEventListener('change', start);
        document.addEventListener('visibilitychange', start);
        start();
        return () => {
            cancelAnimationFrame(frame);
            observer.disconnect();
            media.removeEventListener('change', start);
            document.removeEventListener('visibilitychange', start);
        };
    }, [reviews.length, automatic]);
    const step = (direction: number) => {
        const element = rail.current;
        if (element) {
            interaction.current.until = performance.now() + 4000;
            element.scrollLeft +=
                direction * Math.min(376, element.clientWidth * 0.85);
        }
    };
    return (
        <section
            id="reviews"
            aria-label="Customer reviews"
            className="customer-reviews"
        >
            <div className="reviews-heading">
                <div>
                    <div className="reviews-eyebrow">Reviews</div>
                    <h2>
                        Sabi ng
                        <br />
                        mga suki.
                    </h2>
                    <p>
                        {reviews.length
                            ? 'Stories shared by our customers.'
                            : 'Your experience could be the first.'}
                    </p>
                </div>
                <div className="reviews-actions">
                    <button
                        type="button"
                        onClick={() => step(-1)}
                        aria-label="Previous reviews"
                    >
                        ←
                    </button>
                    <button
                        type="button"
                        onClick={() => step(1)}
                        aria-label="Next reviews"
                    >
                        →
                    </button>
                    {reviews.length > 1 && (
                        <button
                            type="button"
                            aria-pressed={!automatic}
                            onClick={() => setAutomatic(!automatic)}
                        >
                            {automatic ? 'Pause' : 'Resume'}
                        </button>
                    )}
                    <button
                        type="button"
                        className="review-primary"
                        onClick={() => setOpen(true)}
                    >
                        Write a Review
                    </button>
                </div>
            </div>
            {reviews.length ? (
                <div
                    ref={rail}
                    className="reviews-rail"
                    role="region"
                    aria-roledescription="carousel"
                    aria-label="Customer reviews. Swipe or drag to explore."
                    tabIndex={0}
                    onPointerEnter={(event) => {
                        if (event.pointerType === 'mouse')
                            interaction.current.hovered = true;
                    }}
                    onPointerLeave={() => {
                        interaction.current.hovered = false;
                        if (!drag.current?.moved) {
                            interaction.current.touched = false;
                            drag.current = null;
                        }
                    }}
                    onFocusCapture={() => {
                        interaction.current.focused = true;
                    }}
                    onBlurCapture={(event) => {
                        if (!event.currentTarget.contains(event.relatedTarget))
                            interaction.current.focused = false;
                    }}
                    onPointerDown={(event) => {
                        interaction.current.touched = true;
                        /* The photo viewer is a portal: its pointer events bubble here but must never drag the rail. */
                        if (
                            event.pointerType === 'mouse' &&
                            event.button === 0 &&
                            event.currentTarget.contains(
                                event.target as Node,
                            ) &&
                            !(event.target as HTMLElement).closest('button,a')
                        )
                            drag.current = {
                                x: event.clientX,
                                scroll: event.currentTarget.scrollLeft,
                                moved: false,
                            };
                    }}
                    onPointerMove={(event) => {
                        if (drag.current) {
                            const distance = event.clientX - drag.current.x;
                            if (Math.abs(distance) > 6) {
                                drag.current.moved = true;
                                event.currentTarget.setPointerCapture(
                                    event.pointerId,
                                );
                                event.currentTarget.scrollLeft =
                                    drag.current.scroll - distance;
                            }
                        }
                    }}
                    onPointerUp={() => {
                        interaction.current.touched = false;
                        interaction.current.until = performance.now() + 2000;
                        drag.current = null;
                    }}
                    onPointerCancel={(event) => {
                        if (event?.pointerType !== 'touch')
                            interaction.current.touched = false;
                        drag.current = null;
                    }}
                    onTouchStart={() => {
                        interaction.current.touched = true;
                    }}
                    onTouchEnd={() => {
                        interaction.current.touched = false;
                        interaction.current.until = performance.now() + 2000;
                    }}
                    onTouchCancel={() => {
                        interaction.current.touched = false;
                    }}
                    onKeyDown={(event) => {
                        if (event.target !== event.currentTarget) return;
                        if (
                            event.key === 'ArrowRight' ||
                            event.key === 'ArrowLeft'
                        ) {
                            event.preventDefault();
                            step(event.key === 'ArrowRight' ? 1 : -1);
                        }
                    }}
                >
                    {reviews.map((review) => (
                        <ReviewCard key={review.id} review={review} />
                    ))}
                </div>
            ) : (
                <div className="reviews-empty">
                    No approved customer reviews yet. Tell us about your visit -
                    public reviews appear here after approval.
                </div>
            )}
            {open && (
                <WriteReview
                    branches={branches}
                    noticeVersion={noticeVersion}
                    onClose={() => setOpen(false)}
                />
            )}
        </section>
    );
}

type ReviewForm = {
    branch_id: string;
    submission_key: string;
    rating: number;
    preferred_name: string;
    customer_email: string;
    description: string;
    purchased_items: string;
    visit_type: string;
    visibility: string;
    acknowledgment: boolean;
    public_consent: boolean;
    notice_version: string;
    website: string;
    profile_photo: File | null;
    review_images: File[];
};
type SelectedImage = { file: File; url: string };
const RATING_LABELS = ['', 'Poor', 'Fair', 'Good', 'Great', 'Excellent'];
function WriteReview({
    branches,
    noticeVersion,
    onClose,
}: {
    branches: ReviewBranch[];
    noticeVersion: string;
    onClose: () => void;
}) {
    const form = useHttp<ReviewForm>({
        branch_id: branches.length === 1 ? branches[0].id : '',
        submission_key: createClientUuid(),
        rating: 0,
        preferred_name: '',
        customer_email: '',
        description: '',
        purchased_items: '',
        visit_type: '',
        visibility: 'public',
        acknowledgment: false,
        public_consent: false,
        notice_version: noticeVersion,
        website: '',
        profile_photo: null,
        review_images: [],
    });
    const [legal, setLegal] = useState<'terms' | 'privacy' | null>(null);
    const [feedback, setFeedback] = useState('');
    /** The name the server confirmed it saved; null until then, so the thank-you can never appear early. */
    const [savedName, setSavedName] = useState<string | null>(null);
    const [profileError, setProfileError] = useState('');
    const [imagesError, setImagesError] = useState('');
    const [photos, setPhotos] = useState<SelectedImage[]>([]);
    const [avatar, setAvatar] = useState<SelectedImage | null>(null);
    const sending = useRef(false);
    const previewUrls = useRef<string[]>([]);
    useEffect(() => {
        const urls = previewUrls.current;
        return () => urls.forEach((url) => URL.revokeObjectURL(url));
    }, []);
    const preview = (file: File): SelectedImage => {
        const url = URL.createObjectURL(file);
        previewUrls.current.push(url);
        return { file, url };
    };
    const invalid = (key: keyof ReviewForm) =>
        Boolean(form.errors[key]) || !form.data[key];
    const error = (key: keyof ReviewForm) =>
        form.errors[key] ? (
            <small role="alert" className="review-field-error">
                {form.errors[key]}
            </small>
        ) : null;
    const close = () => {
        if (!form.processing) onClose();
    };
    return (
        <>
            <Dialog
                open
                onOpenChange={(value) => {
                    if (!value) close();
                }}
            >
                <DialogContent
                    className="review-dialog review-sheet"
                    overlayClassName="review-overlay"
                    showCloseButton={false}
                >
                    <div className="review-sheet-head">
                        <span
                            className="review-sheet-grip"
                            aria-hidden="true"
                        />
                        <div>
                            <DialogTitle className="review-sheet-title">
                                Write a review
                            </DialogTitle>
                            <button
                                type="button"
                                className="review-sheet-close"
                                aria-label="Close"
                                onClick={close}
                            >
                                <svg
                                    width="18"
                                    height="18"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2.2"
                                    strokeLinecap="round"
                                    aria-hidden="true"
                                >
                                    <path d="M18 6L6 18M6 6l12 12" />
                                </svg>
                            </button>
                        </div>
                    </div>
                    {savedName !== null ? (
                        <div className="review-sent">
                            <span aria-hidden="true">
                                <svg
                                    width="30"
                                    height="30"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="2.6"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                >
                                    <path d="M5 12.5l4.5 4.5L19 7.5" />
                                </svg>
                            </span>
                            <h3>Salamat, {savedName}!</h3>
                            <DialogDescription>
                                {form.data.visibility === 'public'
                                    ? 'Your review was received and is waiting for approval.'
                                    : 'Your feedback was sent privately to PONGSKILOG. It will never appear on the landing page.'}
                            </DialogDescription>
                            <button
                                type="button"
                                className="review-primary"
                                onClick={onClose}
                            >
                                Done
                            </button>
                        </div>
                    ) : (
                        <form
                            className="review-form"
                            onSubmit={async (event) => {
                                event.preventDefault();
                                if (sending.current || form.processing) return;
                                sending.current = true;
                                setFeedback('');
                                const sheet = event.currentTarget;
                                try {
                                    /* useHttp resolves without a body on a 422: only a confirmed save shows the thank-you. */
                                    const result = (await form.post(
                                        store.url(),
                                    )) as
                                        | {
                                              saved?: boolean;
                                              preferred_name?: string;
                                          }
                                        | undefined;
                                    if (result?.saved)
                                        setSavedName(
                                            result.preferred_name ??
                                                form.data.preferred_name.trim(),
                                        );
                                    else {
                                        setFeedback(
                                            'Please fix the errors marked in red above.',
                                        );
                                        /* The errors render after this handler, so the first one is found on the next task. */
                                        setTimeout(() =>
                                            sheet
                                                ?.querySelector(
                                                    '.review-sheet-body [role="alert"]',
                                                )
                                                ?.closest(
                                                    '.review-field, [role="alert"]',
                                                )
                                                ?.scrollIntoView({
                                                    block: 'center',
                                                }),
                                        );
                                    }
                                } catch (failure) {
                                    const { status } = qrError(failure);
                                    /* An expired session also expired the CSRF cookie: renew it so the next submit works without losing the form. */
                                    if (status === 419)
                                        await fetch(window.location.href, {
                                            credentials: 'same-origin',
                                        }).catch(() => undefined);
                                    setFeedback(
                                        reviewSubmitFailure(
                                            status,
                                            (
                                                failure as {
                                                    response?: {
                                                        headers?: Record<
                                                            string,
                                                            string
                                                        >;
                                                    };
                                                }
                                            ).response?.headers?.[
                                                'retry-after'
                                            ],
                                        ),
                                    );
                                } finally {
                                    sending.current = false;
                                }
                            }}
                        >
                            <div className="review-sheet-body">
                                <DialogDescription className="review-hint">
                                    Share a quick, honest review. No login
                                    needed.
                                </DialogDescription>
                                {branches.length !== 1 && (
                                    <label className="review-field">
                                        <span className="review-label">
                                            Branch
                                            <span className="review-required">
                                                Required
                                            </span>
                                        </span>
                                        <select
                                            required
                                            value={form.data.branch_id}
                                            aria-invalid={invalid('branch_id')}
                                            onChange={(event) =>
                                                form.setData(
                                                    'branch_id',
                                                    event.target.value,
                                                )
                                            }
                                        >
                                            <option value="">
                                                Choose a branch
                                            </option>
                                            {branches.map((branch) => (
                                                <option
                                                    key={branch.id}
                                                    value={branch.id}
                                                >
                                                    {branch.name}
                                                </option>
                                            ))}
                                        </select>
                                        {error('branch_id')}
                                    </label>
                                )}
                                <fieldset className="review-field review-rating">
                                    <legend className="review-label">
                                        How was it?
                                        <span className="review-required">
                                            Required
                                        </span>
                                    </legend>
                                    <div
                                        role="radiogroup"
                                        aria-label="Star rating"
                                        aria-invalid={invalid('rating')}
                                    >
                                        {[1, 2, 3, 4, 5].map((star) => (
                                            <button
                                                key={star}
                                                type="button"
                                                role="radio"
                                                aria-checked={
                                                    form.data.rating === star
                                                }
                                                aria-label={`${star} star${star > 1 ? 's' : ''}`}
                                                onKeyDown={(event) => {
                                                    if (
                                                        event.key ===
                                                            'ArrowRight' ||
                                                        event.key ===
                                                            'ArrowLeft'
                                                    ) {
                                                        event.preventDefault();
                                                        form.setData(
                                                            'rating',
                                                            Math.max(
                                                                1,
                                                                Math.min(
                                                                    5,
                                                                    star +
                                                                        (event.key ===
                                                                        'ArrowRight'
                                                                            ? 1
                                                                            : -1),
                                                                ),
                                                            ),
                                                        );
                                                    }
                                                }}
                                                onClick={() =>
                                                    form.setData('rating', star)
                                                }
                                            >
                                                <svg
                                                    width="36"
                                                    height="36"
                                                    viewBox="0 0 24 24"
                                                    aria-hidden="true"
                                                >
                                                    <path
                                                        d="M12 2.6l2.85 5.95 6.55.85-4.8 4.55 1.2 6.5L12 17.3l-5.8 3.15 1.2-6.5-4.8-4.55 6.55-.85z"
                                                        fill={
                                                            star <=
                                                            form.data.rating
                                                                ? '#8A5E12'
                                                                : 'transparent'
                                                        }
                                                        stroke={
                                                            star <=
                                                            form.data.rating
                                                                ? '#8A5E12'
                                                                : 'rgba(18,17,16,.3)'
                                                        }
                                                        strokeWidth="1.3"
                                                        strokeLinejoin="round"
                                                    />
                                                </svg>
                                            </button>
                                        ))}
                                        <span aria-live="polite">
                                            {RATING_LABELS[form.data.rating]}
                                        </span>
                                    </div>
                                    {error('rating')}
                                </fieldset>
                                <label className="review-field">
                                    <span className="review-label">
                                        Your review
                                        <span className="review-required">
                                            Required
                                        </span>
                                        <small id="review-character-counter">
                                            {reviewCharacterCount(
                                                form.data.description,
                                            )}
                                            /200 characters
                                        </small>
                                    </span>
                                    <textarea
                                        required
                                        rows={3}
                                        placeholder="Solid ang tapa, sulit sa presyo."
                                        value={form.data.description}
                                        aria-invalid={invalid('description')}
                                        aria-describedby="review-character-counter"
                                        onChange={(event) =>
                                            form.setData(
                                                'description',
                                                Array.from(event.target.value)
                                                    .slice(0, 200)
                                                    .join(''),
                                            )
                                        }
                                    />
                                    {error('description')}
                                </label>
                                <div className="review-field">
                                    <span className="review-label">
                                        Photos
                                        <span className="review-optional">
                                            · Optional, up to 3 · 5 MB each
                                        </span>
                                    </span>
                                    <div className="review-photo-grid">
                                        {photos.map((photo, index) => (
                                            <div key={photo.url}>
                                                <img
                                                    src={photo.url}
                                                    alt={`Your photo ${index + 1}`}
                                                />
                                                <button
                                                    type="button"
                                                    aria-label={`Remove photo ${index + 1}`}
                                                    onClick={() => {
                                                        const next =
                                                            photos.filter(
                                                                (item) =>
                                                                    item !==
                                                                    photo,
                                                            );
                                                        URL.revokeObjectURL(
                                                            photo.url,
                                                        );
                                                        setPhotos(next);
                                                        setImagesError('');
                                                        form.setData(
                                                            'review_images',
                                                            next.map(
                                                                (item) =>
                                                                    item.file,
                                                            ),
                                                        );
                                                    }}
                                                >
                                                    <span>
                                                        <svg
                                                            width="14"
                                                            height="14"
                                                            viewBox="0 0 24 24"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            strokeWidth="2.4"
                                                            strokeLinecap="round"
                                                            aria-hidden="true"
                                                        >
                                                            <path d="M18 6L6 18M6 6l12 12" />
                                                        </svg>
                                                    </span>
                                                </button>
                                            </div>
                                        ))}
                                        {photos.length < 3 && (
                                            <label className="review-photo-add">
                                                <input
                                                    type="file"
                                                    multiple
                                                    accept="image/jpeg,image/png,image/webp"
                                                    aria-label="Add photos"
                                                    onChange={(event) => {
                                                        const files =
                                                            Array.from(
                                                                event.target
                                                                    .files ??
                                                                    [],
                                                            );
                                                        event.target.value = '';
                                                        const next = [
                                                            ...photos.map(
                                                                (item) =>
                                                                    item.file,
                                                            ),
                                                            ...files,
                                                        ];
                                                        const message =
                                                            reviewFileError(
                                                                next,
                                                                3,
                                                            );
                                                        setImagesError(
                                                            message ?? '',
                                                        );
                                                        if (message) return;
                                                        setPhotos([
                                                            ...photos,
                                                            ...files.map(
                                                                preview,
                                                            ),
                                                        ]);
                                                        form.setData(
                                                            'review_images',
                                                            next,
                                                        );
                                                    }}
                                                />
                                                <svg
                                                    width="24"
                                                    height="24"
                                                    viewBox="0 0 24 24"
                                                    fill="none"
                                                    stroke="currentColor"
                                                    strokeWidth="1.9"
                                                    strokeLinecap="round"
                                                    strokeLinejoin="round"
                                                    aria-hidden="true"
                                                >
                                                    <path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z" />
                                                    <circle
                                                        cx="12"
                                                        cy="13"
                                                        r="3.5"
                                                    />
                                                </svg>
                                                <span>
                                                    {photos.length
                                                        ? 'Add more'
                                                        : 'Add photos'}
                                                </span>
                                            </label>
                                        )}
                                    </div>
                                    {imagesError && (
                                        <small
                                            role="alert"
                                            className="review-field-error"
                                        >
                                            {imagesError}
                                        </small>
                                    )}
                                    {error('review_images')}
                                    {Object.entries(form.errors)
                                        .filter(([key]) =>
                                            key.startsWith('review_images.'),
                                        )
                                        .map(([key, message]) => (
                                            <small
                                                key={key}
                                                role="alert"
                                                className="review-field-error"
                                            >
                                                {message}
                                            </small>
                                        ))}
                                </div>
                                <label className="review-field">
                                    <span className="review-label">
                                        Preferred name
                                        <span className="review-required">
                                            Required
                                        </span>
                                    </span>
                                    <Input
                                        required
                                        autoComplete="nickname"
                                        placeholder="e.g. Carlo M."
                                        value={form.data.preferred_name}
                                        aria-invalid={invalid('preferred_name')}
                                        onChange={(event) =>
                                            form.setData(
                                                'preferred_name',
                                                Array.from(event.target.value)
                                                    .slice(0, REVIEW_NAME_MAX)
                                                    .join(''),
                                            )
                                        }
                                    />
                                    <span className="review-hint">
                                        Up to {REVIEW_NAME_MAX} characters.
                                        Shown with your review if you post it on
                                        the page.
                                    </span>
                                    {error('preferred_name')}
                                </label>
                                <div className="review-profile">
                                    {avatar ? (
                                        <img
                                            src={avatar.url}
                                            alt="Your profile photo"
                                        />
                                    ) : (
                                        <span aria-hidden="true">
                                            {Array.from(
                                                (
                                                    form.data.preferred_name ??
                                                    ''
                                                ).trim(),
                                            )[0]?.toUpperCase() ?? '?'}
                                        </span>
                                    )}
                                    <div className="review-field">
                                        <span className="review-label">
                                            Profile photo
                                            <span className="review-optional">
                                                · Optional · 5 MB
                                            </span>
                                        </span>
                                        <span className="review-hint">
                                            Leave blank to show your initial.
                                        </span>
                                        <div className="review-profile-actions">
                                            <label>
                                                <input
                                                    type="file"
                                                    accept="image/jpeg,image/png,image/webp"
                                                    aria-label="Choose profile photo"
                                                    onChange={(event) => {
                                                        const files =
                                                            Array.from(
                                                                event.target
                                                                    .files ??
                                                                    [],
                                                            );
                                                        event.target.value = '';
                                                        const message =
                                                            reviewFileError(
                                                                files,
                                                                1,
                                                            );
                                                        setProfileError(
                                                            message ?? '',
                                                        );
                                                        if (
                                                            message ||
                                                            !files[0]
                                                        )
                                                            return;
                                                        if (avatar)
                                                            URL.revokeObjectURL(
                                                                avatar.url,
                                                            );
                                                        setAvatar(
                                                            preview(files[0]),
                                                        );
                                                        form.setData(
                                                            'profile_photo',
                                                            files[0],
                                                        );
                                                    }}
                                                />
                                                {avatar
                                                    ? 'Change photo'
                                                    : 'Choose photo'}
                                            </label>
                                            {avatar && (
                                                <button
                                                    type="button"
                                                    onClick={() => {
                                                        URL.revokeObjectURL(
                                                            avatar.url,
                                                        );
                                                        setAvatar(null);
                                                        setProfileError('');
                                                        form.setData(
                                                            'profile_photo',
                                                            null,
                                                        );
                                                    }}
                                                >
                                                    Remove
                                                </button>
                                            )}
                                        </div>
                                        {profileError && (
                                            <small
                                                role="alert"
                                                className="review-field-error"
                                            >
                                                {profileError}
                                            </small>
                                        )}
                                        {error('profile_photo')}
                                    </div>
                                </div>
                                <label className="review-field">
                                    <span className="review-label">
                                        Email
                                        <span className="review-optional">
                                            · Optional
                                        </span>
                                    </span>
                                    <Input
                                        type="email"
                                        inputMode="email"
                                        autoComplete="email"
                                        autoCapitalize="none"
                                        spellCheck={false}
                                        maxLength={254}
                                        placeholder="you@example.com"
                                        value={form.data.customer_email}
                                        aria-invalid={Boolean(
                                            form.errors.customer_email,
                                        )}
                                        onChange={(event) =>
                                            form.setData(
                                                'customer_email',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <span className="review-hint">
                                        Kept private and never shown with your
                                        review. Only for updates about this
                                        review.
                                    </span>
                                    {error('customer_email')}
                                </label>
                                <label className="review-field">
                                    <span className="review-label">
                                        What did you order?
                                        <span className="review-optional">
                                            · Optional
                                        </span>
                                    </span>
                                    <Input
                                        maxLength={200}
                                        placeholder="e.g. Tapsilog, Lemon Yakult"
                                        value={form.data.purchased_items}
                                        onChange={(event) =>
                                            form.setData(
                                                'purchased_items',
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <span className="review-hint">
                                        Self-reported, not verified.
                                    </span>
                                    {error('purchased_items')}
                                </label>
                                <div className="review-field">
                                    <span className="review-label">
                                        Dine-in or takeout?
                                        <span className="review-optional">
                                            · Optional
                                        </span>
                                    </span>
                                    <div className="review-segment">
                                        {(
                                            [
                                                ['dine_in', 'Dine-in'],
                                                ['takeout', 'Takeout'],
                                            ] as const
                                        ).map(([value, label]) => (
                                            <button
                                                key={value}
                                                type="button"
                                                aria-pressed={
                                                    form.data.visit_type ===
                                                    value
                                                }
                                                onClick={() =>
                                                    form.setData(
                                                        'visit_type',
                                                        form.data.visit_type ===
                                                            value
                                                            ? ''
                                                            : value,
                                                    )
                                                }
                                            >
                                                {label}
                                            </button>
                                        ))}
                                    </div>
                                </div>
                                <fieldset className="review-field">
                                    <legend className="review-label">
                                        Who can see this?
                                    </legend>
                                    <div className="review-visibility">
                                        {[
                                            [
                                                'public',
                                                'Post on the page',
                                                'Your name, review and photos show in Reviews after approval.',
                                            ],
                                            [
                                                'private',
                                                'Just for the owner',
                                                'Only PONGSKILOG sees it. Good for private feedback.',
                                            ],
                                        ].map(([value, label, detail]) => (
                                            <label key={value}>
                                                <input
                                                    type="radio"
                                                    name="visibility"
                                                    checked={
                                                        form.data.visibility ===
                                                        value
                                                    }
                                                    onChange={() =>
                                                        form.setData(
                                                            'visibility',
                                                            value,
                                                        )
                                                    }
                                                />
                                                <span>
                                                    <strong>{label}</strong>
                                                    <span>{detail}</span>
                                                </span>
                                            </label>
                                        ))}
                                    </div>
                                </fieldset>
                                <label className="review-check">
                                    <input
                                        type="checkbox"
                                        required
                                        checked={form.data.acknowledgment}
                                        aria-invalid={invalid('acknowledgment')}
                                        onChange={(event) =>
                                            form.setData(
                                                'acknowledgment',
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    <span>
                                        I agree to the{' '}
                                        <button
                                            type="button"
                                            onClick={() => setLegal('terms')}
                                        >
                                            Terms & Conditions
                                        </button>{' '}
                                        and acknowledge the{' '}
                                        <button
                                            type="button"
                                            onClick={() => setLegal('privacy')}
                                        >
                                            Privacy Notice
                                        </button>
                                        .
                                    </span>
                                </label>
                                {error('acknowledgment')}
                                {form.data.visibility === 'public' && (
                                    <>
                                        <label className="review-check">
                                            <input
                                                type="checkbox"
                                                required
                                                checked={
                                                    form.data.public_consent
                                                }
                                                aria-invalid={invalid(
                                                    'public_consent',
                                                )}
                                                onChange={(event) =>
                                                    form.setData(
                                                        'public_consent',
                                                        event.target.checked,
                                                    )
                                                }
                                            />
                                            <span>
                                                I consent to public display of
                                                my preferred name, rating,
                                                review, optional photos and
                                                purchased items after approval.
                                            </span>
                                        </label>
                                        {error('public_consent')}
                                    </>
                                )}
                                <label
                                    className="review-honeypot"
                                    aria-hidden="true"
                                >
                                    Website
                                    <input
                                        tabIndex={-1}
                                        autoComplete="off"
                                        value={form.data.website}
                                        onChange={(event) =>
                                            form.setData(
                                                'website',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <FormErrors
                                    errors={form.errors}
                                    inline={[
                                        'branch_id',
                                        'rating',
                                        'preferred_name',
                                        'customer_email',
                                        'description',
                                        'profile_photo',
                                        'review_images',
                                        /^review_images\./,
                                        'purchased_items',
                                        'acknowledgment',
                                        'public_consent',
                                    ]}
                                />
                            </div>
                            <div className="review-sheet-foot">
                                {feedback && (
                                    <p
                                        role="alert"
                                        className="review-field-error"
                                    >
                                        {feedback}
                                    </p>
                                )}
                                {form.progress && (
                                    <progress
                                        value={form.progress.percentage}
                                        max={100}
                                        aria-label="Image upload progress"
                                    />
                                )}
                                <button
                                    type="submit"
                                    className="review-primary"
                                    disabled={
                                        form.processing || branches.length === 0
                                    }
                                >
                                    {form.processing
                                        ? 'Sending...'
                                        : 'Submit review'}
                                </button>
                            </div>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
            <ReviewLegalDialog
                legal={legal}
                noticeVersion={noticeVersion}
                onClose={() => setLegal(null)}
            />
        </>
    );
}

/** The review Terms and the site Privacy Notice; the landing footer opens the notice without the review form. */
export function ReviewLegalDialog({
    legal,
    noticeVersion,
    onClose,
}: {
    legal: 'terms' | 'privacy' | null;
    noticeVersion: string;
    onClose: () => void;
}) {
    return (
        <Dialog
            open={legal !== null}
            onOpenChange={(value) => {
                if (!value) onClose();
            }}
        >
            <DialogContent
                className="review-dialog max-h-[85svh] overflow-y-auto"
                overlayClassName="review-overlay"
            >
                <DialogHeader>
                    <DialogTitle>
                        {legal === 'terms'
                            ? 'Terms & Conditions'
                            : 'Privacy Notice'}
                    </DialogTitle>
                    <DialogDescription>
                        Notice {noticeVersion}
                    </DialogDescription>
                </DialogHeader>
                <div className="review-legal">
                    {legal === 'terms' ? (
                        <>
                            <p>
                                Share an honest experience of your own visit.
                                Use a preferred name; do not include phone
                                numbers, addresses, payment details, harassment,
                                advertising or other people’s personal
                                information.
                            </p>
                            <p>
                                Upload only photos you own and have permission
                                to share. Purchased items are self-reported and
                                are never marked as verified purchases.
                            </p>
                            <p>
                                Public reviews require your separate consent and
                                approval by PONGSKILOG. PONGSKILOG may reject
                                inappropriate content. Private feedback cannot
                                be published; public sharing would require a new
                                submission with new permission.
                            </p>
                        </>
                    ) : (
                        <>
                            <p>
                                PONGSKILOG collects your preferred name, rating,
                                review, optional visit details and photos to
                                receive feedback and, when you consent, display
                                approved reviews publicly. No account is
                                required. An email address is optional: it is
                                stored encrypted, never displayed publicly and
                                used only to contact you about this review.
                            </p>
                            <p>
                                Only the authorized Owner and system
                                administrator can read private feedback and
                                moderate submissions. Photos are re-encoded,
                                resized and stored privately in object storage;
                                originals and embedded photo metadata are
                                discarded. Publicly displayed content can be
                                copied by visitors.
                            </p>
                            <p>
                                We record acknowledgment time and notice
                                version. Session identifiers are hashed for
                                duplicate and spam prevention; request addresses
                                are used for short-lived rate limits, not saved
                                in review records.
                            </p>
                            <p>
                                This website counts page visits and taps on its
                                menu, directions and Facebook links to
                                understand how the page is used. The counts are
                                anonymous and stay with PONGSKILOG: no IP
                                address, name or advertising tracker is stored
                                or shared, and the daily session hash used to
                                estimate visitors cannot identify you. Counts
                                are kept for up to 12 months.
                            </p>
                            <p>
                                Pending, rejected and private feedback is
                                retained for up to 90 days. Approved public
                                reviews are retained for up to one year from
                                submission. Daily cleanup removes expired
                                records and photos.
                            </p>
                            <p>
                                To request access, correction, removal or
                                withdrawal of public consent, message{' '}
                                <a
                                    href={FB}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    PONGSKILOG on Facebook
                                </a>{' '}
                                with the preferred name, submission date and
                                review details. PONGSKILOG can remove the review
                                and its images. We handle personal information
                                in line with the Philippine Data Privacy Act.
                            </p>
                        </>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
