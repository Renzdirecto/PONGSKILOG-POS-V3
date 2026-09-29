<?php

namespace App\Enums;

/** Whether a Stock Correction adds Product stock (the count found more) or removes it (the count found less). */
enum StockCorrectionDirection: string
{
    case Decrease = 'decrease';
    case Increase = 'increase';

    /** The sign of the inventory movement; `quantity` is always the positive size of the correction. */
    public function sign(): int
    {
        return $this === self::Increase ? 1 : -1;
    }
}
