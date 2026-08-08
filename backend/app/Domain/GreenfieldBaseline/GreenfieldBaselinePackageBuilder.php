<?php

declare(strict_types=1);

namespace App\Domain\GreenfieldBaseline;

use RuntimeException;

final class GreenfieldBaselinePackageBuilder
{
    /**
     * @return array<string, mixed>
     */
    public function build(
        string $streamPath,
        string $outputDirectory,
        string $expectedProjectionSha256,
        bool $downloadMedia = false,
        ?string $expectedMediaHostSetSha256 = null,
        bool $enforceProductionCounts = true,
    ): array {
        $this->assertSha256($expectedProjectionSha256, 'expected projection');
        if (! is_file($streamPath)) {
            throw new RuntimeException('Greenfield source stream does not exist.');
        }
        if (file_exists($outputDirectory)) {
            throw new RuntimeException('Greenfield package output already exists.');
        }
        if (! mkdir($outputDirectory.'/datasets', 0700, true) && ! is_dir($outputDirectory.'/datasets')) {
            throw new RuntimeException('Unable to create Greenfield package directory.');
        }
        if (! mkdir($outputDirectory.'/career', 0700, true) && ! is_dir($outputDirectory.'/career')) {
            throw new RuntimeException('Unable to create Greenfield career directory.');
        }
        if (! mkdir($outputDirectory.'/media', 0700, true) && ! is_dir($outputDirectory.'/media')) {
            throw new RuntimeException('Unable to create Greenfield media directory.');
        }

        $allowed = [];
        foreach (GreenfieldBaselineCatalog::datasets() as $definition) {
            $allowed[(string) $definition['name']] = true;
        }

        $stream = fopen($streamPath, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Unable to open Greenfield source stream.');
        }

        $handles = [];
        $header = null;
        $footer = null;
        $counts = [];
        $mediaRows = [];
        $projectionSha = null;
        $lineNumber = 0;

        try {
            while (($line = fgets($stream)) !== false) {
                $lineNumber++;
                $payload = json_decode(trim($line), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($payload)) {
                    throw new RuntimeException("Invalid Greenfield stream record at line {$lineNumber}.");
                }
                $type = (string) ($payload['type'] ?? '');

                if ($type === 'header') {
                    if ($header !== null || $lineNumber !== 1) {
                        throw new RuntimeException('Greenfield stream contains an invalid header position.');
                    }
                    $header = $payload;

                    continue;
                }

                if ($type === 'row') {
                    $dataset = (string) ($payload['dataset'] ?? '');
                    $row = $payload['row'] ?? null;
                    if (! isset($allowed[$dataset]) || ! is_array($row)) {
                        throw new RuntimeException('Greenfield stream contains a non-allowlisted dataset row.');
                    }
                    $this->assertSafeRow($dataset, $row);
                    if (! isset($handles[$dataset])) {
                        $path = $outputDirectory.'/datasets/'.$dataset.'.jsonl';
                        $handles[$dataset] = fopen($path, 'xb');
                        if ($handles[$dataset] === false) {
                            throw new RuntimeException("Unable to create dataset file {$dataset}.");
                        }
                    }
                    fwrite($handles[$dataset], GreenfieldBaselineJson::encode($row)."\n");
                    $counts[$dataset] = ($counts[$dataset] ?? 0) + 1;
                    if ($dataset === 'media_assets' || $dataset === 'media_variants') {
                        $mediaRows[] = ['dataset' => $dataset, 'row' => $row];
                    }

                    continue;
                }

                if ($type === 'artifact' && ($payload['name'] ?? null) === 'career_runtime_publish_projection') {
                    $bytes = base64_decode((string) ($payload['content_base64'] ?? ''), true);
                    if (! is_string($bytes)) {
                        throw new RuntimeException('Career runtime projection base64 is invalid.');
                    }
                    $projectionSha = hash('sha256', $bytes);
                    if (! hash_equals((string) ($payload['sha256'] ?? ''), $projectionSha)
                        || ! hash_equals($expectedProjectionSha256, $projectionSha)) {
                        throw new RuntimeException('Career runtime projection SHA256 mismatch.');
                    }
                    file_put_contents(
                        $outputDirectory.'/career/'.GreenfieldBaselineCatalog::PROJECTION_FILENAME,
                        $bytes,
                        LOCK_EX,
                    );

                    continue;
                }

                if ($type === 'footer') {
                    if ($footer !== null) {
                        throw new RuntimeException('Greenfield stream contains multiple footers.');
                    }
                    $footer = $payload;

                    continue;
                }

                throw new RuntimeException("Unsupported Greenfield stream record at line {$lineNumber}.");
            }
        } finally {
            fclose($stream);
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        $this->assertEnvelope($header, $footer, $counts, $projectionSha, $enforceProductionCounts);
        $this->writeMediaManifest($outputDirectory, $mediaRows);
        $mediaSummary = (new GreenfieldBaselineMediaExporter)->export(
            $outputDirectory.'/media/manifest.json',
            $outputDirectory.'/media',
            $downloadMedia,
            $expectedMediaHostSetSha256,
        );
        $this->createMissingEmptyDatasets($outputDirectory, $allowed, $counts);
        ksort($counts, SORT_STRING);

        $files = $this->fileInventory($outputDirectory);
        $manifestBase = [
            'schema_version' => GreenfieldBaselineCatalog::PACKAGE_SCHEMA,
            'source' => [
                'active_revision' => (string) $header['active_revision'],
                'database_name_sha256' => (string) $header['source_database_name_sha256'],
                'stream_sha256' => hash_file('sha256', $streamPath),
            ],
            'career_projection' => $this->projectionSummary(
                $outputDirectory.'/career/'.GreenfieldBaselineCatalog::PROJECTION_FILENAME,
            ),
            'dataset_counts' => $counts,
            'media' => $mediaSummary,
            'files' => $files,
            'exclusions' => GreenfieldBaselineCatalog::forbiddenDatasetNames(),
            'writes_committed' => false,
        ];
        $packageSha = hash('sha256', GreenfieldBaselineJson::encode($manifestBase));
        $manifest = $manifestBase + ['package_sha256' => $packageSha];
        file_put_contents(
            $outputDirectory.'/manifest.json',
            GreenfieldBaselineJson::encode($manifest, true)."\n",
            LOCK_EX,
        );
        $this->writeChecksums($outputDirectory);

        return $manifest;
    }

    /** @param array<string, mixed> $row */
    private function assertSafeRow(string $dataset, array $row): void
    {
        if (in_array($dataset, GreenfieldBaselineCatalog::forbiddenDatasetNames(), true)) {
            throw new RuntimeException("Forbidden dataset entered Greenfield stream: {$dataset}.");
        }
        foreach (GreenfieldBaselineCatalog::forbiddenFieldNames() as $field) {
            if (array_key_exists($field, $row) && $row[$field] !== null && $row[$field] !== '') {
                throw new RuntimeException("Forbidden field entered Greenfield stream: {$dataset}.{$field}.");
            }
        }
        foreach ($row as $field => $value) {
            if ((str_ends_with((string) $field, '_admin_user_id')
                    || in_array($field, ['created_by', 'reviewed_by', 'created_by_admin_id'], true))
                && $value !== null) {
                throw new RuntimeException("Actor identifier was not cleared: {$dataset}.{$field}.");
            }
        }
    }

    /**
     * @param  array<string, mixed>|null  $header
     * @param  array<string, mixed>|null  $footer
     * @param  array<string, int>  $counts
     */
    private function assertEnvelope(
        ?array $header,
        ?array $footer,
        array $counts,
        ?string $projectionSha,
        bool $enforceProductionCounts,
    ): void {
        if (($header['schema_version'] ?? null) !== GreenfieldBaselineCatalog::STREAM_SCHEMA
            || ($header['writes_committed'] ?? null) !== false
            || preg_match('/^[0-9a-f]{40}$/', (string) ($header['active_revision'] ?? '')) !== 1
            || preg_match('/^[0-9a-f]{64}$/', (string) ($header['source_database_name_sha256'] ?? '')) !== 1) {
            throw new RuntimeException('Greenfield stream header is invalid.');
        }
        if (! is_array($footer) || ($footer['writes_committed'] ?? null) !== false || ! is_array($footer['counts'] ?? null)) {
            throw new RuntimeException('Greenfield stream footer is invalid.');
        }
        $footerCounts = array_map('intval', $footer['counts']);
        ksort($footerCounts, SORT_STRING);
        $observed = $counts;
        foreach ($footerCounts as $dataset => $count) {
            $observed[(string) $dataset] ??= 0;
        }
        ksort($observed, SORT_STRING);
        if ($footerCounts !== $observed) {
            throw new RuntimeException('Greenfield stream dataset counts do not match footer.');
        }
        if ($projectionSha === null) {
            throw new RuntimeException('Greenfield stream omitted the Career runtime projection.');
        }

        if ($enforceProductionCounts) {
            foreach (GreenfieldBaselineCatalog::expectedDatasetCounts() as $dataset => $expected) {
                if (($observed[$dataset] ?? 0) !== $expected) {
                    throw new RuntimeException("Greenfield dataset {$dataset} must contain exactly {$expected} rows.");
                }
            }
        }
    }

    /** @param array<string, bool> $allowed @param array<string, int> $counts */
    private function createMissingEmptyDatasets(string $outputDirectory, array $allowed, array &$counts): void
    {
        foreach (array_keys($allowed) as $dataset) {
            $counts[$dataset] ??= 0;
            $path = $outputDirectory.'/datasets/'.$dataset.'.jsonl';
            if (! is_file($path)) {
                file_put_contents($path, '', LOCK_EX);
            }
        }
    }

    /** @param list<array{dataset: string, row: array<string, mixed>}> $mediaRows */
    private function writeMediaManifest(string $outputDirectory, array $mediaRows): void
    {
        $entries = [];
        foreach ($mediaRows as $item) {
            $row = $item['row'];
            $entries[] = [
                'dataset' => $item['dataset'],
                'id' => $row['id'] ?? null,
                'media_asset_id' => $row['media_asset_id'] ?? ($row['id'] ?? null),
                'asset_key' => $row['asset_key'] ?? null,
                'variant_key' => $row['variant_key'] ?? null,
                'url' => $row['url'] ?? null,
                'path' => $row['path'] ?? null,
                'expected_bytes' => isset($row['bytes']) ? (int) $row['bytes'] : null,
            ];
        }
        usort($entries, static fn (array $left, array $right): int => strcmp(
            GreenfieldBaselineJson::encode($left),
            GreenfieldBaselineJson::encode($right),
        ));
        file_put_contents(
            $outputDirectory.'/media/manifest.json',
            GreenfieldBaselineJson::encode([
                'schema_version' => 'fermatmind.greenfield.public-media.v1',
                'entries' => $entries,
            ], true)."\n",
            LOCK_EX,
        );
    }

    /** @return array<string, array{sha256: string, bytes: int}> */
    private function fileInventory(string $outputDirectory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
            $outputDirectory,
            \FilesystemIterator::SKIP_DOTS,
        ));
        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($outputDirectory) + 1);
            if (in_array($relative, ['manifest.json', 'checksums.sha256'], true)) {
                continue;
            }
            $files[$relative] = [
                'sha256' => hash_file('sha256', $file->getPathname()),
                'bytes' => $file->getSize(),
            ];
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    /** @return array<string, mixed> */
    private function projectionSummary(string $path): array
    {
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
        $slugs = [];
        $states = [];
        $publicSlugs = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $slug = strtolower(trim((string) ($item['slug'] ?? '')));
            $state = (string) ($item['runtime_publish_state'] ?? 'unknown');
            if ($slug !== '') {
                $slugs[$slug] = true;
                if ($state === 'published' && ($item['detail_route_enabled'] ?? false) === true) {
                    $publicSlugs[$slug] = true;
                }
            }
            $states[$state] = ($states[$state] ?? 0) + 1;
        }
        ksort($states, SORT_STRING);

        return [
            'sha256' => hash_file('sha256', $path),
            'item_count' => count($items),
            'tracked_slug_count' => count($slugs),
            'public_slug_count' => count($publicSlugs),
            'state_counts' => $states,
        ];
    }

    private function writeChecksums(string $outputDirectory): void
    {
        $inventory = $this->fileInventory($outputDirectory);
        $inventory['manifest.json'] = [
            'sha256' => hash_file('sha256', $outputDirectory.'/manifest.json'),
            'bytes' => filesize($outputDirectory.'/manifest.json'),
        ];
        ksort($inventory, SORT_STRING);
        $lines = [];
        foreach ($inventory as $path => $metadata) {
            $lines[] = $metadata['sha256'].'  '.$path;
        }
        file_put_contents($outputDirectory.'/checksums.sha256', implode("\n", $lines)."\n", LOCK_EX);
    }

    private function assertSha256(string $value, string $label): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new RuntimeException("Invalid {$label} SHA256.");
        }
    }
}
