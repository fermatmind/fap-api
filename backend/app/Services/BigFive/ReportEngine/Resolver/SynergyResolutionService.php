<?php

declare(strict_types=1);

namespace App\Services\BigFive\ReportEngine\Resolver;

use App\Services\BigFive\ReportEngine\Contracts\SynergyMatch;
use App\Services\BigFive\ReportEngine\Rules\MutexResolver;

final class SynergyResolutionService
{
    public function __construct(
        private readonly MutexResolver $mutexResolver = new MutexResolver,
    ) {}

    /**
     * @param  list<SynergyMatch>  $candidates
     * @return list<SynergyMatch>
     */
    public function resolve(array $candidates, int $maxShow = 2): array
    {
        return $this->mutexResolver->resolve($candidates, max(0, min(2, $maxShow)));
    }
}
