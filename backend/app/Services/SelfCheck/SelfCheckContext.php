<?php

declare(strict_types=1);

namespace App\Services\SelfCheck;

final class SelfCheckContext
{
    public function __construct(
        public ?string $basePath,
        public ?string $pkgPath,
        public ?string $packId,
        public bool $strictAssets,
        public ?string $manifestPath = null,
        public array $manifest = [],
    ) {
    }

    public static function fromCommandOptions(array $opts): self
    {
        $basePath = self::toNullableString($opts['path'] ?? $opts['manifest'] ?? null);
        $pkgPath = self::toNullableString($opts['pkg'] ?? null);
        $packId = self::toNullableString($opts['pack_id'] ?? $opts['pack-id'] ?? null);

        return new self(
            $basePath,
            $pkgPath,
            $packId,
            (bool) ($opts['strict-assets'] ?? $opts['strict_assets'] ?? false)
        );
    }

    public function resolvePackRoot(): string
    {
        if (is_string($this->manifestPath) && $this->manifestPath !== '') {
            return dirname($this->manifestPath);
        }

        if (is_string($this->pkgPath) && $this->pkgPath !== '') {
            return (string) base_path('../content_packages/' . ltrim($this->pkgPath, '/'));
        }

        if (is_string($this->basePath) && $this->basePath !== '') {
            if (str_ends_with(strtolower($this->basePath), 'manifest.json')) {
                return dirname($this->basePath);
            }
            return $this->basePath;
        }

        return '';
    }

    public function getManifest(): array
    {
        return $this->manifest;
    }

    public function withManifestPath(string $manifestPath): self
    {
        $this->manifestPath = $manifestPath;
        return $this;
    }

    public function withManifest(array $manifest): self
    {
        $this->manifest = $manifest;
        return $this;
    }

    private static function toNullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $v = trim($value);
        return $v === '' ? null : $v;
    }
}
