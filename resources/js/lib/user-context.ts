/**
 * The signed-in account's realtime context contract. `user.context_changed` arrives on the account's own private
 * channel after a committed change to its identity (name, Position, picture), access (Role, Role baseline, custom
 * access), Branch assignments or status. It carries no permissions: the client revalidates the current page against
 * the server, which re-authorizes it and returns fresh shared props (sidebar, Role label, Branch selector).
 */
export const USER_CONTEXT_EVENT = '.user.context_changed';

export function userContextChannel(userId: number): string {
    return `App.Models.User.${userId}`;
}

export type RevalidationOutcome = 'login' | 'workspace' | 'ignore';

/**
 * What to do when revalidating the current page fails with an HTTP status. A lost session (deactivated, signed out,
 * expired CSRF) goes to login; a page the account can no longer open (403) or no longer sees (404) goes to the
 * workspace, whose server-side routing picks the landing page or Branch picker. Anything else keeps the page.
 */
export function revalidationOutcome(status: number): RevalidationOutcome {
    if (status === 401 || status === 419) {
        return 'login';
    }
    if (status === 403 || status === 404) {
        return 'workspace';
    }

    return 'ignore';
}

/**
 * Accepts a signal once, and only when it is addressed to this account (the channel is private, this is defence in
 * depth against a stale subscription after switching accounts).
 */
export function createUserContextEventGuard(userId: number) {
    const seen = new Set<string>();

    return (event: Record<string, unknown>) => {
        if (event.user_id !== userId) {
            return false;
        }
        if (typeof event.event_id === 'string') {
            if (seen.has(event.event_id)) {
                return false;
            }
            seen.add(event.event_id);
            if (seen.size > 256) {
                seen.delete(seen.values().next().value!);
            }
        }

        return true;
    };
}

/**
 * The Staff page's invalidation channel: business-wide Staff management and Super Admin listen on `staff`; a
 * Branch-scoped Staff manager only on its selected Branch's channel, so it never hears about other Branches.
 */
export function staffChannelFor(
    branchScoped: boolean,
    branchId: string | null,
): string | null {
    if (!branchScoped) {
        return 'staff';
    }

    return branchId === null ? null : `branch.${branchId}.staff`;
}
