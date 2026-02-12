<?php

declare(strict_types=1);

namespace App\Services\Content\V2;

use App\Internal\Content\ContentStoreV2Core;

final class ContentStoreV2
{
    private ContentStoreV2Core $core;

    public function __construct(array $chain, array $ctx = [], string $legacyDir = '')
    {
        $this->core = new ContentStoreV2Core($chain, $ctx, $legacyDir);
    }

    public function __call(string $name, array $arguments): mixed
    {
        return $this->core->{$name}(...$arguments);
    }
}
