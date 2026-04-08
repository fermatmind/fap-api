<?php

declare(strict_types=1);

namespace App\Domain\Career;

final class ProjectionLineageReason
{
    public const INITIAL_CAPTURE = 'initial_capture';

    public const CONTEXT_REFRESH = 'context_refresh';

    public const ASSESSMENT_REFRESH = 'assessment_refresh';

    public const MANUAL_RECOMPUTE = 'manual_recompute';
}
