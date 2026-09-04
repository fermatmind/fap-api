<?php

declare(strict_types=1);

namespace App\Services\SeoCouncil\Platform12\Model;

use RuntimeException;

final class SeoCouncilModelFailure extends RuntimeException
{
    public function __construct(
        public readonly string $failureCode,
        public readonly int $transportAttempts = 0,
    ) {
        parent::__construct($failureCode);
    }
}
