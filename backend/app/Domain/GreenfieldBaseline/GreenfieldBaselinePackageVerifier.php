<?php

declare(strict_types=1);

namespace App\Domain\GreenfieldBaseline;

use RuntimeException;

final class GreenfieldBaselinePackageVerifier
{
    /** @return array<string, mixed> */
    public function verify(
        string $packageDirectory,
        ?string $expectedPackageSha256 = null,
        bool $enforceProductionCounts = true,
    ): array {
        $manifestPath = rtrim($packageDirectory, '/').'/manifest.json';
        $checksumsPath = rtrim($packageDirectory, '/').'/checksums.sha256';
        if (! is_file($manifestPath) || ! is_file($checksumsPath)) {
            throw new RuntimeException('Greenfield package manifest or checksums are missing.');
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['schema_version'] ?? null) !== GreenfieldBaselineCatalog::PACKAGE_SCHEMA
            || ($manifest['writes_committed'] ?? null) !== false
            || ! is_array($manifest['dataset_counts'] ?? null)
            || ! is_array($manifest['files'] ?? null)) {
            throw new RuntimeException('Greenfield package manifest contract is invalid.');
        }

        $declaredPackageSha = (string) ($manifest['package_sha256'] ?? '');
        $manifestBase = $manifest;
        unset($manifestBase['package_sha256']);
        $computedPackageSha = hash('sha256', GreenfieldBaselineJson::encode($manifestBase));
        if (! hash_equals($declaredPackageSha, $computedPackageSha)
            || ($expectedPackageSha256 !== null && ! hash_equals($expectedPackageSha256, $computedPackageSha))) {
            throw new RuntimeException('Greenfield package SHA256 mismatch.');
        }

        $this->verifyFiles($packageDirectory, $manifest['files'], $checksumsPath);
        $observedCounts = $this->verifyDatasets(
            $packageDirectory,
            $manifest['dataset_counts'],
            $enforceProductionCounts,
        );
        $this->verifyCurrentRevisionBoundary($packageDirectory);
        $this->verifyCareerProjection($manifest['career_projection'] ?? null, $enforceProductionCounts);
        $this->verifyMedia(
            $packageDirectory,
            $manifest['media'] ?? null,
            $observedCounts,
            $enforceProductionCounts,
        );

