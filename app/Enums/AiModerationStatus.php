<?php

namespace App\Enums;

enum AiModerationStatus: string
{
    case Approve = 'APPROVE';

    /**
     * Reserved for a moderation provider that returns an explicit reject
     * verdict. OpenAI's /v1/moderations endpoint only returns flagged
     * categories, not a verdict, so ProductModerationClient never produces
     * this value — flagged content maps to NeedsReview instead. Keep this
     * case for provider flexibility; see ProductApprovalRouter's REJECT
     * branch for the corresponding (currently unreachable) routing rule.
     */
    case Reject = 'REJECT';
    case NeedsReview = 'NEEDS_REVIEW';
}
