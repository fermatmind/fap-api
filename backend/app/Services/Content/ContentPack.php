<?php

namespace App\Services\Content;

class ContentPack
{
    public function __construct(
        public string $packId,
        public string $scaleCode,
        public string $region,
        public string $locale,
        public string $version,
        public string $basePath,
        public array $manifest,
    ) {}

    public function assets(): array
    {
        return $this->manifest['assets'] ?? [];
    }

    public function fallbackPackIds(): array
    {
        return $this->manifest['fallback'] ?? [];
    }
}