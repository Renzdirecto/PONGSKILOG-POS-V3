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
    advanceReviewScroll,
    reviewCharacterCount,
    reviewFileError,
} from '@/lib/customer-reviews';
import type { CustomerReview, ReviewBranch } from '@/lib/customer-reviews';
import { qrError } from '@/lib/qr-http';
import { FB } from '@/lib/landing';
import { store } from '@/routes/reviews';

export function ReviewCard({
    review,
    management = false,
}: {
    review: CustomerReview;
    management?: boolean;
}) {
    const [expanded, setExpanded] = useState(management);
    const avatar = review.images.find((image) => image.kind === 'profile');
    const photos = review.images.filter((image) => image.kind === 'review');
    const long = reviewCharacterCount(review.description) > 120;
    return (
        <article className="review-card">
            <div className="review-ticket">
                <span>Customer review</span>
                <time dateTime={review.date}>{review.date}</time>
            </div>
            <div className="review-body">
                <div
                    className="review-stars"
                    role="img"
                    aria-label={`${review.rating} out of 5 stars`}
                >
                    {'\u2605'.repeat(review.rating)}
                    <span aria-hidden="true">
                        {'\u2606'.repeat(5 - review.rating)}
                    </span>
                </div>
                <p className="review-quote">
                    “
                    {long && !expanded
                        ? `${Array.from(review.description).slice(0, 120).join('')}\u2026`
                        : review.description}
                    ”
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
                            <a
                                key={image.url}
                                href={image.url}
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label={`Open review photo ${index + 1}`}
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
                            </a>
                        ))}
                    </div>
                )}
                {review.purchased_items && (
                    <div className="review-purchases">
                        <span>Ordered - self-reported</span>
                        <p>{review.purchased_items}</p>
                    </div>
                )}
                {review.visit_type && (
                    <small>
                        {review.visit_type === 'dine_in'
                            ? 'Dine-in'
                            : 'Takeout'}
                    </small>
                )}
            </div>
            <div className="review-author">
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
                <strong>{review.preferred_name}</strong>
            </div>
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
                        if (
                            event.pointerType === 'mouse' &&
                            event.button === 0 &&
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
                    public reviews appear here after Owner approval.
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
    const [saved, setSaved] = useState(false);
    const [profileError, setProfileError] = useState('');
    const [imagesError, setImagesError] = useState('');
    const fileError = profileError || imagesError;
    const sending = useRef(false);
    const invalid = (key: keyof ReviewForm) =>
        Boolean(form.errors[key]) || !form.data[key];
    const error = (key: keyof ReviewForm) =>
        form.errors[key] ? (
            <small role="alert" className="review-field-error">
                {form.errors[key]}
            </small>
        ) : null;
    return (
        <>
            <Dialog
                open
                onOpenChange={(value) => {
                    if (!value && !form.processing) onClose();
                }}
            >
                <DialogContent className="review-dialog max-h-[90svh] overflow-y-auto sm:max-w-[560px]">
                    <DialogHeader>
                        <DialogTitle>
                            {saved ? 'Salamat, suki!' : 'How was your visit?'}
                        </DialogTitle>
                        <DialogDescription>
                            {saved
                                ? form.data.visibility === 'public'
                                    ? 'Your review was received and is waiting for Owner approval.'
                                    : 'Your feedback was sent privately to the Owner. It will never appear on the landing page.'
                                : 'Share a quick, honest review. No login needed.'}
                        </DialogDescription>
                    </DialogHeader>
                    {saved ? (
                        <button
                            type="button"
                            className="review-primary"
                            onClick={onClose}
                        >
                            Done
                        </button>
                    ) : (
                        <form
                            className="review-form"
                            onSubmit={async (event) => {
                                event.preventDefault();
                                if (
                                    sending.current ||
                                    form.processing ||
                                    fileError
                                )
                                    return;
                                sending.current = true;
                                setFeedback('');
                                try {
                                    await form.post(store.url());
                                    setSaved(true);
                                } catch (failure) {
                                    const result = qrError(failure);
                                    setFeedback(
                                        result.status === 422
                                            ? ''
                                            : result.status === 429
                                              ? 'Too many attempts. Please wait before trying again.'
                                              : result.message,
                                    );
                                } finally {
                                    sending.current = false;
                                }
                            }}
                        >
                            {branches.length !== 1 && (
                                <label>
                                    Branch{' '}
                                    <span className="review-required">
                                        Required
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
                            <fieldset
                                aria-invalid={invalid('rating')}
                                className="review-rating"
                            >
                                <legend>
                                    Your rating{' '}
                                    <span className="review-required">
                                        Required
                                    </span>
                                </legend>
                                <div role="radiogroup" aria-label="Star rating">
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
                                                    event.key === 'ArrowLeft'
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
                                            style={{
                                                color:
                                                    star <= form.data.rating
                                                        ? '#8A5E12'
                                                        : '#b8b1a6',
                                            }}
                                        >
                                            ★
                                        </button>
                                    ))}
                                </div>
                                {error('rating')}
                            </fieldset>
                            <label>
                                Preferred name{' '}
                                <span className="review-required">
                                    Required
                                </span>
                                <Input
                                    required
                                    autoComplete="nickname"
                                    maxLength={60}
                                    value={form.data.preferred_name}
                                    aria-invalid={invalid('preferred_name')}
                                    onChange={(event) =>
                                        form.setData(
                                            'preferred_name',
                                            event.target.value,
                                        )
                                    }
                                />
                                {error('preferred_name')}
                            </label>
                            <label>
                                Your review{' '}
                                <span className="review-required">
                                    Required
                                </span>
                                <textarea
                                    required
                                    rows={3}
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
                                <small id="review-character-counter">
                                    {reviewCharacterCount(
                                        form.data.description,
                                    )}
                                    /200 characters
                                </small>
                                {error('description')}
                            </label>
                            <div className="review-form-grid">
                                <label>
                                    Profile photo{' '}
                                    <small>Optional - max 5 MB</small>
                                    <input
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        onChange={(event) => {
                                            const files = Array.from(
                                                event.target.files ?? [],
                                            );
                                            const message = reviewFileError(
                                                files,
                                                1,
                                            );
                                            setProfileError(message ?? '');
                                            if (!message)
                                                form.setData(
                                                    'profile_photo',
                                                    files[0] ?? null,
                                                );
                                        }}
                                    />
                                    {error('profile_photo')}
                                </label>
                                <label>
                                    Review images{' '}
                                    <small>
                                        Optional - up to 3 - 5 MB each
                                    </small>
                                    <input
                                        type="file"
                                        multiple
                                        accept="image/jpeg,image/png,image/webp"
                                        onChange={(event) => {
                                            const files = Array.from(
                                                event.target.files ?? [],
                                            );
                                            const message = reviewFileError(
                                                files,
                                                3,
                                            );
                                            setImagesError(message ?? '');
                                            if (!message)
                                                form.setData(
                                                    'review_images',
                                                    files,
                                                );
                                        }}
                                    />
                                    {error('review_images')}
                                </label>
                            </div>
                            <label>
                                Purchased items{' '}
                                <small>
                                    Optional - self-reported, not verified
                                </small>
                                <Input
                                    maxLength={200}
                                    value={form.data.purchased_items}
                                    onChange={(event) =>
                                        form.setData(
                                            'purchased_items',
                                            event.target.value,
                                        )
                                    }
                                />
                                {error('purchased_items')}
                            </label>
                            <label>
                                Visit type <small>Optional</small>
                                <select
                                    value={form.data.visit_type}
                                    onChange={(event) =>
                                        form.setData(
                                            'visit_type',
                                            event.target.value,
                                        )
                                    }
                                >
                                    <option value="">Prefer not to say</option>
                                    <option value="dine_in">Dine-in</option>
                                    <option value="takeout">Takeout</option>
                                </select>
                            </label>
                            <fieldset>
                                <legend>Who can see this?</legend>
                                <div className="review-visibility">
                                    {[
                                        ['public', 'Public Review'],
                                        [
                                            'private',
                                            'Private Feedback for Owner',
                                        ],
                                    ].map(([value, label]) => (
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
                                            {label}
                                        </label>
                                    ))}
                                </div>
                                <small>
                                    {form.data.visibility === 'public'
                                        ? 'Published only after Owner approval.'
                                        : 'Only the authorized Owner can read this feedback and its images.'}
                                </small>
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
                                            checked={form.data.public_consent}
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
                                            I consent to public display of my
                                            preferred name, rating, review,
                                            optional photos and purchased items
                                            after approval.
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
                                    'description',
                                    'profile_photo',
                                    'review_images',
                                    'purchased_items',
                                    'acknowledgment',
                                    'public_consent',
                                ]}
                            />
                            {fileError && (
                                <p role="alert" className="review-field-error">
                                    {fileError}
                                </p>
                            )}
                            {feedback && (
                                <p role="alert" className="review-field-error">
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
                                    form.processing ||
                                    Boolean(fileError) ||
                                    branches.length === 0
                                }
                            >
                                {form.processing
                                    ? 'Sending...'
                                    : 'Submit review'}
                            </button>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
            <Dialog
                open={legal !== null}
                onOpenChange={(value) => {
                    if (!value) setLegal(null);
                }}
            >
                <DialogContent className="review-dialog max-h-[85svh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {legal === 'terms'
                                ? 'Terms & Conditions'
                                : 'Privacy Notice'}
                        </DialogTitle>
                        <DialogDescription>
                            Review notice {noticeVersion}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="review-legal">
                        {legal === 'terms' ? (
                            <>
                                <p>
                                    Share an honest experience of your own
                                    visit. Use a preferred name; do not include
                                    phone numbers, addresses, payment details,
                                    harassment, advertising or other people’s
                                    personal information.
                                </p>
                                <p>
                                    Upload only photos you own and have
                                    permission to share. Purchased items are
                                    self-reported and are never marked as
                                    verified purchases.
                                </p>
                                <p>
                                    Public reviews require your separate consent
                                    and Owner approval. The Owner may reject
                                    inappropriate content. Private feedback
                                    cannot be published; public sharing would
                                    require a new submission with new
                                    permission.
                                </p>
                            </>
                        ) : (
                            <>
                                <p>
                                    PONGSKILOG collects your preferred name,
                                    rating, review, optional visit details and
                                    photos to receive feedback and, when you
                                    consent, display approved reviews publicly.
                                    No account is required.
                                </p>
                                <p>
                                    Only authorized Owner users can read private
                                    feedback and moderate submissions. Photos
                                    are re-encoded, resized and stored privately
                                    in object storage; originals and embedded
                                    photo metadata are discarded. Publicly
                                    displayed content can be copied by visitors.
                                </p>
                                <p>
                                    We record acknowledgment time and notice
                                    version. Session identifiers are hashed for
                                    duplicate and spam prevention; request
                                    addresses are used for short-lived rate
                                    limits, not saved in review records.
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
                                    review details. The Owner can remove the
                                    review and its images. We handle personal
                                    information in line with the Philippine Data
                                    Privacy Act.
                                </p>
                            </>
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
