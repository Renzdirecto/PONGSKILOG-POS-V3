import type { ComponentType } from 'react';
import type {
    CustomerReview,
    ReviewBranch,
    ReviewSummary,
} from '@/lib/customer-reviews';
import type { TeamCard } from '@/lib/landing';
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
}>;

export default LandingExperience;
