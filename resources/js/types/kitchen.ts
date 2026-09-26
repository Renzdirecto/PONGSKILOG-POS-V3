export type KitchenStatus = 'kitchen' | 'preparing' | 'ready' | 'done';

export type KitchenItem = {
    id: string;
    display_name: string;
    quantity: number;
    standard_modifiers: string[];
    instructions: string[];
    note: string | null;
};

export type KitchenTicket = {
    id: string;
    number: string;
    customer: string | null;
    order_type: 'dine_in' | 'take_out';
    table: string | null;
    status: KitchenStatus;
    placed_at: string;
    version: number;
    items: KitchenItem[];
};

export type KitchenBoardData = {
    is_open: boolean;
    tickets: KitchenTicket[];
    counts: Record<'all' | KitchenStatus, number>;
};

export type KitchenStatusSummary = {
    is_open: boolean;
    dine_in: number;
    take_out: number;
};

export type KitchenTransitionFlash = {
    order_id: string;
    from: KitchenStatus;
    to: KitchenStatus;
    changed: boolean;
};

export type CustomerDisplayData = {
    is_open: boolean;
    preparing: string[];
    ready: string[];
};

/** Present only for a Ready Take Out order whose customer turned notifications on (Phase 19.6B). */
export type PosReadyBuzz = {
    count: number;
    max: number;
    remaining: number;
    last_buzzed_at: string | null;
    cooldown_until: string | null;
};

export type PosReadyOrder = KitchenTicket & {
    payment_status: 'unpaid' | 'partial' | 'paid';
    payment_term: 'immediate' | 'pay_later' | null;
    payment_methods: ('cash' | 'cashless')[];
    total: string;
    buzz: PosReadyBuzz | null;
};

export type KitchenRealtimeEvent = {
    event_id: string;
    event_type: 'kitchen.ticket_created' | 'kitchen.status_changed';
    branch_id: string;
    order_id: string;
    order_number: string;
    occurred_at: string;
    version: number;
};
