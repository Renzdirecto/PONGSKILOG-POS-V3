import { Head, usePage } from '@inertiajs/react';
import LandingExperience from '@/components/landing-experience';
import type { CustomerReview, ReviewBranch } from '@/lib/customer-reviews';
import { dashboard, login } from '@/routes';
import '../../css/landing.css';

type Seo = {
    title: string;
    description: string;
    canonical: string;
    image: string;
    verification: string | null;
    schema: Record<string, unknown>;
};
export default function Welcome({
    reviews,
    reviewBranches,
    reviewNoticeVersion,
    seo,
}: {
    reviews: CustomerReview[];
    reviewBranches: ReviewBranch[];
    reviewNoticeVersion: string;
    seo: Seo;
}) {
    const { auth } = usePage().props;
    return (
        <>
            <Head title={seo.title}>
                <meta
                    head-key="description"
                    name="description"
                    content={seo.description}
                />
                <meta
                    head-key="robots"
                    name="robots"
                    content="index, follow, max-image-preview:large"
                />
                <link
                    head-key="canonical"
                    rel="canonical"
                    href={seo.canonical}
                />
                <meta head-key="og:type" property="og:type" content="website" />
                <meta
                    head-key="og:site_name"
                    property="og:site_name"
                    content="PONGSKILOG"
                />
                <meta
                    head-key="og:title"
                    property="og:title"
                    content={seo.title}
                />
                <meta
                    head-key="og:description"
                    property="og:description"
                    content={seo.description}
                />
                <meta
                    head-key="og:url"
                    property="og:url"
                    content={seo.canonical}
                />
                <meta
                    head-key="og:image"
                    property="og:image"
                    content={seo.image}
                />
                <meta
                    head-key="og:image:width"
                    property="og:image:width"
                    content="1200"
                />
                <meta
                    head-key="og:image:height"
                    property="og:image:height"
                    content="630"
                />
                <meta
                    head-key="og:image:alt"
                    property="og:image:alt"
                    content="PONGSKILOG chef logo, established 2022"
                />
                <meta
                    head-key="twitter:card"
                    name="twitter:card"
                    content="summary_large_image"
                />
                <meta
                    head-key="twitter:title"
                    name="twitter:title"
                    content={seo.title}
                />
                <meta
                    head-key="twitter:description"
                    name="twitter:description"
                    content={seo.description}
                />
                <meta
                    head-key="twitter:image"
                    name="twitter:image"
                    content={seo.image}
                />
                {seo.verification && (
                    <meta
                        head-key="google-site-verification"
                        name="google-site-verification"
                        content={seo.verification}
                    />
                )}
                <script head-key="restaurant" type="application/ld+json">
                    {JSON.stringify(seo.schema).replace(/</g, '\\u003c')}
                </script>
            </Head>
            <LandingExperience
                loginHref={auth.user ? dashboard() : login()}
                loginLabel={auth.user ? 'Open workspace' : 'Login'}
                reviews={reviews}
                reviewBranches={reviewBranches}
                reviewNoticeVersion={reviewNoticeVersion}
            />
        </>
    );
}
