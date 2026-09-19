import type { CashierCatalog } from './catalog';

export type PosProduct = CashierCatalog['products'][number];
export type OrderType = 'dine_in' | 'take_out';
export type BranchTable = { id: string; name: string };
export type CartLine = {
    key: string;
    product: PosProduct;
    quantity: number;
    notes: string;
    modifiers: { group_id: string; option_id: string }[];
};
export type OrderSummary = {
    id: string;
    order_number: string;
    order_type: OrderType;
    table_name: string | null;
    customer_label: string | null;
    subtotal: string;
    total: string;
    items: {
        id: string;
        name: string;
        unit_price: string;
        quantity: number;
        line_total: string;
        notes: string | null;
        modifiers: {
            id: string;
            group_name: string;
            name: string;
            price_delta: string;
            quantity: number;
        }[];
    }[];
};
