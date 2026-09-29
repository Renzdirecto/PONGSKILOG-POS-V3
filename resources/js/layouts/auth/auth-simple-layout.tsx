import AppLogoIcon from '@/components/app-logo-icon';
import type { AuthLayoutProps } from '@/types';

/**
 * PONGSKILOG card for the secondary sign-in screens (confirm password, forgot / reset password, two-factor code),
 * in the same warm palette as the sign-in page — never the starter-kit look.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <main className="theme-static owner-surface flex min-h-svh flex-col items-center justify-center bg-[#faf9f6] px-5 py-10 text-neutral-950 sm:px-8">
            <div className="flex w-full max-w-108 flex-col gap-6">
                <header className="flex items-center gap-3">
                    <AppLogoIcon className="size-11 shrink-0" />
                    <div className="min-w-0">
                        <p className="text-lg font-bold tracking-tight">
                            PONGSKILOG POS
                        </p>
                        <p className="text-[0.67rem] font-semibold tracking-[0.18em] text-[#8c671e] uppercase">
                            Multi-branch restaurant operations
                        </p>
                    </div>
                </header>

                <div className="rounded-2xl border border-neutral-200/90 bg-white p-6 shadow-[0_18px_45px_rgba(28,25,23,0.08)] sm:p-8">
                    <div className="mb-6 space-y-1.5">
                        <h1 className="text-xl font-bold tracking-tight">
                            {title}
                        </h1>
                        {description && (
                            <p className="text-sm leading-6 text-neutral-600">
                                {description}
                            </p>
                        )}
                    </div>
                    {children}
                </div>
            </div>
        </main>
    );
}
