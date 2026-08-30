<?php

declare(strict_types=1);

namespace App\Domain\Personality\Current;

use RuntimeException;

final class PersonalityCurrentAuthorityFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}
