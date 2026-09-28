import { Form, Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { workspace } from '@/routes';
import { store } from '@/routes/password/confirm';

export default function ConfirmPassword() {
    return (
        <>
            <Head title="Confirm password" />

            <Form {...store.form()} resetOnSuccess={['password']}>
                {({ processing, errors }) => (
                    <div className="space-y-5">
                        <div className="grid gap-1.5">
                            <Label htmlFor="password">Password</Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder="Your password"
                                autoComplete="current-password"
                                className="min-h-11 rounded-xl"
                                autoFocus
                            />

                            <InputError message={errors.password} />
                        </div>

                        <Button
                            className="min-h-12 w-full rounded-xl bg-neutral-950 text-white hover:bg-black"
                            disabled={processing}
                            data-test="confirm-password-button"
                        >
                            {processing && <Spinner />}
                            Confirm password
                        </Button>

                        <Link
                            href={workspace()}
                            className="flex min-h-11 items-center justify-center gap-2 rounded-xl text-[13px] font-semibold text-neutral-600 hover:bg-neutral-100 hover:text-neutral-950"
                        >
                            <ArrowLeft className="size-4" aria-hidden="true" />
                            Back to workspace
                        </Link>
                    </div>
                )}
            </Form>
        </>
    );
}

ConfirmPassword.layout = {
    title: 'Confirm your password',
    description:
        'For your security, enter your password again before opening this area.',
};
