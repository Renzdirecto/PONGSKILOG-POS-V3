import type { AuthLayoutProps } from '@/types';

export default function LoginLayout({ children }: AuthLayoutProps) {
    return (
        <main className="min-h-svh bg-[#14110e] lg:grid lg:grid-cols-[minmax(0,1.1fr)_minmax(27rem,0.9fr)]">
            <section className="relative flex min-h-64 overflow-hidden bg-[#14110e] px-5 py-6 sm:min-h-80 sm:px-8 lg:min-h-svh lg:items-center lg:px-12 lg:py-12 xl:px-16">
                <div className="absolute inset-0 bg-[radial-gradient(circle_at_55%_35%,rgba(124,76,34,0.38),transparent_48%)]" />
                <div className="relative mx-auto flex w-full max-w-2xl flex-col justify-center gap-4 lg:gap-5">
                    <div className="overflow-hidden rounded-2xl border border-white/10 bg-black/20 shadow-2xl shadow-black/40 sm:rounded-3xl">
                        <img
                            src="/images/branding/big-logo.png"
                            alt="PONGSKILOG chef tossing silog rice in a wok"
                            className="aspect-[16/9] w-full object-cover object-[center_45%] sm:aspect-[4/3] lg:aspect-square"
                            decoding="async"
                        />
                    </div>
                    <div className="hidden gap-3 text-white sm:flex sm:flex-col">
                        <div className="flex items-center gap-3 text-[0.68rem] font-semibold tracking-[0.2em] text-[#c9b495] uppercase">
                            <span className="h-px w-8 bg-[#b58b52]" />
                            Est. 2022 · Quezon City
                        </div>
                        <p className="text-base font-semibold text-white/90 lg:text-lg">
                            Multi-branch silog operations, one terminal at a
                            time.
                        </p>
                    </div>
                </div>
            </section>

            <section className="flex min-h-[calc(100svh-16rem)] items-center bg-[#faf9f6] px-5 py-10 sm:min-h-[calc(100svh-20rem)] sm:px-10 lg:min-h-svh lg:px-12 xl:px-20">
                <div className="mx-auto flex w-full max-w-[27rem] flex-col gap-6">
                    <header className="space-y-1">
                        <p className="text-lg font-bold tracking-tight text-neutral-950">
                            PONGSKILOG POS
                        </p>
                        <p className="text-[0.67rem] font-semibold tracking-[0.18em] text-[#8c671e] uppercase">
                            Multi-branch restaurant operations
                        </p>
                    </header>

                    <div className="rounded-2xl border border-neutral-200/90 bg-white p-6 shadow-[0_18px_45px_rgba(28,25,23,0.08)] sm:p-8">
                        {children}
                    </div>

                    <p className="text-center text-[0.65rem] font-semibold tracking-[0.2em] text-neutral-400 uppercase">
                        Powered by <span className="text-[#8c671e]">Sebby</span>
                    </p>
                </div>
            </section>
        </main>
    );
}
