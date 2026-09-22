import { http } from '@inertiajs/react';
export async function qrRequest<T>(
    route: { url: string; method: 'get' | 'post' | 'delete' | 'put' },
    data?: unknown,
): Promise<T> {
    const response = await http
        .getClient()
        .request({
            ...route,
            data,
            headers: { Accept: 'application/json' },
            signal: AbortSignal.timeout(15000),
        });
    return JSON.parse(response.data) as T;
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
