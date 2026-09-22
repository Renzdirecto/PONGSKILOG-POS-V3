<?php

namespace App\Enums;

enum OrderSource: string
{
    case Pos = 'pos';
    case CustomerQr = 'customer_qr';
}
