<?php

declare(strict_types=1);

namespace App\Services\Content\V2\Loader;

use App\Services\Content\ContentPack;
use App\Services\Content\V2\Contracts\AssetLoaderInterface;

final class StorageAssetLoader implements AssetLoaderInterface
{
    public function loadJson(ContentPack $pack, string $relativePath): ?array
    {
        $relativePath = ltrim($relativePath, '/');
        $absPath = rtrim($pack->basePath(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $relativePath;
        if (!is_file($absPath)) {
            return null;
        }

        $raw = file_get_contents($absPath);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}
