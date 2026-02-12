<?php

declare(strict_types=1);

namespace App\Services\Content\V2\Contracts;

use App\Services\Content\ContentPack;

interface AssetLoaderInterface
{
    public function loadJson(ContentPack $pack, string $relativePath): ?array;
}
