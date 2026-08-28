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

    /**
     * Validate the complete publish root without retaining page bodies.
     *
     * @return array{
     *   root:string,
     *   manifest:array<string,mixed>,
     *   entries:array<string,array<string,array<string,mixed>>>,
     *   slugs:list<string>,
     *   summary:array<string,mixed>
     * }
     */
    public function indexForPublish(string $backendRoot): array
    {
        $index = $this->contentV3Package->manifestIndex($backendRoot);
        $manifest = $index['manifest'];
        if (($manifest['contract_version'] ?? null) !== CareerContentV3AuthorityPackage::CONTRACT_VERSION) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_PUBLISH_CONTENT_V3_AUTHORITY_REQUIRED');
        }

        $coverage = $manifest['coverage'];
        $index['summary'] = [
            'aggregate_sha256' => $manifest['aggregate_sha256'],
            'assets_sha256' => $manifest['aggregate_sha256'],
            'career_count' => $coverage['slugs'],
            'file_count' => $coverage['files'],
            'locale_page_count' => $coverage['locale_pages'],
            'enhanced_locale_page_count' => $coverage['enhanced_locale_pages'],
            'legacy_locale_page_count' => $coverage['legacy_locale_pages'],
            'manifest_sha256' => hash_file('sha256', $index['root'].'/manifest.json'),
            'source_format' => 'content_v3_per_page',
            'slug_set_sha256' => $manifest['set_hashes']['slug_set_sha256'],
            'locale_page_set_sha256' => $manifest['set_hashes']['locale_page_set_sha256'],
            'versionless_projection_sha256' => $manifest['set_hashes']['legacy_versionless_projection_sha256'],
        ];

        return $index;
    }

    /**
     * @param  array{root:string,entries:array<string,array<string,array<string,mixed>>>}  $index
     * @return array<string,mixed>
     */
    public function pageFromPublishIndex(array $index, string $slug, string $locale): array
    {
        return $this->contentV3Package->pageFromIndex($index, $slug, $locale);
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
