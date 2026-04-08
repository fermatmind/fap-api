<?php

declare(strict_types=1);

namespace App\Domain\Career;

final class ReviewerStatus
{
    public const PENDING = 'pending';

    public const IN_REVIEW = 'in_review';

    public const APPROVED = 'approved';

    public const CHANGES_REQUIRED = 'changes_required';
}
