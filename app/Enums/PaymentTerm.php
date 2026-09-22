<?php

namespace App\Enums;

enum PaymentTerm: string
{
    case Immediate = 'immediate';
    case PayLater = 'pay_later';
}
