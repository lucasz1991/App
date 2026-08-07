<?php

namespace App\Enums;

enum MarketingCreativeStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Archived = 'archived';
}
