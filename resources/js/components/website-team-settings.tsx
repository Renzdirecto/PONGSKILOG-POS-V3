import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ImagePlus,
    Plus,
    Trash2,
    UserRound,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    controlClass,
    primaryActionClass,
} from '@/components/catalog-ui';
import { OwnerStatusBadge, ownerPanelClass } from '@/components/owner-ui';
import { SettingSwitch } from '@/components/receipt-settings';
import { TeamPhotoCropDialog } from '@/components/team-photo-crop-dialog';
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
import type { TeamCard } from '@/lib/landing';
import { qrError, qrRequest } from '@/lib/qr-http';
import {
    NEW_TEAM_MEMBER,
    TEAM_LIMITS,
    TEAM_MAX_MEMBERS,
    TEAM_PHOTO_SOURCE_BYTES,
    movedTeamOrder,
    teamCardChanged,
} from '@/lib/website-team';
import type { TeamCardForm } from '@/lib/website-team';
import {
    destroy,
    reorder,
    store,
    update,
} from '@/routes/workspaces/website/team';
import {
    destroy as removePhoto,
    store as uploadPhoto,
} from '@/routes/workspaces/website/team/photo';

const TOO_LARGE = 'Choose a photo of at most 20 MB.';

/**
 * Website → Team: the members of the landing page's Meet the Team rail, in the order the page shows them. Members are
 * added, edited, hidden, moved earlier or later and deleted here. A card's details are saved with its Save button; a
 * portrait, a move and a deletion are saved right away. The landing page reads the team on every visit, so nothing
 * here needs a deployment.
 */
