<?php

declare(strict_types=1);

namespace App\DTO;

final class ContentPackResolved
{
    /**
     * @param array<string,mixed> $manifest
     * @param array<int,array<string,mixed>> $fallbackChain
     * @param array<string,callable> $loaders
     */
    public function __construct(
        public ContentPack $pack,
        public string $baseDir,
        public array $manifest,
        public array $fallbackChain,
        public array $loaders,
    ) {
    }
}
