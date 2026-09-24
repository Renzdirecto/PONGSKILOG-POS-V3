import { http } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type {
    CapacitySelection,
    ConfigurationCapacity,
} from '@/lib/recipe-availability';

const DEBOUNCE_MS = 180;

/**
 * Asks the server (App\Support\RecipeCapacity) how much of the configured item Branch Ingredient stock can still make
 * after the rest of the cart. Debounced per selection change and only while `url` is set; stale answers are ignored.
 * Display only: every commit re-checks under the Ingredient locks. A failed request leaves the dialog unrestricted.
 */
export function useRecipeCapacity(
    url: string | null,
    request: { lines: CapacitySelection[]; focus: CapacitySelection },
    /** Changes when the authoritative catalog refreshes (for example after a realtime stock change). */
    refreshKey = '',
): { capacity: ConfigurationCapacity | null; loading: boolean } {
    const [capacity, setCapacity] = useState<ConfigurationCapacity | null>(
        null,
    );
    const [loading, setLoading] = useState(false);
    const latest = useRef(0);
    const body = JSON.stringify(request);

    useEffect(() => {
        if (url === null) {
            return;
        }
        const id = ++latest.current;
        const timer = setTimeout(async () => {
            setLoading(true);
            try {
                const response = await http.getClient().request({
                    url,
                    method: 'post',
                    data: JSON.parse(body),
                    headers: { Accept: 'application/json' },
                });
                if (id === latest.current) {
                    setCapacity(
                        JSON.parse(response.data) as ConfigurationCapacity,
                    );
                }
            } catch {
                if (id === latest.current) {
                    setCapacity(null);
                }
            } finally {
                if (id === latest.current) {
                    setLoading(false);
                }
            }
        }, DEBOUNCE_MS);

        return () => clearTimeout(timer);
    }, [url, body, refreshKey]);

    return { capacity: url === null ? null : capacity, loading };
}
