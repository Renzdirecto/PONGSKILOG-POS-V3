import type { PaymentInput } from '@/types/pos';

export const invoiceProofDeferredLabel = 'Coming in Transaction History';

export function showsInvoiceProof(
    paymentMethod: PaymentInput['payment_method'],
): boolean {
    return paymentMethod === 'cashless' || paymentMethod === 'split';
}
