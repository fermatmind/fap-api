<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

class CareerCurrentAuthorityPackageLoader
{
    public function __construct(
        private readonly CareerCurrentAuthorityPackage $package,
    ) {}

    /** @return array<string,mixed> */
    public function load(string $backendRoot): array
    {
        return $this->package->load($backendRoot);
    }
}
