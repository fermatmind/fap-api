<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use App\Domain\Career\Display\CareerShardedCurrentAuthorityPackage;
use RuntimeException;

final class CareerContentV3Compiler
{
    public function __construct(
        private readonly CareerCurrentAuthorityPackage $legacyPackage,
        private readonly CareerContentV3Projector $projector,
        private readonly CareerContentV3AuthorityPackage $contentPackage,
    ) {}

    /** @return array<string,mixed> */
    public function compile(string $backendRoot, ?string $outputRoot = null): array
    {
        $manifestPath = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['contract_version'] ?? null) === CareerContentV3AuthorityPackage::CONTRACT_VERSION) {
            if ($outputRoot !== null) {
                throw new RuntimeException('CONTENT_V3_ALREADY_INSTALLED');
            }
            $authority = $this->contentPackage->load($backendRoot);

            return $this->receipt($authority['summary'], $manifest['set_hashes']['source_semantic_aggregate_sha256']);
        }
        if (($manifest['contract_version'] ?? null) !== CareerShardedCurrentAuthorityPackage::CONTRACT_VERSION) {
            throw new RuntimeException('CONTENT_V3_SOURCE_AUTHORITY_INVALID');
        }
        if ($outputRoot === null) {
            $temporary = $this->temporaryDirectory();
            try {
                return $this->compileSharded($backendRoot, $temporary);
            } finally {
                $this->deleteDirectory($temporary);
            }
        }

        return $this->compileSharded($backendRoot, $this->guardEmptyOutputRoot($outputRoot));
    }

    /** @return array<string,mixed> */
    private function compileSharded(string $backendRoot, string $outputRoot): array
    {
        $authority = $this->legacyPackage->loadMigrationSource($backendRoot);
        $pages = 0;
        $enhanced = 0;
        $legacy = 0;
        $blockCount = 0;
        $itemCount = 0;
        $files = [];
        $slugs = array_keys($authority['rows']);
        $localePageSet = [];
        $semanticHashes = [];
        $compatibilityHashes = [];

        foreach ($authority['rows'] as $slug => $row) {
            $localizedPages = is_array(data_get($row, 'page_payload_json.page'))
                ? data_get($row, 'page_payload_json.page')
                : ($row['page_payload_json'] ?? []);
            foreach (['en' => 'en', 'zh' => 'zh-CN'] as $pageLocale => $locale) {
                $page = is_array($localizedPages) ? ($localizedPages[$pageLocale] ?? null) : null;
                if (! is_array($page)) {
                    throw new CareerTenBlockCompileFailure('CONTENT_V3_LOCALE_PAGE_INVALID');
                }
                $surface = $this->legacyPackage->publicProjection($row, $locale);
                $content = $surface['content_v3'] ?? null;
                if (! is_array($content)) {
                    $presentation = data_get($row, 'metadata_json.presentation_v2.'.$pageLocale);
                    $content = $this->projector->project(
                        $slug,
                        $locale,
                        $page,
                        is_array($presentation) ? $presentation : null,
                        is_array($row['sources_json'] ?? null) ? $row['sources_json'] : [],
                    );
                }
                CareerContentV3Contract::assert($content);
                unset($surface['content_v3']);
                $relativePath = 'careers/'.$slug.'/'.$locale.'.json';
                $bytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($content);
                $absolute = $outputRoot.'/'.$relativePath;
                if (! is_dir(dirname($absolute)) && ! mkdir(dirname($absolute), 0755, true)) {
                    throw new RuntimeException('CONTENT_V3_OUTPUT_WRITE_FAILED');
                }
                $this->atomicWrite($absolute, $bytes);
                $projectionHash = CareerCurrentAuthorityPackage::hashValue($surface);
                $files[] = [
                    'bytes' => strlen($bytes),
                    'canonical_slug' => $slug,
                    'legacy_projection_sha256' => $projectionHash,
                    'legacy_row_sha256' => CareerCurrentAuthorityPackage::hashValue($row),
                    'locale' => $locale,
                    'path' => $relativePath,
                    'sha256' => hash('sha256', $bytes),
                    'source_content_sha256' => $content['source_content_sha256'],
                ];
                $localePageSet[] = $slug.'|'.$locale;
                $semanticHashes[] = $content['source_content_sha256'];
                $compatibilityHashes[] = $projectionHash;
                $content['content_state'] === 'enhanced' ? $enhanced++ : $legacy++;
                $blockCount += count($content['blocks']);
                foreach ($content['blocks'] as $block) {
                    $itemCount += count($block['items']);
                }
                $pages++;
            }
        }
        if (count($slugs) !== CareerCurrentAuthorityPackage::EXPECTED_CAREERS
            || $pages !== CareerCurrentAuthorityPackage::EXPECTED_LOCALE_PAGES
            || $enhanced !== 2 || $legacy !== CareerCurrentAuthorityPackage::EXPECTED_LOCALE_PAGES - 2) {
            throw new RuntimeException('CONTENT_V3_COVERAGE_INVALID');
        }
        sort($localePageSet, SORT_STRING);
        $sourceManifest = $authority['manifest'];
        $manifest = [
            'aggregate_sha256' => '',
            'authority_path' => 'backend/content_assets/career/current',
            'compiler_version' => CareerContentV3AuthorityPackage::COMPILER_VERSION,
            'contract_version' => CareerContentV3AuthorityPackage::CONTRACT_VERSION,
            'coverage' => [
                'slugs' => count($slugs),
                'locales' => count(CareerCurrentAuthorityPackage::LOCALES),
                'locale_pages' => $pages,
                'files' => count($files),
                'enhanced_locale_pages' => $enhanced,
                'legacy_locale_pages' => $legacy,
            ],
            'files' => $files,
            'locales' => CareerCurrentAuthorityPackage::LOCALES,
            'schema_version' => CareerContentV3AuthorityPackage::SCHEMA_VERSION,
            'set_hashes' => [
                'legacy_projection_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($compatibilityHashes),
                'legacy_versionless_projection_sha256' => (string) $authority['summary']['versionless_projection_sha256'],
                'locale_page_set_sha256' => CareerCurrentAuthorityPackage::hashValue($localePageSet),
                'slug_set_sha256' => CareerCurrentAuthorityPackage::hashValue($slugs),
                'source_semantic_aggregate_sha256' => CareerCurrentAuthorityPackage::hashValue($semanticHashes),
            ],
            'source_registry_sha256' => CareerCurrentAuthorityPackage::hashValue([
                'source_manifest_sha256' => hash_file('sha256', rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json'),
                'registries' => $sourceManifest['registries'] ?? [],
            ]),
        ];
        $projection = array_intersect_key($manifest, array_flip([
            'authority_path', 'compiler_version', 'contract_version', 'coverage', 'files', 'locales',
            'schema_version', 'set_hashes', 'source_registry_sha256',
        ]));
        $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($projection);
        $this->atomicWrite(
            $outputRoot.'/manifest.json',
            CareerCurrentAuthorityPackage::encodePrettyCanonical($manifest),
        );
        $installed = $this->contentPackage->loadRoot($outputRoot);

        return $this->receipt($installed['summary'], $manifest['set_hashes']['source_semantic_aggregate_sha256']) + [
            'block_count' => $blockCount,
            'item_count' => $itemCount,
            'legacy_projection_aggregate_sha256' => $manifest['set_hashes']['legacy_projection_aggregate_sha256'],
            'legacy_versionless_projection_sha256' => $manifest['set_hashes']['legacy_versionless_projection_sha256'],
        ];
    }

    /** @param array<string,mixed> $summary @return array<string,mixed> */
    private function receipt(array $summary, string $semanticHash): array
    {
        return [
            'contract_version' => 'career.detail.content.v3.compile_receipt.v1',
            'career_count' => $summary['career_count'],
            'locale_page_count' => $summary['locale_page_count'],
            'enhanced_locale_page_count' => $summary['enhanced_locale_page_count'],
            'legacy_locale_page_count' => $summary['legacy_locale_page_count'],
            'aggregate_sha256' => $summary['aggregate_sha256'],
            'source_semantic_aggregate_sha256' => $semanticHash,
            'database_writes' => 0,
            'cache_writes' => 0,
            'cms_writes' => 0,
            'discoverability_writes' => 0,
            'search_submissions' => 0,
        ];
    }

    private function guardEmptyOutputRoot(string $root): string
    {
        $resolved = is_link($root) ? false : realpath($root);
        $temporary = realpath(sys_get_temp_dir());
        $shared = realpath('/tmp');
        if (! is_string($resolved) || ! is_dir($resolved) || ! is_string($temporary)
            || (! str_starts_with($resolved.'/', rtrim($temporary, '/').'/')
                && (! is_string($shared) || ! str_starts_with($resolved.'/', rtrim($shared, '/').'/')))
            || (new \FilesystemIterator($resolved))->valid()) {
            throw new RuntimeException('CONTENT_V3_OUTPUT_ROOT_FORBIDDEN');
        }

        return $resolved;
    }

    private function temporaryDirectory(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'career-content-v3-');
        if (! is_string($path) || ! unlink($path) || ! mkdir($path, 0700)) {
            throw new RuntimeException('CONTENT_V3_OUTPUT_ROOT_CREATE_FAILED');
        }

        return $path;
    }

    private function atomicWrite(string $path, string $bytes): void
    {
        $temporary = $path.'.tmp';
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
            || ! rename($temporary, $path)) {
            throw new RuntimeException('CONTENT_V3_OUTPUT_WRITE_FAILED');
        }
    }

    private function deleteDirectory(string $root): void
    {
        $resolved = realpath($root);
        $temporary = realpath(sys_get_temp_dir());
        if (! is_string($resolved) || ! is_string($temporary) || ! str_starts_with($resolved, $temporary.'/')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($resolved, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($resolved);
    }
}
