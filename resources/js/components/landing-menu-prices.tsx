import { CAT_ORDER, MENU } from '@/lib/landing';
export function LandingMenuPrices({ category }: { category: string }) {
    return (
        <div className="landing-price-grid">
            {CAT_ORDER.filter(
                (key) => category === 'all' || category === key,
            ).map((key) => (
                <section key={key} className="landing-price-category">
                    <header>
                        <h3>{MENU[key].label}</h3>
                        <p>{MENU[key].note}</p>
                    </header>
                    <dl>
                        {MENU[key].items.map(([name, price]) => (
                            <div key={name}>
                                <dt>{name}</dt>
                                <dd>{price}</dd>
                            </div>
                        ))}
                    </dl>
                </section>
            ))}
        </div>
    );
}