export function WebsiteTeamSettings({ members }: { members: TeamCard[] }) {
    const [adding, setAdding] = useState(false);
    const [removing, setRemoving] = useState<TeamCard | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const full = members.length >= TEAM_MAX_MEMBERS;
    const run = async (request: () => Promise<unknown>, message: string) => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            await request();
            toast.success(message);
            router.reload({ only: ['team'], onFinish: () => setBusy(false) });
        } catch (reason) {
            setError(qrError(reason).message);
            setBusy(false);
        }
    };
    const move = (member: TeamCard, direction: -1 | 1) => {
        const ids = movedTeamOrder(
            members.map(({ id }) => id),
            member.id,
            direction,
        );
        if (ids)
            void run(() => qrRequest(reorder(), { ids }), 'Team order saved');
    };

    return (
        <section aria-label="Team" className="flex flex-col gap-3">
            <div
                className={`${ownerPanelClass} flex flex-wrap items-start justify-between gap-3 p-4 sm:p-5`}
            >
                <div className="min-w-[220px] flex-1">
                    <h2 className="text-lg font-bold">Landing page team</h2>
                    <p className="text-muted-foreground text-sm">
                        The people of “Sila ang nasa likod ng silog,” in the
                        order the landing page shows them. An empty name, bio or
                        photo is shown there as still to follow. Hide a member
                        to leave them out without deleting them.
                    </p>
                    <p
                        role={error ? 'alert' : undefined}
                        className={`mt-1 text-xs ${error ? 'font-semibold text-red-700' : 'text-neutral-600'}`}
                    >
                        {error ||
                            `${members.length} of up to ${TEAM_MAX_MEMBERS} members.`}
                    </p>
                </div>
                <Button
                    type="button"
                    className={`${primaryActionClass} min-h-11`}
                    disabled={busy || full}
                    onClick={() => setAdding(true)}
                >
                    <Plus className="size-3.5" aria-hidden="true" /> Add team
                    member
                </Button>
            </div>
            <div className="grid gap-3 lg:grid-cols-2">
                {members.map((member, index) => (
                    <TeamMemberEditor
                        key={member.id}
                        member={member}
                        place={{
                            position: index + 1,
                            total: members.length,
                            locked: busy,
                            onMove: (direction) => move(member, direction),
                            onRemove: () => setRemoving(member),
                        }}
                    />
                ))}
            </div>
            {adding && <AddTeamMemberDialog onClose={() => setAdding(false)} />}
            <Dialog
                open={removing !== null}
                onOpenChange={(value) => {
                    if (!value && !busy) setRemoving(null);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete this team member?</DialogTitle>
                        <DialogDescription>
                            {removing?.name ?? removing?.title} (
                            {removing?.code}) leaves the landing page and their
                            uploaded photo is deleted. This cannot be undone. To
                            only leave them out for now, hide the card instead.
                        </DialogDescription>
                    </DialogHeader>
                    <Button
                        variant="destructive"
                        disabled={busy}
                        onClick={() => {
                            if (!removing) return;
                            const id = removing.id;
                            void run(
                                () => qrRequest(destroy(id)),
                                'Team member deleted',
                            ).then(() => setRemoving(null));
                        }}
                    >
                        {busy ? 'Deleting...' : 'Delete team member'}
                    </Button>
                </DialogContent>
            </Dialog>
        </section>
    );
}

/** Where a card sits in the team and what the list does when it is moved or deleted. */
type TeamPlace = {
    position: number;
    total: number;
    locked: boolean;
    onMove: (direction: -1 | 1) => void;
    onRemove: () => void;
};

function TeamMemberEditor({
    member,
    place,
}: {
    member: TeamCard;
    place?: TeamPlace;
}) {
    const file = useRef<HTMLInputElement>(null);
    const [form, setForm] = useState<TeamCardForm>({
        role_label: member.code,
        role_title: member.title,
        name: member.name ?? '',
        bio: member.bio ?? '',
        is_visible: member.visible,
    });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    /** The photo being framed before it is uploaded. */
    const [framing, setFraming] = useState<File | null>(null);
    const changed = teamCardChanged(form, member);
    const incomplete = !form.role_label.trim() || !form.role_title.trim();
    const field = (name: string) => `team-${member.id}-${name}`;
    const set = (values: Partial<TeamCardForm>) =>
        setForm((current) => ({ ...current, ...values }));
    const run = async (request: () => Promise<unknown>, message: string) => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            await request();
            toast.success(message);
            router.reload({ only: ['team'], onFinish: () => setBusy(false) });
        } catch (reason) {
            setError(qrError(reason).message);
            setBusy(false);
        }
    };

    return (
        <form
            aria-label={`${member.code} card`}
            className={`${ownerPanelClass} flex flex-col gap-4 p-4 sm:p-5`}
            onSubmit={(event) => {
                event.preventDefault();
                if (incomplete) return;
                void run(
                    () => qrRequest(update(member.id), form),
                    'Team card saved',
                );
            }}
        >
            <div className="flex items-center justify-between gap-2">
                <h3 className="text-sm font-bold tracking-[0.08em] uppercase">
                    {member.code}
                </h3>
                <OwnerStatusBadge tone={member.visible ? 'green' : 'neutral'}>
                    {member.visible ? 'On landing page' : 'Hidden'}
                </OwnerStatusBadge>
            </div>
            {place && (
                <div className="flex flex-wrap items-center gap-2">
                    <span className="mr-auto text-xs text-neutral-600">
                        Place {place.position} of {place.total}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        className={`${actionClass} min-h-11`}
                        aria-label={`Move the ${member.code} card earlier`}
                        disabled={busy || place.locked || place.position === 1}
                        onClick={() => place.onMove(-1)}
                    >
                        <ArrowUp className="size-3.5" aria-hidden="true" />{' '}
                        Earlier
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className={`${actionClass} min-h-11`}
                        aria-label={`Move the ${member.code} card later`}
                        disabled={
                            busy ||
                            place.locked ||
                            place.position === place.total
                        }
                        onClick={() => place.onMove(1)}
                    >
                        <ArrowDown className="size-3.5" aria-hidden="true" />{' '}
                        Later
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className={`${actionClass} min-h-11 text-red-700`}
                        aria-label={`Delete the ${member.code} card`}
                        disabled={busy || place.locked}
                        onClick={place.onRemove}
                    >
                        <Trash2 className="size-3.5" aria-hidden="true" />{' '}
                        Delete
                    </Button>
                </div>
            )}
            <div className="flex flex-wrap gap-4">
                <div className="flex w-[132px] shrink-0 flex-col gap-2">
                    {/* The landing card is a 3:4 portrait that fills its frame, so this is the crop a visitor sees. */}
                    <div className="relative aspect-[3/4] overflow-hidden rounded-[14px] border border-neutral-200 bg-[#f4f0e8]">
                        {member.img ? (
                            <img
                                src={member.img}
                                alt={`${member.name ?? member.title} portrait`}
                                className="absolute inset-0 size-full object-cover"
                            />
                        ) : (
                            <div className="absolute inset-0 flex flex-col items-center justify-center gap-1.5 px-2 text-center text-[11px] font-semibold text-neutral-500">
                                <UserRound
                                    className="size-7"
                                    aria-hidden="true"
                                />
                                Photo to follow
                            </div>
                        )}
                    </div>
                    <input
                        ref={file}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="hidden"
                        aria-label={`Choose a photo for the ${member.code} card`}
                        onChange={(event) => {
                            const image = event.target.files?.[0];
                            event.target.value = '';
                            if (!image) return;
                            if (image.size > TEAM_PHOTO_SOURCE_BYTES) {
                                setError(TOO_LARGE);
                                return;
                            }
                            setError('');
                            setFraming(image);
                        }}
                    />
                    {framing && (
                        <TeamPhotoCropDialog
                            file={framing}
                            card={{
                                code: form.role_label,
                                title: form.role_title,
                                name: form.name,
                                bio: form.bio,
                            }}
                            saveLabel="Save photo"
                            onCancel={() => setFraming(null)}
                            onCropped={(photo) => {
                                setFraming(null);
                                const payload = new FormData();
                                payload.set('image', photo);
                                void run(
                                    () =>
                                        qrRequest(
                                            uploadPhoto(member.id),
                                            payload,
                                        ),
                                    'Team photo saved',
                                );
                            }}
                        />
                    )}
                    <Button
                        type="button"
                        variant="outline"
                        className={`${actionClass} min-h-11`}
                        disabled={busy}
                        onClick={() => file.current?.click()}
                    >
                        {busy ? (
                            <Spinner />
                        ) : (
                            <ImagePlus className="size-3.5" />
                        )}
                        {member.img ? 'Change photo' : 'Upload photo'}
                    </Button>
                    {member.img && (
                        <Button
                            type="button"
                            variant="outline"
                            className={`${actionClass} min-h-11`}
                            disabled={busy}
                            onClick={() =>
                                void run(
                                    () => qrRequest(removePhoto(member.id)),
                                    'Team photo removed',
                                )
                            }
                        >
                            <Trash2 className="size-3.5" /> Remove photo
                        </Button>
                    )}
                </div>
                <div className="flex min-w-[200px] flex-1 flex-col gap-3">
                    <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-2">
                        <div className="space-y-1.5">
                            <Label htmlFor={field('label')}>Role label</Label>
                            <Input
                                id={field('label')}
                                value={form.role_label}
                                maxLength={TEAM_LIMITS.role_label}
                                required
                                aria-invalid={!form.role_label.trim()}
                                onChange={(event) =>
                                    set({ role_label: event.target.value })
                                }
                                className={`${controlClass} uppercase`}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor={field('title')}>Role</Label>
                            <Input
                                id={field('title')}
                                value={form.role_title}
                                maxLength={TEAM_LIMITS.role_title}
                                required
                                aria-invalid={!form.role_title.trim()}
                                onChange={(event) =>
                                    set({ role_title: event.target.value })
                                }
                                className={controlClass}
                            />
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor={field('name')}>Name</Label>
                        <Input
                            id={field('name')}
                            value={form.name}
                            maxLength={TEAM_LIMITS.name}
                            placeholder="Name to follow"
                            onChange={(event) =>
                                set({ name: event.target.value })
                            }
                            className={controlClass}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor={field('bio')}>
                            Short bio{' '}
                            <span className="font-normal text-neutral-500">
                                {Array.from(form.bio).length}/{TEAM_LIMITS.bio}
                            </span>
                        </Label>
                        <textarea
                            id={field('bio')}
                            value={form.bio}
                            maxLength={TEAM_LIMITS.bio}
                            rows={3}
                            placeholder="Details to follow."
                            onChange={(event) =>
                                set({ bio: event.target.value })
                            }
                            className={`${controlClass} h-auto resize-none py-2 leading-5`}
                        />
                    </div>
                </div>
            </div>
            <SettingSwitch
                checked={form.is_visible}
                disabled={busy}
                onChange={(is_visible) => set({ is_visible })}
                label="Show on landing page"
                description="Off leaves this card out of the team section. Save to apply."
            />
            <div className="flex flex-wrap items-center justify-between gap-2">
                <p
                    role={error ? 'alert' : undefined}
                    className={`text-xs ${error ? 'font-semibold text-red-700' : 'text-neutral-600'}`}
                >
                    {error ||
                        (incomplete
                            ? 'Required: a role label and a role.'
                            : changed
                              ? 'Unsaved changes.'
                              : 'Photos: JPG, PNG or WebP up to 20 MB. Frame it in the card, then it is saved right away and resized for the web.')}
                </p>
                <Button
                    type="submit"
                    className={`${primaryActionClass} min-h-11`}
                    disabled={busy || incomplete || !changed}
                >
                    {busy && <Spinner />} Save card
                </Button>
            </div>
        </form>
    );
}

