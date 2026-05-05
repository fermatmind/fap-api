<?php

declare(strict_types=1);

namespace App\Services\BigFive\ResultPageV2\ContentAssets;

final readonly class BigFiveV2AssetInventory
{
    /**
     * @param  list<BigFiveV2AssetPackage>  $packages
     * @param  list<string>  $errors
     */
    public function __construct(
        public string $rootPath,
        public string $rootRelativePath,
        public array $packages,
        public array $errors,
    ) {}

    public function isValid(): bool
    {
        if ($this->errors !== []) {
            return false;
        }

        foreach ($this->packages as $package) {
            if ($package->errors !== []) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'root_path' => $this->rootPath,
            'root_relative_path' => $this->rootRelativePath,
            'package_count' => count($this->packages),
            'file_count' => array_sum(array_map(
                static fn (BigFiveV2AssetPackage $package): int => $package->fileCount,
                $this->packages
            )),
            'valid' => $this->isValid(),
            'errors' => $this->errors,
            'packages' => array_map(
                static fn (BigFiveV2AssetPackage $package): array => $package->toArray(),
                $this->packages
            ),
        ];
    }
}
