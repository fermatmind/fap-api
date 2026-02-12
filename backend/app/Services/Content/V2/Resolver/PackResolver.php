<?php

declare(strict_types=1);

namespace App\Services\Content\V2\Resolver;

use App\Services\Content\ContentPack;
use App\Services\Content\V2\Contracts\PackResolverInterface;

final class PackResolver implements PackResolverInterface
{
    public function resolvePrimary(array $chain): ?ContentPack
    {
        foreach ($chain as $pack) {
            if ($pack instanceof ContentPack) {
                return $pack;
            }
        }

        return null;
    }
}
