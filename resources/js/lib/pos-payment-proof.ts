import type { PaymentInput } from '@/types/pos';

export function showsInvoiceProof(
    paymentMethod: PaymentInput['payment_method'],
): boolean {
    return paymentMethod === 'cashless' || paymentMethod === 'split';
}
