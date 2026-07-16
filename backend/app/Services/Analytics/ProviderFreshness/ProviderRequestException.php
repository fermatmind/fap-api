<?php

declare(strict_types=1);

namespace App\Services\Analytics\ProviderFreshness;

use RuntimeException;

final class ProviderRequestException extends RuntimeException
{
    public function __construct(public readonly string $diagnosticCode)
    {
        parent::__construct($diagnosticCode);
    }
}
