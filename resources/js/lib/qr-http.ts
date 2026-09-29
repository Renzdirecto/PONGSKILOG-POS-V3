import { http } from '@inertiajs/react';
/** A JSON request with a 15 s timeout; `signal` lets a caller abort a superseded request (e.g. a switched tab). */
export async function qrRequest<T>(
    route: { url: string; method: 'get' | 'post' | 'delete' | 'put' },
    data?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const timeout = AbortSignal.timeout(15000);
    const response = await http.getClient().request({
        ...route,
        data,
        headers: { Accept: 'application/json' },
        signal:
            signal === undefined
                ? timeout
                : typeof AbortSignal.any === 'function'
                  ? AbortSignal.any([signal, timeout])
                  : signal,
    });
    return JSON.parse(response.data) as T;
}

/** Whether a failed request was aborted on purpose (superseded), not a real failure to report. */
export function qrAborted(error: unknown): boolean {
    return (
        (error instanceof DOMException && error.name === 'AbortError') ||
        (typeof error === 'object' &&
            error !== null &&
            'name' in error &&
            ((error as { name?: string }).name === 'AbortError' ||
                (error as { name?: string }).name === 'CanceledError'))
    );
}
export function qrError(error: unknown): { status: number; message: string } {
    const response = (error as { response?: { status: number; data: string } })
        ?.response;
    if (response) {
        try {
            const body = JSON.parse(response.data) as {
                message?: string;
                errors?: Record<string, string[]>;
            };
            return {
                status: response.status,
                message:
                    Object.values(body.errors ?? {})
                        .flat()
                        .join(' ') ||
                    body.message ||
                    'The request was rejected.',
            };
        } catch {
            return {
                status: response.status,
                message:
                    'The request could not be completed. Please refresh and try again.',
            };
        }
    }
    return {
        status: 0,
        message:
            'The result is unconfirmed. Check your connection and retry the same request.',
    };
}
