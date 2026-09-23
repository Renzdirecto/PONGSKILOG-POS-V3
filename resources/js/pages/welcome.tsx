import { Head, Link, usePage } from '@inertiajs/react';
import { dashboard, login } from '@/routes';

/** The public landing page, also where signing out ends: the official Pongskilog logo and one way in. */
export default function Welcome() {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Welcome" />
            <main className="flex min-h-svh flex-col items-center justify-center gap-8 bg-black px-4 py-10">
                <img
                    src="/images/branding/icons/icon-512.png"
                    alt="Pongskilog — Est. 2022"
                    className="aspect-square w-full max-w-[min(360px,72vw)]"
                />
                <Link
                    href={auth.user ? dashboard() : login()}
                    className="inline-flex min-h-12 items-center justify-center rounded-xl bg-white px-8 text-[15px] font-semibold text-[#111] hover:bg-neutral-200 focus-visible:ring-2 focus-visible:ring-white focus-visible:ring-offset-2 focus-visible:ring-offset-black focus-visible:outline-none"
                >
                    {auth.user ? 'Open workspace' : 'Log in'}
                </Link>
            </main>
        </>
    );
}
