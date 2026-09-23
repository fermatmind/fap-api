<?php

declare(strict_types=1);

namespace App\Domain\Career\Display;

use JsonException;
use Throwable;

final class CareerContentV3BatchUpdater
{
    private const MAX_SOURCE_BYTES = 16 * 1024 * 1024;

    private const MAX_BATCH_SOURCE_BYTES = 1536 * 1024 * 1024;

    public function __construct(
        private readonly CareerContentV3AuthorityPackage $package,
        private readonly CareerContentV3FactResolver $facts,
        private readonly CareerCurrentAuthorityReleaseIntent $releaseIntent,
    ) {}

    /**
     * @param  list<array{slug:string,locale:string,path:string,sha256:string,number?:int}>  $selection
     * @return array<string,mixed>
     */
    public function update(string $backendRoot, string $sourceRoot, array $selection, bool $write): array
    {
        if (is_link($sourceRoot)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_SOURCE_INVALID');
        }
        $sourceRoot = realpath($sourceRoot) ?: '';
        if ($sourceRoot === '' || ! is_dir($sourceRoot) || ! array_is_list($selection)
            || count($selection) < 1 || count($selection) > 2092
            || array_filter($selection, 'is_array') !== $selection) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_SELECTION_INVALID');
        }
        usort($selection, static fn (array $a, array $b): int => [$a['slug'] ?? '', $a['locale'] ?? '']
            <=> [$b['slug'] ?? '', $b['locale'] ?? '']);
        $currentRoot = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
        $scratchRoot = dirname($currentRoot);
        $manifestPath = $currentRoot.'/manifest.json';
        $intentPath = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityReleaseIntent::RELATIVE_PATH;
        $this->releaseIntent->verify($backendRoot);
        $manifest = $this->readJson($manifestPath);
        $intent = $this->readJson($intentPath);
        $entries = [];
        foreach ($manifest['files'] as $index => $entry) {
            $entries[$entry['canonical_slug'].'|'.$entry['locale']] = $index;
        }

