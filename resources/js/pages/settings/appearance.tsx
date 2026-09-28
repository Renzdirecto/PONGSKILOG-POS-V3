import { Head, usePage } from '@inertiajs/react';
import { AccountSection } from '@/components/account-section';
import AppearanceTabs from '@/components/appearance-tabs';
import { releaseLabel } from '@/lib/release';
import type { ReleaseInfo } from '@/lib/release';

/** Light (default) or Dark on this device, and which PONGSKILOG release it is running. */
export default function Appearance() {
    const { release } = usePage<{ release: ReleaseInfo }>().props;

    return (
        <>
            <Head title="Appearance" />
            <h1 className="sr-only">Appearance</h1>

            <AccountSection
                id="appearance"
                title="Appearance"
                description="Saved on this device only. Customer-facing screens (menu, customer display, receipts) always stay Light."
            >
                <AppearanceTabs />
            </AccountSection>

            <AccountSection
                id="about"
                title="About this app"
                description="Share this with support so they know exactly which release this device runs."
            >
                <p className="rounded-xl bg-neutral-100 px-3.5 py-3 text-sm font-semibold tabular-nums">
                    {release?.name ?? 'PONGSKILOG POS'} ·{' '}
                    {releaseLabel(release)}
                </p>
            </AccountSection>
        </>
    );
}
