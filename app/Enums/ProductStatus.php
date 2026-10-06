<?php

namespace App\Enums;

enum ProductStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case PendingReview = 'pending_review';
    case Archived = 'archived';
}
