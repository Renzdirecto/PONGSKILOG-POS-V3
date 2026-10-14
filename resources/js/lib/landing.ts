import menu from './landing-menu.json' with { type: 'json' };

// Published menu and address cross-checked on 2026-10-13 against
// https://pongskilog.kesug.com/LANDINGPAGE/PONGSKILOG-LANDING-PAGE.html
export const FB = 'https://www.facebook.com/profile.php?id=100094575655003';
// Independently verified Google Maps pin; the reference marker was offset.
export const SHOP = [14.6936607, 121.079139];

export const ULAM = [
    {
        pre: 'TAP',
        rest: 'a, cured beef',
        price: 100,
        note: 'The house standard.',
        img: '/images/landing-v121-f508db12.webp',
        alt: 'Tapsilog',
    },
    {
        pre: 'LIEMPO',
        rest: ', grilled pork belly',
        price: 100,
        note: 'Cut thick, grilled to order.',
        img: '/images/landing-v121-ecc6c749.webp',
        alt: 'Liempo silog',
    },
    {
        pre: 'CHICK',
        rest: 'en, fried quarter',
        price: 110,
        note: 'Crisp skin, juicy leg.',
        img: '/images/landing-v121-0ff4a945.webp',
        alt: 'Chicken silog',
    },
    {
        pre: 'BANG',
        rest: 'us, milkfish',
        price: 110,
        note: 'Fried until the edges crackle.',
        img: '/images/landing-v121-8b72ed2f.webp',
        alt: 'Bangus silog',
    },
    {
        pre: 'PORK',
        rest: ' chop, fried pork',
        price: 95,
        note: 'Crisp edges, juicy center.',
        img: '/images/landing-v121-8611ee78.webp',
        alt: 'Pork silog',
    },
];

/**
 * Three's Lemonade (Landing V7): each drink's poster sits beside its name and price in the published menu, so the
 * Drinks cards, the Three's Lemonade section and Shippy read one path per drink. A poster carries its own name and
 * prices, so it is always shown whole.
 */
export const CUPS = menu.drinks.items
    .filter((item) => item.length > 2)
    .map(([name, price, img]) => ({ name, price, img, alt: `${name} poster` }));

export const CARDS = [
    {
        img: '/images/landing-v121-822fd717.webp',
        alt: 'Tapsilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-2f7901fa.webp',
        alt: 'Bangsilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-1bba3869.webp',
        alt: 'Chicksilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-2be3bb14.webp',
        alt: 'Liemposilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-8611ee78.webp',
        alt: 'Porksilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-eebdc746.webp',
        alt: 'Longsilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-d8225368.webp',
        alt: 'Tocilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-f3b60af7.webp',
        alt: 'Hungarian silog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-a061b274.webp',
        alt: 'Mixsilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-7d660de0.webp',
        alt: 'Chicken fillet silog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-b51e6b5d.webp',
        alt: 'Hamsilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-a74425f2.webp',
        alt: 'Hotsilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-cb95c141.webp',
        alt: 'Embutido silog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-3c8f2dab.webp',
        alt: 'Malingsilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-09894ed2.webp',
        alt: 'Nuggetsilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-7547940c.webp',
        alt: 'Shanghaisilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-5da3d399.webp',
        alt: 'Siomaisilog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-d3bcb09c.webp',
        alt: 'Silog',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-54e8c470.webp',
        alt: 'Burger steak',
        cat: 'silog',
    },
    {
        img: '/images/landing-v121-b0bbc3cb.webp',
        alt: 'Bulalo',
        cat: 'pares',
    },
    {
        img: '/images/landing-v121-63dcbd0b.webp',
        alt: 'Beef pares with rice',
        cat: 'pares',
    },
    {
        img: '/images/landing-v121-fcd12624.webp',
        alt: 'Beef pares only',
        cat: 'pares',
    },
    {
        img: '/images/landing-v121-6ae3a027.webp',
        alt: 'Lechon pares with rice',
        cat: 'pares',
    },
    {
        img: '/images/landing-v121-450058c9.webp',
        alt: 'Lechon pares only',
        cat: 'pares',
    },
    {
        img: '/images/landing-v121-c8b27d2f.webp',
        alt: 'Mami',
        cat: 'pares',
    },
    {
        img: '/images/landing-v121-8fb7f148.webp',
        alt: 'Pancit canton',
        cat: 'short',
    },
    {
        img: '/images/landing-v121-22d7e1a7.webp',
        alt: 'Pancit bihon',
        cat: 'short',
    },
    {
        img: '/images/landing-v121-af229195.webp',
        alt: 'Miki bihon',
        cat: 'short',
    },
    {
        img: '/images/landing-v121-56d2c469.webp',
        alt: 'Miki guisado',
        cat: 'short',
    },
    {
        img: '/images/landing-v121-12cbd644.webp',
        alt: 'Lechon kawali',
        cat: 'short',
    },
    {
        img: '/images/landing-v121-2f07f761.webp',
        alt: 'Sisig',
        cat: 'short',
    },
    {
        img: '/images/landing-v121-6544153d.webp',
        alt: "Tokwa't baboy",
        cat: 'short',
    },
    {
        img: '/images/landing-v121-0ecf9527.webp',
        alt: 'Chicken',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-6a193b10.webp',
        alt: 'Bangus',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-93911d07.webp',
        alt: 'Porkchop',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-f0f3cc4a.webp',
        alt: 'Liempo',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-d8d56c44.webp',
        alt: 'Hungarian',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-21029c67.webp',
        alt: 'Shanghai',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-f8953943.webp',
        alt: 'Siomai',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-ed339048.webp',
        alt: 'Hotdog',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-0cd269f2.webp',
        alt: 'Maling',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-d0bdf098.webp',
        alt: 'Egg',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-fd74d8a0.webp',
        alt: 'Rice',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-7304e306.webp',
        alt: 'Half rice',
        cat: 'ala',
    },
    {
        img: '/images/landing-v121-5c08777b.webp',
        alt: 'Crystal add-on',
        cat: 'ala',
    },
    /* The Three's Lemonade posters from CUPS; a `drink` poster is shown whole over its own blur. */
    ...CUPS.map(({ img, alt }) => ({ img, alt, cat: 'drinks', drink: true })),
];

