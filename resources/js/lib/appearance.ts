/** Customer-facing pages (menu, pickup, receipts, customer display) keep their designed Light look on every device. */
export function isCustomerFacingPage(component: string): boolean {
    return (
        component === 'welcome' ||
        component.startsWith('qr/') ||
        component === 'public-receipt' ||
        component === 'workspaces/customer-display' ||
        component === 'customer-screen' ||
        component === 'pickup'
    );
}
