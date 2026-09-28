import { Form, Head, router, usePage } from '@inertiajs/react';
import { Camera, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import { AccountSection } from '@/components/account-section';
import InputError from '@/components/input-error';
import { PersonAvatar } from '@/components/person-avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { Auth } from '@/types';

type Identity = {
    name: string;
    email: string;
    employee_id: string | null;
    position: string | null;
    branches: string[];
};

/**
 * The account's own profile (Phase 20): a Preferred Name and a profile photo are the staff member's to choose;
 * everything that identifies them in the business is read-only here and managed in Staff administration.
 */
export default function Profile({
    identity,
    preferredName,
}: {
    identity: Identity;
    preferredName: string | null;
}) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const fileInput = useRef<HTMLInputElement>(null);
    const [photoBusy, setPhotoBusy] = useState(false);
    const [photoError, setPhotoError] = useState('');

    function uploadPhoto(file: File) {
        setPhotoError('');
        router.post(
            ProfileController.updateAvatar.url(),
            { avatar: file },
            {
                forceFormData: true,
                preserveScroll: true,
                onStart: () => setPhotoBusy(true),
                onError: (errors) =>
                    setPhotoError(
                        errors.avatar ??
                            'The photo could not be saved. Try again.',
                    ),
                onFinish: () => {
                    setPhotoBusy(false);
                    if (fileInput.current) {
                        fileInput.current.value = '';
                    }
                },
            },
        );
    }

    function removePhoto() {
        setPhotoError('');
        router.delete(ProfileController.destroyAvatar.url(), {
            preserveScroll: true,
            onStart: () => setPhotoBusy(true),
            onFinish: () => setPhotoBusy(false),
        });
    }

    const details: [string, string][] = [
        ['Full name', identity.name],
        ['Sign-in e-mail', identity.email],
        ['Employee ID', identity.employee_id ?? '—'],
        ['Position', identity.position ?? '—'],
        ['Role', auth.roleLabel ?? '—'],
        [
            'Branches',
            identity.branches.length > 0
                ? identity.branches.join(', ')
                : 'All Branches (business-wide)',
        ],
    ];

    return (
        <>
            <Head title="Profile" />
            <h1 className="sr-only">Profile</h1>

            <AccountSection
                id="profile-photo"
                title="Profile photo"
                description="Shown on your account menu and Staff card. JPG, PNG or WebP up to 2 MB."
            >
                <div className="flex flex-wrap items-center gap-4">
                    <PersonAvatar
                        name={auth.user?.displayName}
                        avatarUrl={auth.user?.avatarUrl}
                        className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-full bg-neutral-950 text-xl font-bold text-white"
                    />
                    <div className="flex flex-wrap gap-2">
                        {/* A picker, not camera-only: phones still offer Camera alongside the gallery. */}
                        <input
                            ref={fileInput}
                            type="file"
                            accept="image/jpeg,image/png,image/webp"
                            className="sr-only"
                            aria-label="Choose a profile photo"
                            onChange={(event) => {
                                const file = event.target.files?.[0];
                                if (file) {
                                    uploadPhoto(file);
                                }
                            }}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            disabled={photoBusy}
                            onClick={() => fileInput.current?.click()}
                            className="min-h-11 rounded-xl"
                        >
                            {photoBusy ? (
                                <Spinner />
                            ) : (
                                <Camera className="size-4" aria-hidden="true" />
                            )}
                            {auth.user?.avatarUrl ? 'Change photo' : 'Add photo'}
                        </Button>
                        {auth.user?.avatarUrl && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={photoBusy}
                                onClick={removePhoto}
                                className="min-h-11 rounded-xl text-red-700"
                            >
                                <Trash2 className="size-4" aria-hidden="true" />
                                Remove
                            </Button>
                        )}
                    </div>
                </div>
                <InputError message={photoError} className="mt-2" />
            </AccountSection>

            <AccountSection
                id="preferred-name"
                title="Preferred name"
                description="The friendly name on your screens and, when your Branch shows the cashier, on customer receipts. Your full name stays on business records and the audit trail."
            >
                <Form
                    {...ProfileController.update.form()}
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-3"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="preferred_name">
                                    Preferred name (optional)
                                </Label>
                                <Input
                                    id="preferred_name"
                                    name="preferred_name"
                                    defaultValue={preferredName ?? ''}
                                    maxLength={60}
                                    autoComplete="nickname"
                                    placeholder={
                                        identity.name.split(' ')[0] ?? ''
                                    }
                                    aria-invalid={Boolean(errors.preferred_name)}
                                    className="min-h-11 rounded-xl"
                                />
                                <p className="text-[11.5px] text-neutral-500">
                                    Leave it blank to use your first name on
                                    receipts and your full name on screens.
                                </p>
                                <InputError message={errors.preferred_name} />
                            </div>
                            <Button
                                disabled={processing}
                                className="min-h-11 w-full rounded-xl bg-neutral-950 text-white hover:bg-black sm:w-fit"
                                data-test="update-profile-button"
                            >
                                {processing && <Spinner />}
                                Save preferred name
                            </Button>
                        </>
                    )}
                </Form>
            </AccountSection>

            <AccountSection
                id="profile-details"
                title="Your details"
                description="Managed by your administrator in Staff administration. Ask a Super Admin or Owner if something is wrong."
            >
                <dl className="divide-y divide-neutral-100 overflow-hidden rounded-xl border border-neutral-200">
                    {details.map(([label, value]) => (
                        <div
                            key={label}
                            className="grid gap-0.5 px-3.5 py-2.5 sm:grid-cols-[150px_minmax(0,1fr)] sm:gap-3"
                        >
                            <dt className="text-[11px] font-semibold tracking-wide text-neutral-500 uppercase">
                                {label}
                            </dt>
                            <dd className="text-sm font-medium wrap-anywhere">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
            </AccountSection>
        </>
    );
}