        return [
            'status' => 'ready',
            'schema_version' => GreenfieldBaselineCatalog::PACKAGE_SCHEMA,
            'package_sha256' => $computedPackageSha,
            'dataset_counts' => $observedCounts,
            'career_projection' => $manifest['career_projection'],
            'media' => $manifest['media'],
            'forbidden_dataset_count' => 0,
            'forbidden_field_count' => 0,
            'writes_committed' => false,
        ];
    }

    /** @param array<string, array{sha256: string, bytes: int}> $files */
    private function verifyFiles(string $packageDirectory, array $files, string $checksumsPath): void
    {
        $actualFiles = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $packageDirectory,
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen(rtrim($packageDirectory, '/')) + 1);
            if (! in_array($relative, ['manifest.json', 'checksums.sha256'], true)) {
                $actualFiles[$relative] = true;
            }
        }
        $declaredFiles = array_fill_keys(array_keys($files), true);
        ksort($actualFiles, SORT_STRING);
        ksort($declaredFiles, SORT_STRING);
        if ($actualFiles !== $declaredFiles) {
            throw new RuntimeException('Greenfield package file inventory does not match the manifest.');
        }

        foreach ($files as $relative => $metadata) {
            if (! is_string($relative) || str_starts_with($relative, '/') || str_contains($relative, '..')) {
                throw new RuntimeException('Greenfield package contains an unsafe file path.');
            }
            $path = rtrim($packageDirectory, '/').'/'.$relative;
            if (! is_file($path)
                || ! hash_equals((string) ($metadata['sha256'] ?? ''), hash_file('sha256', $path))
                || (int) ($metadata['bytes'] ?? -1) !== filesize($path)) {
                throw new RuntimeException("Greenfield package file verification failed: {$relative}.");
            }
        }

        $checksumLines = file($checksumsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (! is_array($checksumLines)) {
            throw new RuntimeException('Greenfield checksums file is unreadable.');
        }
        $checksumPaths = [];
        foreach ($checksumLines as $line) {
            if (preg_match('/^(?<sha>[0-9a-f]{64})  (?<path>[^.][^\r\n]*)$/', $line, $matches) !== 1
                || str_contains($matches['path'], '..')) {
                throw new RuntimeException('Greenfield checksums file contains an invalid entry.');
            }
            $path = rtrim($packageDirectory, '/').'/'.$matches['path'];
            if (! is_file($path) || ! hash_equals($matches['sha'], hash_file('sha256', $path))) {
                throw new RuntimeException('Greenfield checksums verification failed.');
            }
            $checksumPaths[$matches['path']] = true;
        }
        $expectedChecksumPaths = $actualFiles + ['manifest.json' => true];
        ksort($checksumPaths, SORT_STRING);
        ksort($expectedChecksumPaths, SORT_STRING);
        if ($checksumPaths !== $expectedChecksumPaths) {
            throw new RuntimeException('Greenfield checksums inventory is incomplete.');
        }
    }

    /** @param array<string, int> $declaredCounts @return array<string, int> */
    private function verifyDatasets(
        string $packageDirectory,
        array $declaredCounts,
        bool $enforceProductionCounts,
    ): array {
        $allowed = array_column(GreenfieldBaselineCatalog::datasets(), 'name');
        $observed = [];
        foreach ($declaredCounts as $dataset => $declaredCount) {
            if (! is_string($dataset) || ! in_array($dataset, $allowed, true)
                || in_array($dataset, GreenfieldBaselineCatalog::forbiddenDatasetNames(), true)) {
                throw new RuntimeException('Greenfield manifest declares a forbidden dataset.');
            }
            $path = rtrim($packageDirectory, '/').'/datasets/'.$dataset.'.jsonl';
            if (! is_file($path)) {
                throw new RuntimeException("Greenfield dataset file is missing: {$dataset}.");
            }
            $count = 0;
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw new RuntimeException("Greenfield dataset file is unreadable: {$dataset}.");
            }
            try {
                while (($line = fgets($handle)) !== false) {
                    $row = json_decode(trim($line), true, flags: JSON_THROW_ON_ERROR);
                    if (! is_array($row)) {
                        throw new RuntimeException("Greenfield dataset row is invalid: {$dataset}.");
                    }
                    $this->assertNoForbiddenFields($dataset, $row);
                    $count++;
                }
            } finally {
                fclose($handle);
            }
            if ($count !== (int) $declaredCount) {
                throw new RuntimeException("Greenfield dataset count mismatch: {$dataset}.");
            }
            $observed[$dataset] = $count;
        }

        if ($enforceProductionCounts) {
            foreach (GreenfieldBaselineCatalog::expectedDatasetCounts() as $dataset => $expected) {
                if (($observed[$dataset] ?? 0) !== $expected) {
                    throw new RuntimeException("Greenfield dataset count mismatch: {$dataset}.");
                }
            }
        }
        ksort($observed, SORT_STRING);

        return $observed;
    }

    /** @param array<string, mixed> $row */
    private function assertNoForbiddenFields(string $dataset, array $row): void
    {
        foreach (GreenfieldBaselineCatalog::forbiddenFieldNames() as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                throw new RuntimeException("Forbidden field found in Greenfield package: {$dataset}.{$field}.");
            }
        }
        foreach ($row as $field => $value) {
            if ((str_ends_with((string) $field, '_admin_user_id')
                    || in_array($field, ['created_by', 'reviewed_by', 'created_by_admin_id'], true))
                && $value !== null) {
                throw new RuntimeException("Actor identifier found in Greenfield package: {$dataset}.{$field}.");
            }
        }
    }

    private function verifyCurrentRevisionBoundary(string $packageDirectory): void
    {
        $this->assertReferencedRevisionSet(
            $packageDirectory,
            'articles',
            'article_translation_revisions',
            'published_revision_id',
            'id',
        );
        $this->assertReferencedRevisionSet(
            $packageDirectory,
            'content_pages',
            'cms_translation_revisions',
            'published_revision_id',
            'id',
        );
        $this->assertReferencedRevisionSet(
            $packageDirectory,
            'personality_public_content_assets',
            'personality_public_content_asset_revisions',
            'published_revision_id',
            'id',
        );
    }

    private function assertReferencedRevisionSet(
        string $packageDirectory,
        string $parentDataset,
        string $revisionDataset,
        string $pointerField,
        string $revisionIdField,
    ): void {
        $parents = $this->readDataset($packageDirectory, $parentDataset);
        $revisions = $this->readDataset($packageDirectory, $revisionDataset);
        $expected = [];
        foreach ($parents as $row) {
            $id = (string) ($row[$pointerField] ?? '');
            if ($id !== '') {
                $expected[$id] = true;
            }
        }
        $actual = [];
        foreach ($revisions as $row) {
            $id = (string) ($row[$revisionIdField] ?? '');
            if ($id !== '') {
                $actual[$id] = true;
            }
        }
        ksort($expected, SORT_STRING);
        ksort($actual, SORT_STRING);
        if ($expected !== $actual) {
            throw new RuntimeException("Greenfield package contains non-current revisions for {$parentDataset}.");
        }
    }

    private function verifyCareerProjection(mixed $summary, bool $enforceProductionCounts): void
    {
        if (! is_array($summary)
            || preg_match('/^[0-9a-f]{64}$/', (string) ($summary['sha256'] ?? '')) !== 1) {
            throw new RuntimeException('Greenfield Career runtime projection boundary changed.');
        }
        if ($enforceProductionCounts
            && ((int) ($summary['tracked_slug_count'] ?? 0) !== 342
                || (int) ($summary['public_slug_count'] ?? 0) !== 30
                || (int) data_get($summary, 'state_counts.blocked', 0) !== 622
                || (int) data_get($summary, 'state_counts.quarantined', 0) !== 2)) {
            throw new RuntimeException('Greenfield Career runtime projection boundary changed.');
        }
    }

    private function verifyMedia(
        string $packageDirectory,
        mixed $summary,
        array $datasetCounts,
        bool $enforceProductionCounts,
    ): void {
        if (! is_array($summary) || (int) ($summary['entry_count'] ?? -1) < ($enforceProductionCounts ? 1 : 0)
            || preg_match('/^[0-9a-f]{64}$/', (string) ($summary['host_set_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('Greenfield media summary is invalid.');
        }
        $manifest = json_decode(
            (string) file_get_contents(rtrim($packageDirectory, '/').'/media/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $entries = is_array($manifest['entries'] ?? null) ? $manifest['entries'] : [];
        $expectedEntryCount = (int) ($datasetCounts['media_assets'] ?? 0)
            + (int) ($datasetCounts['media_variants'] ?? 0);
        if (count($entries) !== (int) $summary['entry_count']
            || count($entries) !== $expectedEntryCount) {
            throw new RuntimeException('Greenfield media manifest count mismatch.');
        }
        if (($summary['downloaded'] ?? false) === true) {
            foreach ($entries as $entry) {
                $relative = (string) ($entry['object_path'] ?? '');
                $path = rtrim($packageDirectory, '/').'/media/'.$relative;
                if (! str_starts_with($relative, 'objects/') || str_contains($relative, '..')
                    || ! is_file($path)
                    || ! hash_equals((string) ($entry['sha256'] ?? ''), hash_file('sha256', $path))
                    || (int) ($entry['bytes'] ?? -1) !== filesize($path)) {
                    throw new RuntimeException('Greenfield media object verification failed.');
                }
            }
        }
    }

    /** @return list<array<string, mixed>> */
    private function readDataset(string $packageDirectory, string $dataset): array
    {
        $rows = [];
        $path = rtrim($packageDirectory, '/').'/datasets/'.$dataset.'.jsonl';
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
