import type { ComponentType } from 'react';
import type { RouteDefinition } from '@/wayfinder';

declare const LandingExperience: ComponentType<{
    loginHref: RouteDefinition<'get'>;
    loginLabel: string;
    motion?: boolean;
    showMarquee?: boolean;
    showPriceList?: boolean;
}>;

export default LandingExperience;