export const BOARD_CATS = [
    ['all', 'All'],
    ['silog', 'Silog'],
    ['pares', 'Pares & bowls'],
    ['short', 'Short orders'],
    ['ala', 'Ala carte'],
    ['drinks', 'Drinks'],
];

/**
 * The published menu. It lives in one JSON file so the landing page and the server (Shippy: `ShippyKnowledge`) read
 * the same names and prices; nothing about the menu is typed twice.
 */
export const MENU = menu;

export const CAT_ORDER: (keyof typeof MENU)[] = [
    'silog',
    'pares',
    'short',
    'ala',
    'drinks',
    'dessert',
];

/**
 * One card of Meet the Team (Landing V6), from `LandingTeamMember::card()`. The Owner edits the six cards in
 * Website → Team; a `null` photo, name or bio renders a clearly marked placeholder, so nothing is ever made up.
 */
export type TeamCard = {
    id: number;
    code: string;
    title: string;
    name: string | null;
    bio: string | null;
    img: string | null;
    visible: boolean;
};

/**
 * One Branch in "Choose your branch" (Landing V7 online kiosk), from `LandingPageController`: its public Customer QR
 * ordering page and whether it takes orders right now. The link always opens; the kiosk page itself decides what may
 * be ordered.
 */
export type KioskBranch = {
    name: string;
    address: string | null;
    url: string;
    open: boolean;
};

const OPEN = '#2E5A4B';
const CLOSED = '#B0281F';

/** A Branch's own line in the branch sheet. */
export function kioskBranchStatus(branch: KioskBranch) {
    return branch.open
        ? { label: 'Open now', fg: OPEN }
        : { label: 'Closed now', fg: CLOSED };
}

/** The status beside "Start your order": the real state of the Branches, or nothing when there is none to show. */
export function kioskStatus(branches: KioskBranch[]) {
    const open = branches.filter((branch) => branch.open).length;
    if (!branches.length) return null;
    if (branches.length === 1) return kioskBranchStatus(branches[0]);
    return open
        ? {
              label: `Open now at ${open} of ${branches.length} branches`,
              fg: OPEN,
          }
        : { label: 'All branches closed now', fg: CLOSED };
}

/**
 * Meet the Team auto-slide: 0.024 px per ms, which is V6's 0.4 px per frame at 60 Hz on any screen refresh rate.
 * Reaching the last card asks for a return to the first one.
 */
export function advanceTeamScroll(
    position: number,
    maximum: number,
    elapsed: number,
) {
    if (position >= maximum - 1) return { position: 0, restart: true };
    return {
        position: Math.min(maximum, position + Math.min(elapsed, 64) * 0.024),
        restart: false,
    };
}

export const HOURS = [
    {
        day: 'Mon, Tue, Thu to Sat',
        time: '6:00 PM - 12:00 AM',
        fg: '#121110',
        days: ['Mon', 'Tue', 'Thu', 'Fri', 'Sat'],
    },
    {
        day: 'Wednesday',
        time: 'Usually Closed',
        note: 'Sometimes open — message to confirm.',
        fg: '#8A5E12',
        days: ['Wed'],
    },
    {
        day: 'Sunday',
        time: 'Closed',
        fg: '#B0281F',
        days: ['Sun'],
    },
];
