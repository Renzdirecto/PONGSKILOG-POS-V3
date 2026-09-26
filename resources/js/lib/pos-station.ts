import { http } from '@inertiajs/react';
import { createClientUuid } from '@/lib/client-uuid';
import { readStationId, type CustomerScreenMode } from '@/lib/customer-screen';

/**
 * This browser as a POS station (Phase 19.6A). The station id is a random UUID kept in local storage — the pairing
 * belongs to the station, not to whoever is signed in — and is sent only in the `X-POS-Station` header. The server
 * pairs and looks up screens by (selected Branch, hash of this id), so the id alone reaches nothing at another Branch.
 */
export type StationScreenStatus = {
    paired: boolean;
    mode: CustomerScreenMode | null;
    paired_at: string | null;
    last_seen_at: string | null;
};

let stationId: string | null | undefined;

export function posStationId(): string | null {
    if (stationId === undefined) {
        let storage: Storage | null = null;
        try {
            storage = window.localStorage;
        } catch {
            storage = null;
        }
        stationId = readStationId(storage, createClientUuid);
    }

    return stationId;
}

export class StationUnavailableError extends Error {}

export async function stationRequest<T>(
    route: { url: string; method: 'get' | 'post' | 'put' | 'delete' },
    data?: unknown,
): Promise<T> {
    const station = posStationId();
    if (station === null) {
        throw new StationUnavailableError(
            'This browser cannot keep a POS station id (storage is blocked).',
        );
    }
    const response = await http.getClient().request({
        ...route,
        data,
        headers: { Accept: 'application/json', 'X-POS-Station': station },
        signal: AbortSignal.timeout(15000),
    });

    return JSON.parse(response.data) as T;
}

/**
 * Station writes to the customer screen (cart sends, the takeover) run one after another in the order they were
 * made, so the takeover can never overtake the last cart send before the payment.
 */
let writes: Promise<unknown> = Promise.resolve();
export function enqueueStationWrite<T>(task: () => Promise<T>): Promise<T> {
    const run = writes.then(task, task);
    writes = run.catch(() => undefined);

    return run;
}

/** The paired-screen status of this station, shared by the header control and the POS cart sync. */
let status: StationScreenStatus | null = null;
const listeners = new Set<() => void>();

export const stationScreen = {
    get: (): StationScreenStatus | null => status,
    set: (next: StationScreenStatus | null) => {
        status = next;
        listeners.forEach((listener) => listener());
    },
    subscribe: (listener: () => void) => {
        listeners.add(listener);

        return () => {
            listeners.delete(listener);
        };
    },
};
