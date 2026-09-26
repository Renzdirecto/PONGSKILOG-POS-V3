<?php

namespace App\Enums;

/**
 * The persistent mode of a paired customer screen. The two Store Operations controls are mutually exclusive and both
 * may be off: `Menu` on → Menu, `CustomerDisplay` on → the order-number board, neither → `Ads` (the default). One
 * column holds the value, so "both on" cannot exist. An order takeover is temporary and never changes this value.
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
}
