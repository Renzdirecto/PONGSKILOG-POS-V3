<?php

namespace App\Enums;

enum BranchStatus: string
{
    case Active = 'active';
    case TemporarilyClosed = 'temporarily_closed';
    case Inactive = 'inactive';
}
