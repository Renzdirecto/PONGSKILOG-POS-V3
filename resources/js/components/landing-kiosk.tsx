import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { FB, kioskBranchStatus, kioskStatus } from '@/lib/landing';
import type { KioskBranch } from '@/lib/landing';

const STEPS = [
    [
        'Pick your branch and order',
        'Choose your silog, drinks and add-ons on the kiosk.',
    ],
    [
        'Get your order number',
        'It shows on screen after you place your order. Take a screenshot.',
    ],
    [
        'Show it to pay and pick up',
        'Present the number at the cashier, or message it to us on Facebook while the store is open.',
    ],
];

const Arrow = ({ d, size }: { d: string; size: number }) => (
    <svg
        width={size}
        height={size}
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
    >
        <path d={d} />
    </svg>
);

/**
 * Skip the line (Landing V7): how the online kiosk works, a sample ticket, and "Start your order", which always opens
 * "Choose your branch" (a bottom sheet on a phone). Each Branch links to its real public Customer QR page in a new tab;
 * Open or Closed is the Branch's real state from the server, shown but never used to block the way there.
 */
export function LandingKiosk({ branches }: { branches: KioskBranch[] }) {
    const [choosing, setChoosing] = useState(false);
    const status = kioskStatus(branches);

    return (
        <section id="kiosk" aria-label="Order online" className="kiosk-section">
            <div data-reveal="" className="kiosk-inner">
                <div className="kiosk-copy">
                    <div className="kiosk-head">
                        <span className="kiosk-eyebrow">Online kiosk</span>
                        <h2>Skip the line. Order online.</h2>
                        <p>
                            Order from your phone using our online kiosk. You
                            get an order number when you're done.
                        </p>
                    </div>
                    <ol className="kiosk-steps">
                        {STEPS.map(([title, detail], index) => (
                            <li key={title}>
                                <span aria-hidden="true">{index + 1}</span>
                                <div>
                                    <strong>{title}</strong>
                                    <p>{detail}</p>
                                </div>
                            </li>
                        ))}
                    </ol>
                    <div className="kiosk-start">
                        <button
                            type="button"
                            data-magnet=""
                            onClick={() => setChoosing(true)}
                        >
                            <span>Start your order</span>
                            <Arrow d="M5 12h14M13 6l6 6-6 6" size={18} />
                        </button>
                        {status && (
                            <span
                                className="kiosk-status"
                                style={{ color: status.fg }}
                            >
                                <span style={{ background: status.fg }} />
                                {status.label}
                            </span>
                        )}
                    </div>
                </div>
                <div className="kiosk-ticket-wrap">
                    {/* An illustration of what the kiosk shows, never a real order. */}
                    <div
                        role="img"
                        aria-label="Sample order ticket with order number 024"
                        className="kiosk-ticket"
                    >
                        <div className="kiosk-ticket-top">
                            <span>PONGSKILOG</span>
                            <span>Sample</span>
                        </div>
                        <div className="kiosk-ticket-number">
                            <span>Your order number</span>
                            <b>#024</b>
                        </div>
                        <div className="kiosk-ticket-cut" />
                        <div className="kiosk-ticket-notes">
                            <div>
                                <svg
                                    width="18"
                                    height="18"
                                    viewBox="0 0 24 24"
                                    fill="none"
                                    stroke="currentColor"
                                    strokeWidth="1.9"
                                    strokeLinecap="round"
                                    strokeLinejoin="round"
                                    aria-hidden="true"
                                >
                                    <rect
                                        x="2"
                                        y="6"
                                        width="20"
                                        height="12"
                                        rx="2"
                                    />
                                    <circle cx="12" cy="12" r="2" />
                                </svg>
                                Show this at the cashier
                            </div>
                            <div>
                                <Arrow
                                    d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"
                                    size={18}
                                />
                                or send it to us on Facebook
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <Dialog open={choosing} onOpenChange={setChoosing}>
                <DialogContent
                    className="kiosk-sheet"
                    overlayClassName="kiosk-overlay"
                    showCloseButton={false}
                >
                    <div className="kiosk-sheet-head">
                        <div>
                            <DialogTitle>Choose your branch</DialogTitle>
                            <DialogDescription>
                                The kiosk opens in a new tab.
                            </DialogDescription>
                        </div>
                        <button
                            type="button"
                            aria-label="Close"
                            onClick={() => setChoosing(false)}
                        >
                            <Arrow d="M18 6L6 18M6 6l12 12" size={18} />
                        </button>
                    </div>
                    {branches.length ? (
                        <ul className="kiosk-branches">
                            {branches.map((branch) => {
                                const state = kioskBranchStatus(branch);
                                return (
                                    <li key={branch.url}>
                                        <a
                                            href={branch.url}
                                            target="_blank"
                                            rel="noopener"
                                            onClick={() => setChoosing(false)}
                                        >
                                            <img
                                                src="/images/landing-v121-20b09867.webp"
                                                alt=""
                                                width="56"
                                                height="56"
                                            />
                                            <span className="kiosk-branch-text">
                                                <strong>{branch.name}</strong>
                                                {branch.address && (
                                                    <span>
                                                        {branch.address}
                                                    </span>
                                                )}
                                                <span
                                                    className="kiosk-branch-status"
                                                    style={{ color: state.fg }}
                                                >
                                                    <span
                                                        style={{
                                                            background:
                                                                state.fg,
                                                        }}
                                                    />
                                                    {state.label}
                                                </span>
                                            </span>
                                            <span
                                                className="kiosk-branch-go"
                                                aria-hidden="true"
                                            >
                                                <Arrow
                                                    d="M7 17L17 7M8 7h9v9"
                                                    size={16}
                                                />
                                            </span>
                                        </a>
                                    </li>
                                );
                            })}
                        </ul>
                    ) : (
                        <p className="kiosk-none">
                            Online ordering isn't set up for any branch yet.{' '}
                            <a
                                href={FB}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Message us on Facebook
                            </a>{' '}
                            to order.
                        </p>
                    )}
                </DialogContent>
            </Dialog>
        </section>
    );
}
