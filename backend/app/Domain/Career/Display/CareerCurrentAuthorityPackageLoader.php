<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use JsonException;

class CareerCurrentAuthorityPackageLoader
{
    public function __construct(
        private readonly CareerCurrentAuthorityPackage $package,
        private readonly CareerShardedCurrentAuthorityPackage $shardedPackage,
    ) {}

    /** @return array<string,mixed> */
    public function load(string $backendRoot): array
    {
        $manifestPath = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json';
        if (! is_file($manifestPath) || is_link($manifestPath)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_PACKAGE_FILE_MISSING');
        }
        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_INVALID');
        }
        if (! is_array($manifest) || array_is_list($manifest)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_INVALID');
        }

        return match ($manifest['contract_version'] ?? null) {
            CareerCurrentAuthorityPackage::CONTRACT_VERSION => $this->package->load($backendRoot),
            CareerShardedCurrentAuthorityPackage::CONTRACT_VERSION => $this->shardedPackage->load($backendRoot, $manifest),
            default => throw new CareerCurrentAuthorityPackageFailure('CURRENT_MANIFEST_CONTRACT_UNSUPPORTED'),
        };
    }
}
