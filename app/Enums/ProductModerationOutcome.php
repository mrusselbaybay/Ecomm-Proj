<?php

namespace App\Enums;

enum ProductModerationOutcome: string
{
    case AutoApproved = 'auto_approved';
    case AutoRejected = 'auto_rejected';
    case PendingHumanReview = 'pending_human_review';
}
