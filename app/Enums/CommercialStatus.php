<?php

namespace App\Enums;

enum CommercialStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Active = 'active';
    case Completed = 'completed';
    case Voided = 'voided';
    case ArchivedUnclaimed = 'archived_unclaimed';
}
