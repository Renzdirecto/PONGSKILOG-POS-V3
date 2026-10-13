import type { ComponentType } from 'react';
import type { CustomerReview, ReviewBranch } from '@/lib/customer-reviews';
import type { RouteDefinition } from '@/wayfinder';

declare const LandingExperience: ComponentType<{
    loginHref: RouteDefinition<'get'>;
    loginLabel: string;
    motion?: boolean;
    showMarquee?: boolean;
    reviews: CustomerReview[];
    reviewBranches: ReviewBranch[];
    reviewNoticeVersion: string;
}>;

export default LandingExperience;