/** The form for a new member: the same details as a card, with the optional portrait sent in the same request. */
function AddTeamMemberDialog({ onClose }: { onClose: () => void }) {
    const file = useRef<HTMLInputElement>(null);
    const [form, setForm] = useState<TeamCardForm>(NEW_TEAM_MEMBER);
    const [photo, setPhoto] = useState<File | null>(null);
    const [framing, setFraming] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const preview = useMemo(
        () => (photo ? URL.createObjectURL(photo) : null),
        [photo],
    );
    useEffect(
        () => () => {
            if (preview) URL.revokeObjectURL(preview);
        },
        [preview],
    );
    const incomplete = !form.role_label.trim() || !form.role_title.trim();
    const set = (values: Partial<TeamCardForm>) =>
        setForm((current) => ({ ...current, ...values }));
    const add = async () => {
        if (busy || incomplete) return;
        setBusy(true);
        setError('');
        const payload = new FormData();
        payload.set('role_label', form.role_label.trim());
        payload.set('role_title', form.role_title.trim());
        payload.set('name', form.name.trim());
        payload.set('bio', form.bio.trim());
        payload.set('is_visible', form.is_visible ? '1' : '0');
        if (photo) payload.set('image', photo);
        try {
            await qrRequest(store(), payload);
            toast.success('Team member added');
            router.reload({ only: ['team'], onFinish: onClose });
        } catch (reason) {
            setError(qrError(reason).message);
            setBusy(false);
        }
    };

    return (
        <Dialog
            open
            onOpenChange={(value) => {
                if (!value && !busy) onClose();
            }}
        >
            <DialogContent className="max-h-[90svh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>Add team member</DialogTitle>
                    <DialogDescription>
                        The new member joins the end of the team. A name, bio
                        and photo can also be added later.
                    </DialogDescription>
                </DialogHeader>
                <form
                    aria-label="New team member"
                    className="flex flex-col gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void add();
                    }}
                >
                    <div className="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)] gap-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="team-new-label">Role label</Label>
                            <Input
                                id="team-new-label"
                                value={form.role_label}
                                maxLength={TEAM_LIMITS.role_label}
                                required
                                placeholder="CHEF"
                                aria-invalid={!form.role_label.trim()}
                                onChange={(event) =>
                                    set({ role_label: event.target.value })
                                }
                                className={`${controlClass} uppercase`}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="team-new-title">Role</Label>
                            <Input
                                id="team-new-title"
                                value={form.role_title}
                                maxLength={TEAM_LIMITS.role_title}
                                required
                                placeholder="Head Chef"
                                aria-invalid={!form.role_title.trim()}
                                onChange={(event) =>
                                    set({ role_title: event.target.value })
                                }
                                className={controlClass}
                            />
                        </div>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="team-new-name">Name</Label>
                        <Input
                            id="team-new-name"
                            value={form.name}
                            maxLength={TEAM_LIMITS.name}
                            placeholder="Name to follow"
                            onChange={(event) =>
                                set({ name: event.target.value })
                            }
                            className={controlClass}
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="team-new-bio">
                            Short bio{' '}
                            <span className="font-normal text-neutral-500">
                                {Array.from(form.bio).length}/{TEAM_LIMITS.bio}
                            </span>
                        </Label>
                        <textarea
                            id="team-new-bio"
                            value={form.bio}
                            maxLength={TEAM_LIMITS.bio}
                            rows={3}
                            placeholder="Details to follow."
                            onChange={(event) =>
                                set({ bio: event.target.value })
                            }
                            className={`${controlClass} h-auto resize-none py-2 leading-5`}
                        />
                    </div>
                    <input
                        ref={file}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        className="hidden"
                        aria-label="Choose a photo for the new team member"
                        onChange={(event) => {
                            const image = event.target.files?.[0] ?? null;
                            event.target.value = '';
                            if (!image) return;
                            if (image.size > TEAM_PHOTO_SOURCE_BYTES) {
                                setError(TOO_LARGE);
                                return;
                            }
                            setError('');
                            setFraming(image);
                        }}
                    />
                    {framing && (
                        <TeamPhotoCropDialog
                            file={framing}
                            card={{
                                code: form.role_label,
                                title: form.role_title,
                                name: form.name,
                                bio: form.bio,
                            }}
                            saveLabel="Use photo"
                            onCancel={() => setFraming(null)}
                            onCropped={(framed) => {
                                setFraming(null);
                                setPhoto(framed);
                            }}
                        />
                    )}
                    <div className="flex flex-wrap items-center gap-2">
                        {preview && (
                            <img
                                src={preview}
                                alt="New member photo as framed"
                                className="h-18 w-13.5 shrink-0 rounded-lg border border-neutral-200 object-cover"
                            />
                        )}
                        <Button
                            type="button"
                            variant="outline"
                            className={`${actionClass} min-h-11`}
                            disabled={busy}
                            onClick={() => file.current?.click()}
                        >
                            <ImagePlus className="size-3.5" />
                            {photo ? 'Change photo' : 'Choose photo (optional)'}
                        </Button>
                        <span className="min-w-0 flex-1 truncate text-xs text-neutral-600">
                            {photo
                                ? 'Framed and ready.'
                                : 'JPG, PNG or WebP up to 20 MB.'}
                        </span>
                    </div>
                    <SettingSwitch
                        checked={form.is_visible}
                        disabled={busy}
                        onChange={(is_visible) => set({ is_visible })}
                        label="Show on landing page"
                        description="Off adds the member hidden, to be shown later."
                    />
                    <p
                        role={error ? 'alert' : undefined}
                        className={`text-xs ${error ? 'font-semibold text-red-700' : 'text-neutral-600'}`}
                    >
                        {error ||
                            (incomplete
                                ? 'Required: a role label and a role.'
                                : 'Ready to add.')}
                    </p>
                    <Button
                        type="submit"
                        className={`${primaryActionClass} min-h-11`}
                        disabled={busy || incomplete}
                    >
                        {busy && <Spinner />} Add team member
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}
