import { Form, Head } from '@inertiajs/react';
import { useRef } from 'react';
import SecurityController from '@/actions/App/Http/Controllers/Settings/SecurityController';
import { AccountSection } from '@/components/account-section';
import InputError from '@/components/input-error';
import type { Props as ManageTwoFactorProps } from '@/components/manage-two-factor';
import ManageTwoFactor from '@/components/manage-two-factor';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

// oxfmt-ignore
type Props = {
    passwordRules: string;
} &
    ManageTwoFactorProps;

/** Password and two-factor authentication for the signed-in account (behind a recent password confirmation). */
export default function Security(props: Props) {
    const passwordInput = useRef<HTMLInputElement>(null);
    const currentPasswordInput = useRef<HTMLInputElement>(null);

    return (
        <>
            <Head title="Security" />
            <h1 className="sr-only">Security</h1>

            <AccountSection
                id="password"
                title="Password"
                description="Use a long password you do not use anywhere else. Changing it signs out your other devices."
            >
                <Form
                    {...SecurityController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    resetOnError={[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]}
                    resetOnSuccess
                    onError={(errors) => {
                        if (errors.password) {
                            passwordInput.current?.focus();
                        }

                        if (errors.current_password) {
                            currentPasswordInput.current?.focus();
                        }
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ errors, processing }) => (
                        <>
                            <div className="grid gap-1.5">
                                <Label htmlFor="current_password">
                                    Current password
                                </Label>
                                <PasswordInput
                                    id="current_password"
                                    ref={currentPasswordInput}
                                    name="current_password"
                                    className="min-h-11 rounded-xl"
                                    autoComplete="current-password"
                                    placeholder="Current password"
                                />
                                <InputError message={errors.current_password} />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="password">New password</Label>
                                <PasswordInput
                                    id="password"
                                    ref={passwordInput}
                                    name="password"
                                    className="min-h-11 rounded-xl"
                                    autoComplete="new-password"
                                    placeholder="New password"
                                    passwordrules={props.passwordRules}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="password_confirmation">
                                    Confirm new password
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    name="password_confirmation"
                                    className="min-h-11 rounded-xl"
                                    autoComplete="new-password"
                                    placeholder="Confirm new password"
                                    passwordrules={props.passwordRules}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                disabled={processing}
                                data-test="update-password-button"
                                className="min-h-11 w-full rounded-xl bg-neutral-950 text-white hover:bg-black sm:w-fit"
                            >
                                {processing && <Spinner />}
                                Change password
                            </Button>
                        </>
                    )}
                </Form>
            </AccountSection>

            {props.canManageTwoFactor && (
                <AccountSection
                    id="two-factor"
                    title="Two-factor authentication"
                    description="Ask for a code from an authenticator app on your phone whenever you sign in."
                >
                    <ManageTwoFactor
                        canManageTwoFactor={props.canManageTwoFactor}
                        requiresConfirmation={props.requiresConfirmation}
                        twoFactorEnabled={props.twoFactorEnabled}
                        embedded
                    />
                </AccountSection>
            )}
        </>
    );
}
