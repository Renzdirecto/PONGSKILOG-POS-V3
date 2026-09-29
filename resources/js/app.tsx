import { createInertiaApp } from '@inertiajs/react';
import { PwaRuntime } from '@/components/pwa-runtime';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { AppearanceLock } from '@/components/appearance-lock';
import { initializeTheme } from '@/hooks/use-appearance';
import AccountLayout from '@/layouts/account-layout';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import LoginLayout from '@/layouts/auth/login-layout';
import WorkspaceLayout from '@/layouts/workspace-layout';
import { captureInstallPrompt } from '@/lib/pwa-runtime';
import type { ReleaseInfo } from '@/lib/release';
import { configureEcho } from '@laravel/echo-react';

configureEcho({
    broadcaster: 'reverb',
});

captureInstallPrompt();

const appName = import.meta.env.VITE_APP_NAME || 'Pongskilog';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
            case name.startsWith('qr/'):
            case name === 'public-receipt':
            case name === 'workspaces/customer-display':
            case name === 'customer-screen':
            case name === 'pickup':
                return null;
            case name === 'auth/login':
                return LoginLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return AccountLayout;
            case name.startsWith('branches/'):
            case name.startsWith('catalog/'):
            case name.startsWith('inventory/'):
            case name.startsWith('operations/'):
            case name.startsWith('super-admin/'):
            case name.startsWith('workspaces/'):
                return WorkspaceLayout;
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app, { page }) {
        const props = page.props as {
            auth?: { user?: unknown };
            release?: ReleaseInfo;
        };

        return (
            <TooltipProvider delayDuration={0}>
                <AppearanceLock component={page.component} />
                {app}
                <Toaster />
                <PwaRuntime
                    component={page.component}
                    signedIn={Boolean(props.auth?.user)}
                    release={props.release ?? null}
                />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
