import { Link, usePage } from '@inertiajs/react';
import { LogOut } from 'lucide-react';
import { BranchSwitcher } from '@/components/branch-switcher';
import { logout } from '@/routes';
import type { Auth, BranchContext } from '@/types';

type SharedProps = {
    auth: Auth;
    branchContext: BranchContext;
};

function roleLabel(role?: string): string {
    if (!role) {
        return 'Staff';
    }

    return role
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

export default function WorkspaceLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const page = usePage<SharedProps>();
    const { auth, branchContext } = page.props;
    const isPos =
        page.component === 'workspaces/order-summary' ||
        (page.component === 'workspaces/show' &&
            auth.roles.some(
                (role) => role === 'cashier' || role === 'cashier_kitchen',
            ));

    return (
        <div className="min-h-svh bg-[#f4f4f3] text-neutral-950">
            <header className="border-b border-neutral-200 bg-white">
                <div className="mx-auto flex min-h-18 max-w-[96rem] flex-wrap items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
                    <div className="flex min-w-0 items-center gap-3 sm:mr-auto">
                        <div className="flex h-11 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-neutral-950 px-2">
                            <img
                                src="/images/branding/logo.png"
                                alt="PONGSKILOG"
                                className="h-auto w-full"
                            />
                        </div>
                        <div className="hidden sm:block">
                            <p className="text-sm font-bold tracking-[0.12em] uppercase">
                                PONGSKILOG
                            </p>
                            <p className="text-[0.62rem] font-semibold tracking-[0.17em] text-neutral-400 uppercase">
                                Operations
                            </p>
                        </div>
                    </div>

                    <div className="order-3 w-full sm:order-none sm:w-auto">
                        <BranchSwitcher branchContext={branchContext} />
                    </div>

                    {auth.user && (
                        <div className="ml-auto flex items-center gap-2 sm:ml-0">
                            <div className="hidden text-right md:block">
                                <p className="text-sm font-semibold">
                                    {auth.user.name}
                                </p>
                                <p className="text-xs text-neutral-500">
                                    {roleLabel(auth.roles[0])}
                                </p>
                            </div>
                            <Link
                                href={logout()}
                                as="button"
                                className="inline-flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-600 transition hover:bg-neutral-100 hover:text-neutral-950 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none"
                                aria-label="Log out"
                            >
                                <LogOut className="size-4" />
                            </Link>
                        </div>
                    )}
                </div>
            </header>

            <main
                className={`mx-auto w-full max-w-[96rem] ${isPos ? 'px-3 py-4 sm:px-4' : 'px-4 py-8 sm:px-6 lg:px-8 lg:py-12'}`}
            >
                {children}
            </main>
        </div>
    );
}
