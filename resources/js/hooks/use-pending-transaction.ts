import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { toast } from 'sonner';

/** Keep the stable attempt and cart mounted until the server confirms or rejects the write. */
export function usePendingTransaction(pending: boolean) {
    const pendingRef = useRef(pending);
    pendingRef.current = pending;
    useEffect(() => {
        const remove = router.on('before', (event) => {
            if (!pendingRef.current || event.detail.visit.async) return;
            event.preventDefault();
            toast.info(
                'Transaction pending. Wait for confirmation or retry the same attempt before leaving.',
            );
        });
        const beforeUnload = (event: BeforeUnloadEvent) => {
            if (pendingRef.current) event.preventDefault();
        };
        window.addEventListener('beforeunload', beforeUnload);
        return () => {
            remove();
            window.removeEventListener('beforeunload', beforeUnload);
        };
    }, []);
}
