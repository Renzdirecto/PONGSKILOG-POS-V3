/** The running release (APP_VERSION + deployed commit), shared by the server; never hard-coded in the bundle. */
export type ReleaseInfo = {
    name: string;
    version: string;
    build: string | null;
};

/** "v1.0.0 · Build 3f9c2ab", or just the version when the deployment has no commit (local development). */
export function releaseLabel(release: ReleaseInfo | null | undefined): string {
    if (!release) {
        return 'Unknown version';
    }

    return release.build
        ? `${release.version} · Build ${release.build}`
        : release.version;
}
