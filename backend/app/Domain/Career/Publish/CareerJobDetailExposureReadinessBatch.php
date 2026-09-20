<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

interface CareerJobDetailExposureReadinessBatch
{
    /**
     * @param  list<array{slug:string,locale:string}>  $targets
     * @return array<string,array{classification:string,payload:array<string,mixed>|null,version:string|null}>
     */
    public function jobDetailCacheReadinessBatch(array $targets, bool $includePayload = true): array;
}
