<?php

declare(strict_types=1);

namespace App\Services\Content\V2\Contracts;

use App\Services\Content\ContentPack;

interface PackResolverInterface
{
    /**
     * @param array<int,ContentPack> $chain
     */
    public function resolvePrimary(array $chain): ?ContentPack;
}
