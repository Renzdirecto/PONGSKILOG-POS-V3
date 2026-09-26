/**
 * Install PONGSKILOG as an app. The programmatic prompt exists only where the browser offers `beforeinstallprompt`
 * (Chromium); it is captured, never shown automatically, and used only from the Install button. Everywhere else the
 * app gives the platform's real manual steps — iPhone/iPad have no install prompt, only Safari's Add to Home Screen.
 * Platform detection is used only to pick those instructions; installed state comes from feature detection.
 */
export type InstallPlatform = 'ios' | 'android' | 'macos-safari' | 'desktop' | 'other';

export type InstallState =
    | 'installed'
    | 'available'
    | 'ios-guide'
    | 'safari-guide'
    | 'browser-menu'
    | 'insecure';

export type InstallEnvironment = {
    /** Running as an installed app (display-mode standalone/fullscreen/minimal-ui, or iOS `navigator.standalone`). */
    standalone: boolean;
    /** A deferred `beforeinstallprompt` is ready for the Install button. */
    promptAvailable: boolean;
    platform: InstallPlatform;
    secureContext: boolean;
};

export type InstallGuidance = { title: string; steps: string[] };

export function detectInstallPlatform(
    userAgent: string,
    maxTouchPoints: number,
): InstallPlatform {
    /** iPadOS Safari reports a desktop Mac user agent; touch support tells them apart. */
    const iPadOs = /Macintosh/.test(userAgent) && maxTouchPoints > 1;
    if (/iPhone|iPad|iPod/.test(userAgent) || iPadOs) {
        return 'ios';
    }
    if (/Android/i.test(userAgent)) {
        return 'android';
    }
    const safari =
        /Safari\//.test(userAgent) &&
        !/Chrome|Chromium|CriOS|Edg|OPR|Firefox|FxiOS/.test(userAgent);
    if (/Macintosh/.test(userAgent) && safari) {
        return 'macos-safari';
    }
    if (/Windows|Macintosh|Linux|CrOS/.test(userAgent)) {
        return 'desktop';
    }

    return 'other';
}

export function installState(environment: InstallEnvironment): InstallState {
    if (environment.standalone) {
        return 'installed';
    }
    if (environment.promptAvailable) {
        return 'available';
    }
    if (!environment.secureContext) {
        return 'insecure';
    }
    if (environment.platform === 'ios') {
        return 'ios-guide';
    }
    if (environment.platform === 'macos-safari') {
        return 'safari-guide';
    }

    return 'browser-menu';
}

export function installGuidance(state: InstallState): InstallGuidance | null {
    switch (state) {
        case 'ios-guide':
            return {
                title: 'Add PONGSKILOG to your Home Screen',
                steps: [
                    'Open PONGSKILOG in Safari.',
                    'Tap the Share button.',
                    'Choose Add to Home Screen, then tap Add.',
                    'Open PONGSKILOG from the new Home Screen icon.',
                ],
            };
        case 'safari-guide':
            return {
                title: 'Add PONGSKILOG to the Dock',
                steps: [
                    'In Safari, open the File menu (or the Share button).',
                    'Choose Add to Dock, then Add.',
                    'Open PONGSKILOG from the Dock or Launchpad.',
                ],
            };
        case 'browser-menu':
            return {
                title: 'Install from your browser',
                steps: [
                    'Open the browser menu (⋮ or …).',
                    'Choose Install PONGSKILOG, Install app or Add to Home screen.',
                    'If it is already installed, open PONGSKILOG from your apps or Home screen.',
                ],
            };
        case 'insecure':
            return {
                title: 'Installing needs a secure connection',
                steps: [
                    'Open PONGSKILOG through its https:// address to install it.',
                    'Local network http:// links keep working in the browser, but cannot be installed.',
                ],
            };
        default:
            return null;
    }
}
