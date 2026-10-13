/** One period of Shippy's usage, from `ShippyUsage::summary()`. */
export type ShippyUsageTotals = {
    /** Questions guests asked, however they were answered. */
    messages: number;
    /** Requests really sent to Gemini. */
    requests: number;
    /** Gemini requests that failed or whose answer could not be used. */
    errors: number;
    input_tokens: number;
    output_tokens: number;
    /** Tokens at the model's list price: an estimate, never a bill. */
    estimated_usd: number;
};

/** What Website → Shippy shows. The API key is never part of it: only whether one is configured. */
export type WebsiteShippy = {
    enabled: boolean;
    configured: boolean;
    model: string;
    usage: {
        today: ShippyUsageTotals;
        month: ShippyUsageTotals;
        days: number;
        daily_limit: number;
    };
};

/** An estimated cost in US dollars; a real but tiny amount is never rounded down to zero. */
export function shippyCostLabel(usd: number): string {
    if (usd <= 0) return '$0.00';
    return usd < 0.01 ? 'under $0.01' : `$${usd.toFixed(2)}`;
}
