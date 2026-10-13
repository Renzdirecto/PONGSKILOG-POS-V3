import { Head, usePage } from '@inertiajs/react';
import LandingExperience from '@/components/landing-experience';
import { dashboard, login } from '@/routes';
import '../../css/landing.css';

export default function Welcome() {
    const { auth } = usePage().props;
    return (
        <>
            <Head title="Silog, pares and lemonade in Quezon City">
                <meta
                    name="description"
                    content="PONGSKILOG serves Filipino silog, pares and short orders on Adarna Street, Quezon City. EST. 2022."
                />
            </Head>
            <LandingExperience
                loginHref={auth.user ? dashboard() : login()}
                loginLabel={auth.user ? 'Open workspace' : 'Login'}
            />
        </>
    );
}
