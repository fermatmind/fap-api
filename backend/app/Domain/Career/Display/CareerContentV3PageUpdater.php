<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use JsonException;
use Throwable;

final class CareerContentV3PageUpdater
{
    public function __construct(
        private readonly CareerContentV3AuthorityPackage $package,
        private readonly CareerContentV3FactResolver $factResolver,
        private readonly CareerCurrentAuthorityPackageLoader $loader,
    ) {}

    /** @return array<string,mixed> */
    public function update(string $backendRoot, string $slug, string $locale, bool $write): array
    {
        $slug = strtolower(trim($slug));
        $locale = $this->locale($locale);
        if (preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PAGE_UPDATE_IDENTITY_INVALID');
        }
        $currentRoot = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        $manifestPath = $currentRoot.'/manifest.json';
        $intentPath = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH;
        $manifest = $this->read($manifestPath, 'CURRENT_CONTENT_V3_MANIFEST_INVALID');
        $intent = $this->read($intentPath, 'CURRENT_RELEASE_INTENT_INVALID');
        $targetIndex = null;
        foreach ($manifest['files'] ?? [] as $index => $entry) {
            if (is_array($entry) && ($entry['canonical_slug'] ?? null) === $slug && ($entry['locale'] ?? null) === $locale) {
                $targetIndex = $index;
                break;
            }
        }
        if (! is_int($targetIndex)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PAGE_MISSING');
        }

        $pagePath = $currentRoot.'/'.$manifest['files'][$targetIndex]['path'];
        $page = $this->read($pagePath, 'CURRENT_CONTENT_V3_JSON_INVALID');
        if (($page['locale'] ?? null) !== $locale || data_get($page, 'subject.canonical_slug') !== $slug) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PAGE_UPDATE_IDENTITY_INVALID');
        }
        $semantic = $page;
        unset($semantic['source_content_sha256']);
        $page['source_content_sha256'] = CareerCurrentAuthorityPackage::hashValue($semantic);
        CareerContentV3Contract::assert($page);
        $this->factResolver->resolve($page);
        $pageBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($page);

        $manifest['files'][$targetIndex]['bytes'] = strlen($pageBytes);
        $manifest['files'][$targetIndex]['sha256'] = hash('sha256', $pageBytes);
        $manifest['files'][$targetIndex]['source_content_sha256'] = $page['source_content_sha256'];
        $semanticHashes = array_map(
            static fn (array $entry): string => (string) $entry['source_content_sha256'],
            $manifest['files'],
        );
        $manifest['set_hashes']['source_semantic_aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($semanticHashes);
        $manifest['source_registry_sha256'] = $this->sourceRegistryHash($currentRoot, $manifest, $slug, $locale, $page);
        $projection = array_intersect_key($manifest, array_flip([
            'authority_path', 'compiler_version', 'contract_version', 'coverage', 'files', 'locales',
            'schema_version', 'set_hashes', 'source_registry_sha256',
        ]));
        $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($projection);
        $manifestBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($manifest);

        $intent['aggregate_sha256'] = $manifest['aggregate_sha256'];
        $intent['manifest_sha256'] = hash('sha256', $manifestBytes);
        $intent['source_registry_sha256'] = $manifest['source_registry_sha256'];
        $intent['versionless_projection_sha256'] = $manifest['set_hashes']['legacy_versionless_projection_sha256'];
        $intent['slug_count'] = $manifest['coverage']['slugs'];
        $intent['locale_page_count'] = $manifest['coverage']['locale_pages'];
        $intent['file_count'] = $manifest['coverage']['files'];
        $intentBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($intent);

        $changed = ! hash_equals(hash('sha256', (string) file_get_contents($pagePath)), hash('sha256', $pageBytes))
            || ! hash_equals(hash('sha256', (string) file_get_contents($manifestPath)), hash('sha256', $manifestBytes))
            || ! hash_equals(hash('sha256', (string) file_get_contents($intentPath)), hash('sha256', $intentBytes));
        if ($write && $changed) {
            $updates = [
                $pagePath => $pageBytes,
                $manifestPath => $manifestBytes,
                $intentPath => $intentBytes,
            ];
            $originals = array_map(
                static fn (string $path): string => (string) file_get_contents($path),
                array_keys($updates),
            );
            try {
                foreach ($updates as $path => $bytes) {
                    $this->replaceAtomically($path, $bytes);
                }
                $this->package->manifestIndex($backendRoot);
                (new CareerCurrentAuthorityReleaseIntent($this->loader))->verify($backendRoot);
            } catch (Throwable) {
                foreach (array_keys($updates) as $index => $path) {
                    $this->replaceAtomically($path, $originals[$index]);
                }
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PAGE_UPDATE_POST_WRITE_INVALID');
            }
        }

        return [
            'status' => $write
                ? ($changed ? 'UPDATED_CAREER_CONTENT_V3_PAGE' : 'PASS_CAREER_CONTENT_V3_PAGE')
                : 'PASS_CAREER_CONTENT_V3_PAGE_DRY_RUN',
            'page' => $slug.'|'.$locale,
            'changed' => $changed,
            'written' => $write && $changed,
            'source_content_sha256' => $page['source_content_sha256'],
            'page_sha256' => hash('sha256', $pageBytes),
            'manifest_sha256' => hash('sha256', $manifestBytes),
            'aggregate_sha256' => $manifest['aggregate_sha256'],
            'source_registry_sha256' => $manifest['source_registry_sha256'],
            'release_intent_sha256' => hash('sha256', $intentBytes),
            'database_writes' => 0,
            'cache_writes' => 0,
            'discoverability_writes' => 0,
            'search_submissions' => 0,
        ];
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $candidate */
    private function sourceRegistryHash(string $currentRoot, array $manifest, string $slug, string $locale, array $candidate): string
    {
        $registries = [];
        foreach ($manifest['files'] as $entry) {
            $identity = $entry['canonical_slug'].'|'.$entry['locale'];
            $page = $entry['canonical_slug'] === $slug && $entry['locale'] === $locale
                ? $candidate
                : $this->read($currentRoot.'/'.$entry['path'], 'CURRENT_CONTENT_V3_JSON_INVALID');
            $sources = [];
            foreach ((array) ($page['blocks'] ?? []) as $block) {
                foreach ((array) ($block['items'] ?? []) as $item) {
                    if (($item['type'] ?? null) === 'sources') {
                        $sources = array_merge($sources, (array) data_get($item, 'data.entries', []));
                    }
                }
            }
            $registries[] = [$identity, CareerCurrentAuthorityPackage::hashValue($sources)];
        }

        return CareerCurrentAuthorityPackage::hashValue($registries);
    }

    /** @return array<string,mixed> */
    private function read(string $path, string $safeCode): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerCurrentAuthorityPackageFailure($safeCode);
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerCurrentAuthorityPackageFailure($safeCode);
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new CareerCurrentAuthorityPackageFailure($safeCode);
        }

        return $value;
    }

    private function replaceAtomically(string $path, string $bytes): void
    {
        $temporary = tempnam(dirname($path), '.career-content-v3-page-');
        if (! is_string($temporary)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PAGE_UPDATE_WRITE_FAILED');
        }
        try {
            $mode = fileperms($path);
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
                || ($mode !== false && ! chmod($temporary, $mode & 0777))
                || ! rename($temporary, $path)) {
                throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PAGE_UPDATE_WRITE_FAILED');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function locale(string $locale): string
    {
        return match (strtolower(trim($locale))) {
            'en' => 'en',
            'zh', 'zh-cn' => 'zh-CN',
            default => throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_PAGE_UPDATE_IDENTITY_INVALID'),
        };
    }
}
