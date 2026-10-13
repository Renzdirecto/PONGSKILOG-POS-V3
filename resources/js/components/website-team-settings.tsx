import { router } from '@inertiajs/react';
import { ImagePlus, Trash2, UserRound } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { TeamCard } from '@/lib/landing';
import { qrError, qrRequest } from '@/lib/qr-http';
import { TEAM_LIMITS, teamCardChanged } from '@/lib/website-team';
import type { TeamCardForm } from '@/lib/website-team';
import { update } from '@/routes/workspaces/website/team';
import {
    destroy as removePhoto,
    store as uploadPhoto,
} from '@/routes/workspaces/website/team/photo';

/**
 * Website → Team: the six cards of the landing page's Meet the Team rail. A card's details are saved with its Save
 * button; a portrait is saved right away and re-encoded by the server. The landing page reads the cards on every
 * visit, so nothing here needs a deployment.
 */
export function WebsiteTeamSettings({ members }: { members: TeamCard[] }) {
    return (
        <section aria-label="Team" className="flex flex-col gap-3">
            <div className={`${ownerPanelClass} p-4 sm:p-5`}>
                <h2 className="text-lg font-bold">Landing page team</h2>
                <p className="text-muted-foreground text-sm">
                    The six cards of “Sila ang nasa likod ng silog.” An empty
                    name, bio or photo is shown on the landing page as still to
                    follow. Hide a card to leave it out.
                </p>
            </div>
            <div className="grid gap-3 lg:grid-cols-2">
                {members.map((member) => (
                    <TeamMemberEditor key={member.id} member={member} />
                ))}
            </div>
        </section>
    );
}

function TeamMemberEditor({ member }: { member: TeamCard }) {
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
                            if (image.size > 5 * 1024 * 1024) {
                                setError('Choose an image of at most 5 MB.');
                                return;
                            }
                            const payload = new FormData();
                            payload.set('image', image);
                            void run(
                                () =>
                                    qrRequest(uploadPhoto(member.id), payload),
                                'Team photo saved',
                            );
                        }}
                    />
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
                              : 'Photos: JPG, PNG or WebP up to 5 MB, saved right away and resized for the web.')}
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
