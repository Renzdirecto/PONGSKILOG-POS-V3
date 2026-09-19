<?php

namespace App\Support;

use Illuminate\Support\Str;

class OrderNumber
{
    public function generate(): string
    {
        return now()->format('ymd').'-'.Str::upper(Str::random(8));
    }
}
