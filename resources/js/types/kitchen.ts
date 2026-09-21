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

export type CustomerDisplayData = {
    is_open: boolean;
    preparing: string[];
    ready: string[];
};

export type PosReadyOrder = KitchenTicket & {
    payment_status: 'unpaid' | 'partial' | 'paid';
    payment_term: 'immediate' | 'pay_later' | null;
    payment_methods: ('cash' | 'cashless')[];
    total: string;
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
