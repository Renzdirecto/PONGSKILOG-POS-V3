import type { KitchenStatus, KitchenTicket } from '@/types/kitchen';

export const KITCHEN_REALTIME_EVENTS = [
    '.kitchen.ticket_created',
    '.kitchen.status_changed',
    '.kitchen.order_updated',
    '.order.voided',
] as const;

export const POS_READY_REALTIME_EVENTS = [
    '.kitchen.ticket_created',
    '.kitchen.status_changed',
    /** A Take Out customer turned pickup notifications on/off, or a cashier buzzed (Phase 19.6B). */
    '.pickup.notify_changed',
    /** A voided order leaves the Ready list at once. */
    '.order.voided',
] as const;

export const DISPLAY_REALTIME_EVENTS = ['.display.orders_changed'] as const;

export function canOpenCustomerDisplay(
    permissions: readonly string[],
): boolean {
    return permissions.includes('customer_display.launch');
}

/** All orders is the active kitchen queue: Ready tickets live in Ready and Done tickets in Done. */
export function isActiveKitchenWork(status: KitchenStatus): boolean {
    return status === 'kitchen' || status === 'preparing';
}

const STATUS_POSITION: Record<KitchenStatus, number> = {
    kitchen: 0,
    preparing: 1,
    ready: 2,
    done: 3,
};

export function canTransitionKitchenStatus(
    from: KitchenStatus,
    to: KitchenStatus,
): boolean {
    const distance = STATUS_POSITION[to] - STATUS_POSITION[from];

    return distance >= 0 || distance === -1;
}

export function filterKitchenTickets(
    tickets: KitchenTicket[],
    tab: 'all' | KitchenStatus,
    search: string,
): KitchenTicket[] {
    const needle = search.trim().toLocaleLowerCase();
    const numberNeedle = needle.replace(/^#/, '');

    return tickets.filter((ticket) => {
        const matchesTab =
            tab === 'all'
                ? isActiveKitchenWork(ticket.status)
                : ticket.status === tab;
        const matchesSearch =
            needle === '' ||
            ticket.number.toLocaleLowerCase().includes(numberNeedle) ||
            (ticket.customer ?? '').toLocaleLowerCase().includes(needle);

        return matchesTab && matchesSearch;
    });
}

export function statusLabel(status: KitchenStatus): string {
    return status.charAt(0).toUpperCase() + status.slice(1);
}

export function orderTypeLabel(type: KitchenTicket['order_type']): string {
    return type === 'dine_in' ? 'Dine in' : 'Take out';
}

export function relativePlacedTime(placedAt: string, now = Date.now()): string {
    const minutes = Math.max(
        0,
        Math.floor((now - new Date(placedAt).getTime()) / 60_000),
    );

    if (minutes < 1) {
        return 'Just now';
    }

    if (minutes < 60) {
        return `${minutes} min ago`;
    }

    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;

    return remainder === 0
        ? `${hours} hr ago`
        : `${hours} hr ${remainder} min ago`;
}

export function kitchenItemLabel(
    displayName: string,
    standardModifiers: readonly string[],
): string {
    return [displayName, ...standardModifiers].join(' + ');
}
