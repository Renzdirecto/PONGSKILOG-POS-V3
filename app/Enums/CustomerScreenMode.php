<?php

namespace App\Enums;

/**
 * The persistent mode of a paired customer screen. The two controls (Store Operations header and the screen's own
 * header) are mutually exclusive and both may be off: `Menu` on → Menu, `CustomerDisplay` on → the order-number board,
 * neither → `Ads` (the default). One column holds the value, so "both on" cannot exist.
 */
enum CustomerScreenMode: string
{
    case Ads = 'ads';
    case Menu = 'menu';
    case CustomerDisplay = 'customer_display';

    /** Pressing a control: turning on one turns the other off; pressing the active control again returns to Ads. */
    public function toggled(self $control): self
    {
        return $this === $control ? self::Ads : $control;
    }

    /**
     * The mode once a committed order has been confirmed on screen: the Menu the customer browsed while ordering closes
     * (the screen returns to Ads, never reopening the Menu by itself); an explicitly selected Customer Display stays.
     */
    public function afterOrderSuccess(): self
    {
        return $this === self::Menu ? self::Ads : $this;
    }
}
