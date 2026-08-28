<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use JsonException;

class CareerCurrentAuthorityPackageLoader
{
    public function __construct(private readonly CareerContentV3AuthorityPackage $contentV3Package) {}

    /** @return array<string,mixed> */
    public function load(string $backendRoot): array
    {
        $manifest = $this->readManifest($backendRoot);

        if (($manifest['contract_version'] ?? null) !== CareerContentV3AuthorityPackage::CONTRACT_VERSION) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_AUTHORITY_REQUIRED');
        }

        return $this->contentV3Package->load($backendRoot);
    }

    /** @return array<string,mixed> */
    public function loadForPublish(string $backendRoot): array
    {
        $manifest = $this->readManifest($backendRoot);
        if (($manifest['contract_version'] ?? null) === CareerContentV3AuthorityPackage::CONTRACT_VERSION) {
            $authority = $this->contentV3Package->load($backendRoot);
            if (($authority['summary']['source_format'] ?? null) !== 'content_v3_per_page'
                || ! hash_equals(
                    (string) ($manifest['aggregate_sha256'] ?? ''),
                    (string) ($authority['summary']['aggregate_sha256'] ?? ''),
                )) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_PUBLISH_CONTENT_V3_AUTHORITY_INVALID');
            }

            return $authority;
        }
        throw new CareerCurrentAuthorityPackageFailure('CURRENT_PUBLISH_CONTENT_V3_AUTHORITY_REQUIRED');
    }

    /** @return array<string,mixed> */
    private function readManifest(string $backendRoot): array
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

        return $manifest;
    }
}
