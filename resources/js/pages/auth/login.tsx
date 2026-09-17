import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    return (
        <>
            <Head title="Sign in" />

            <div className="mb-7 space-y-2">
                <h1 className="text-3xl font-bold tracking-tight text-neutral-950">
                    Welcome back
                </h1>
                <p className="max-w-sm text-sm leading-6 text-neutral-500">
                    Sign in to continue to your assigned PONGSKILOG workspace.
                </p>
            </div>

            {status && (
                <div
                    role="status"
                    className="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"
                >
                    {status}
                </div>
            )}

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-5"
            >
                {({ processing, errors }) => (
                    <div className="grid gap-5">
                        <div className="grid gap-2">
                            <Label
                                htmlFor="email"
                                className="text-sm font-semibold text-neutral-800"
                            >
                                Email
                            </Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="you@pongskilog.ph"
                                className="h-12 rounded-xl border-neutral-300 bg-white px-4 text-base shadow-none focus-visible:border-neutral-900 focus-visible:ring-neutral-900/15"
                            />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label
                                htmlFor="password"
                                className="text-sm font-semibold text-neutral-800"
                            >
                                Password
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="Enter your password"
                                className="h-12 rounded-xl border-neutral-300 bg-white px-4 text-base shadow-none focus-visible:border-neutral-900 focus-visible:ring-neutral-900/15"
                            />
                            <InputError message={errors.password} />
                        </div>

                        <div className="flex min-h-11 flex-wrap items-center justify-between gap-3">
                            <div className="flex items-center gap-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                    className="size-5 rounded-md"
                                />
                                <Label
                                    htmlFor="remember"
                                    className="cursor-pointer text-sm font-medium text-neutral-700"
                                >
                                    Remember me
                                </Label>
                            </div>
                            {canResetPassword && (
                                <TextLink
                                    href={request()}
                                    className="inline-flex min-h-11 items-center text-sm font-semibold text-[#7a5819] hover:text-[#4f370d]"
                                    tabIndex={5}
                                >
                                    Forgot password?
                                </TextLink>
                            )}
                        </div>

                        <Button
                            type="submit"
                            className="h-12 w-full rounded-xl bg-neutral-950 text-base font-semibold shadow-lg shadow-neutral-950/10 hover:bg-neutral-800"
                            tabIndex={4}
                            disabled={processing}
                            data-test="login-button"
                        >
                            {processing && <Spinner />}
                            {processing ? 'Signing in…' : 'Sign in'}
                        </Button>

                        <p className="border-t border-neutral-100 pt-5 text-xs leading-5 text-neutral-500">
                            Your access and branch availability are based on
                            your assigned role.
                        </p>
                    </div>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: 'Sign in to PONGSKILOG POS',
    description: 'Use your assigned staff account to continue.',
};
