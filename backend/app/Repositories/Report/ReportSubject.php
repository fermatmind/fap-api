<?php

declare(strict_types=1);

namespace App\Repositories\Report;

use App\Models\Attempt;
use App\Models\Result;

final readonly class ReportSubject
{
    public function __construct(
        public Attempt $attempt,
        public Result $result,
        public int $orgId,
    ) {}
}
