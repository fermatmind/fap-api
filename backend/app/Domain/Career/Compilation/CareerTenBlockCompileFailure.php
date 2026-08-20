<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use RuntimeException;

final class CareerTenBlockCompileFailure extends RuntimeException
{
    public function __construct(public readonly string $safeCode)
    {
        parent::__construct($safeCode);
    }
}
