/** The official round Pongskilog emblem, the one brand mark for headers, sidebars and auth screens. */
export const BRAND_EMBLEM_SRC = '/images/branding/pongskilog-emblem.png';

export default function AppLogoIcon({
    className,
    alt = 'Pongskilog',
}: {
    className?: string;
    alt?: string;
}) {
    return (
        <img
            src={BRAND_EMBLEM_SRC}
            alt={alt}
            draggable={false}
            className={`aspect-square object-contain ${className ?? ''}`}
        />
    );
}
