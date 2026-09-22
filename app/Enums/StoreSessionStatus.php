<?php

namespace App\Enums;

enum StoreSessionStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}
