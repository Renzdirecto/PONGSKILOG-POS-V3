<?php

namespace App\Enums;

enum KitchenStatus: string
{
    case NotSent = 'not_sent';
    case Kitchen = 'kitchen';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Done = 'done';
}