        $seen = [];
        $numberToSlug = [];
        $slugToNumber = [];
        $prepared = [];
        $sourceBytes = 0;
        $staged = [];
        try {
            foreach ($selection as $row) {
                if (! is_array($row) || array_diff(array_keys($row), ['slug', 'locale', 'path', 'sha256', 'number']) !== []) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_SELECTION_INVALID');
                }
                $slug = $row['slug'] ?? null;
                $locale = $row['locale'] ?? null;
                $path = $row['path'] ?? null;
                $sha256 = $row['sha256'] ?? null;
                if (! is_string($slug) || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug) !== 1
                    || ! in_array($locale, CareerCurrentAuthorityPackage::LOCALES, true)
                    || $path !== 'careers/'.$slug.'/'.$locale.'.json'
                    || ! is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/', $sha256) !== 1
                    || (isset($row['number']) && (! is_int($row['number']) || $row['number'] < 1 || $row['number'] > 1046))) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_SELECTION_INVALID');
                }
                $identity = $slug.'|'.$locale;
                if (isset($seen[$identity]) || ! isset($entries[$identity])
                    || $manifest['files'][$entries[$identity]]['path'] !== $path) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_SCOPE_INVALID');
                }
                $seen[$identity] = true;
                if (isset($row['number'])) {
                    $number = $row['number'];
                    if ((isset($numberToSlug[$number]) && $numberToSlug[$number] !== $slug)
                        || (isset($slugToNumber[$slug]) && $slugToNumber[$slug] !== $number)) {
                        throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_INDEX_INVALID');
                    }
                    $numberToSlug[$number] = $slug;
                    $slugToNumber[$slug] = $number;
                }
                $source = $sourceRoot.'/'.$path;
                foreach (['careers', 'careers/'.$slug, $path] as $part) {
                    if (is_link($sourceRoot.'/'.$part)) {
                        throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_SOURCE_INVALID');
                    }
                }
                $real = realpath($source);
                if (! is_string($real) || ! str_starts_with($real, $sourceRoot.'/')
                    || ! is_file($source) || is_link($source) || filesize($source) > self::MAX_SOURCE_BYTES) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_SOURCE_INVALID');
                }
                $raw = file_get_contents($source);
                if (! is_string($raw) || ! hash_equals($sha256, hash('sha256', $raw))) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_SOURCE_HASH_MISMATCH');
                }
                $sourceBytes += strlen($raw);
                if ($sourceBytes > self::MAX_BATCH_SOURCE_BYTES) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_SIZE_EXCEEDED');
                }
                $page = $this->decode($raw);
                if (($page['locale'] ?? null) !== $locale || data_get($page, 'subject.canonical_slug') !== $slug) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_IDENTITY_INVALID');
                }
                $semantic = $page;
                unset($semantic['source_content_sha256']);
                $page['source_content_sha256'] = CareerCurrentAuthorityPackage::hashValue($semantic);
                CareerContentV3Contract::assert($page);
                $this->facts->resolve($page);
                $canonical = CareerCurrentAuthorityPackage::encodePrettyCanonical($page);
                $destination = $currentRoot.'/'.$path;
                $changed = ! hash_equals(hash_file('sha256', $destination), hash('sha256', $canonical));
                $prepared[$identity] = [
                    'path' => $path,
                    'destination' => $destination,
                    'sha256' => hash('sha256', $canonical),
                    'bytes' => strlen($canonical),
                    'source_content_sha256' => $page['source_content_sha256'],
                    'content_state' => $page['content_state'],
                    'has_public_body' => CareerContentV3CanonicalReader::sourceHasPublicBody($page),
                    'sources_sha256' => $this->sourcesHash($page),
                    'changed' => $changed,
                ];
                if ($write && $changed) {
                    $staged[$destination] = $this->temporary($destination, $canonical, $scratchRoot);
                }
                unset($page, $semantic, $canonical, $raw);
            }

            ksort($prepared, SORT_STRING);
            $counts = ['enhanced' => 0, 'legacy' => 0];
            $registries = [];
            foreach ($manifest['files'] as &$entry) {
                $identity = $entry['canonical_slug'].'|'.$entry['locale'];
                if (isset($prepared[$identity])) {
                    $metadata = $prepared[$identity];
                    $entry['bytes'] = $metadata['bytes'];
                    $entry['sha256'] = $metadata['sha256'];
                    $entry['source_content_sha256'] = $metadata['source_content_sha256'];
                } else {
                    $page = $this->readJson($currentRoot.'/'.$entry['path']);
                    $metadata = [
                        'content_state' => $page['content_state'] ?? null,
                        'has_public_body' => CareerContentV3CanonicalReader::sourceHasPublicBody($page),
                        'sources_sha256' => $this->sourcesHash($page),
                    ];
                    unset($page);
                }
                if (! isset($counts[$metadata['content_state']])) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_STATE_INVALID');
                }
                $counts[$metadata['content_state']]++;
                $entry['body_qualification'] = [
                    'version' => CareerContentV3CanonicalReader::BODY_QUALIFICATION_VERSION,
                    'source_content_sha256' => $entry['source_content_sha256'],
                    'has_public_body' => $metadata['has_public_body'],
                ];
                $registries[] = [$identity, $metadata['sources_sha256']];
            }
            unset($entry);
            $manifest['coverage']['enhanced_locale_pages'] = $counts['enhanced'];
            $manifest['coverage']['legacy_locale_pages'] = $counts['legacy'];
            $manifest['source_registry_sha256'] = CareerCurrentAuthorityPackage::hashValue($registries);
            $manifest['set_hashes']['source_semantic_aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue(
                array_column($manifest['files'], 'source_content_sha256'),
            );
            $projection = array_intersect_key($manifest, array_flip([
                'authority_path', 'compiler_version', 'contract_version', 'coverage', 'files', 'locales',
                'schema_version', 'set_hashes', 'source_registry_sha256', 'identity_aliases', 'identity_scopes',
            ]));
            $manifest['aggregate_sha256'] = CareerCurrentAuthorityPackage::hashValue($projection);
            $manifestBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($manifest);
            $intent['aggregate_sha256'] = $manifest['aggregate_sha256'];
            $intent['manifest_sha256'] = hash('sha256', $manifestBytes);
            $intent['source_registry_sha256'] = $manifest['source_registry_sha256'];
            $intent['versionless_projection_sha256'] = $manifest['set_hashes']['source_semantic_aggregate_sha256'];
            $intentBytes = CareerCurrentAuthorityPackage::encodePrettyCanonical($intent);
            $changedPages = array_keys(array_filter($prepared, static fn (array $row): bool => $row['changed']));
            $changed = $changedPages !== []
                || hash_file('sha256', $manifestPath) !== hash('sha256', $manifestBytes)
                || hash_file('sha256', $intentPath) !== hash('sha256', $intentBytes);
            if ($write && $changed) {
                if (hash_file('sha256', $manifestPath) !== hash('sha256', $manifestBytes)) {
                    $staged[$manifestPath] = $this->temporary($manifestPath, $manifestBytes, $scratchRoot);
                }
                if (hash_file('sha256', $intentPath) !== hash('sha256', $intentBytes)) {
                    $staged[$intentPath] = $this->temporary($intentPath, $intentBytes, $scratchRoot);
                }
                $this->apply($backendRoot, $staged);
            }

            return [
                'status' => $write ? 'PASS_CAREER_CONTENT_V3_BATCH_UPDATE' : 'PASS_CAREER_CONTENT_V3_BATCH_DRY_RUN',
                'selected_locale_pages' => count($prepared),
                'changed_locale_pages' => count($changedPages),
                'changed_pages' => $changedPages,
                'source_bytes' => $sourceBytes,
                'source_selection_sha256' => CareerCurrentAuthorityPackage::hashValue($selection),
                'aggregate_sha256' => $manifest['aggregate_sha256'],
                'manifest_sha256' => hash('sha256', $manifestBytes),
                'release_intent_sha256' => hash('sha256', $intentBytes),
                'written' => $write && $changed,
                'database_writes' => 0,
                'cache_writes' => 0,
                'discoverability_writes' => 0,
                'search_submissions' => 0,
            ];
        } finally {
            foreach ($staged as $temporary) {
                if (is_file($temporary)) {
                    unlink($temporary);
                }
            }
        }
    }

    /** @param array<string,string> $staged */
    private function apply(string $backendRoot, array $staged): void
    {
        $backups = [];
        $rollbackFailed = false;
        try {
            foreach ($staged as $destination => $temporary) {
                $backup = tempnam(dirname($backendRoot.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH), '.career-batch-backup-');
                if (! is_string($backup) || ! copy($destination, $backup)) {
                    if (is_string($backup) && is_file($backup)) {
                        unlink($backup);
                    }
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_BACKUP_FAILED');
                }
                $backups[$destination] = $backup;
                if (! rename($temporary, $destination)) {
                    throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_WRITE_FAILED');
                }
            }
            $this->releaseIntent->verify($backendRoot);
        } catch (Throwable) {
            foreach ($backups as $destination => $backup) {
                if (is_file($backup) && ! rename($backup, $destination)) {
                    $rollbackFailed = true;
                }
            }
            throw new CareerCurrentAuthorityPackageFailure($rollbackFailed
                ? 'CURRENT_CONTENT_V3_BATCH_ROLLBACK_FAILED'
                : 'CURRENT_CONTENT_V3_BATCH_POST_WRITE_INVALID');
        } finally {
            foreach ($backups as $backup) {
                if (! $rollbackFailed && is_file($backup)) {
                    unlink($backup);
                }
            }
        }
    }

    private function temporary(string $destination, string $bytes, string $scratchRoot): string
    {
        $temporary = tempnam($scratchRoot, '.career-batch-candidate-');
        if (! is_string($temporary) || file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_STAGE_FAILED');
        }
        $mode = fileperms($destination);
        if ($mode !== false) {
            chmod($temporary, $mode & 0777);
        }

        return $temporary;
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_FILE_INVALID');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_FILE_INVALID');
        }

        return $this->decode($bytes);
    }

    /** @return array<string,mixed> */
    private function decode(string $bytes): array
    {
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_JSON_INVALID');
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new CareerCurrentAuthorityPackageFailure('CURRENT_CONTENT_V3_BATCH_JSON_INVALID');
        }

        return $value;
    }

    /** @param array<string,mixed> $page */
    private function sourcesHash(array $page): string
    {
        $sources = [];
        foreach ((array) ($page['blocks'] ?? []) as $block) {
            foreach ((array) ($block['items'] ?? []) as $item) {
                if (($item['type'] ?? null) === 'sources') {
                    $sources = array_merge($sources, (array) data_get($item, 'data.entries', []));
                }
            }
        }

        return CareerCurrentAuthorityPackage::hashValue($sources);
    }
}
