import type { ComponentType } from 'react';
import type {
    CustomerReview,
    ReviewBranch,
    ReviewSummary,
} from '@/lib/customer-reviews';
import type { KioskBranch, TeamCard } from '@/lib/landing';
import type { RouteDefinition } from '@/wayfinder';

declare const LandingExperience: ComponentType<{
    loginHref: RouteDefinition<'get'>;
    loginLabel: string;
    motion?: boolean;
    showMarquee?: boolean;
    reviews: CustomerReview[];
    reviewSummary: ReviewSummary;
    reviewBranches: ReviewBranch[];
    reviewNoticeVersion: string;
    team: TeamCard[];
    loveTotal: number;
    /** The online kiosk's Branches (Landing V7), with whether each takes orders right now. */
    kiosk: KioskBranch[];
    /** Shippy's preset questions, or null while the chat is switched off in Website → Shippy. */
    shippy: { presets: string[] } | null;
}>;

export default LandingExperience;
