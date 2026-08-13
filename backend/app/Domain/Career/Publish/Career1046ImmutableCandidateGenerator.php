<?php

declare(strict_types=1);

namespace App\Domain\Career\Publish;

use App\Console\Commands\CareerPublicResolutionTypeMatrix;
use RuntimeException;

final class Career1046ImmutableCandidateGenerator
{
    public const SCHEMA_VERSION = 'career.1046.immutable_candidate.v2';

    public const MANIFEST_SHA256 = 'ef4d43eeaa0300534b36fd77d7806bcbe065de1fb13f158ceda1517f259207c5';

    public const BASELINE_SET_SHA256 = '39cc766fb18c85d385b83f0ac1f56a8b97d46481d3e9a12de0588abbaf640060';

    public const RECEIPT_SET_SHA256 = '09ec67befe967e1619a40578c47b862743883717b048da802ee7ef3551a0747f';

    public const TARGET_SET_SHA256 = '3b101fb76b5666200c73519c650beb1a5b0b35f47f7592453bf5671920571a18';

    public const TARGET_LOCALE_ROW_SET_SHA256 = 'c9878e76c817cc09448c32b1dcba3152b22821af34a31204840eb77a2d65857e';

    public const BASELINE_COUNT = 30;

    public const RECEIPT_COUNT = 1016;

    public const TARGET_COUNT = 1046;

    public const TARGET_LOCALE_ROW_COUNT = 2092;

    /** @var list<string> */
    public const FORBIDDEN_SLUGS = [
        'database-administrators-and-architects',
        'software-developers',
    ];

    /**
     * @param  list<string>  $baselineAuthoritySlugs
     * @param  list<string>  $databaseMatchingReceiptSlugs
     * @param  list<array<string, mixed>>  $detailRows
     * @return array<string, mixed>
     */
    public function generate(
        string $manifestPath,
        array $baselineAuthoritySlugs,
        array $databaseMatchingReceiptSlugs,
        array $ledger,
        array $projection,
        array $detailRows,
    ): array {
        $manifest = $this->readFrozenManifest($manifestPath);
        $baseline = $this->exactSlugSet($manifest['baseline_slugs'] ?? null, 'manifest_baseline');
        $receipts = $this->exactSlugSet($manifest['delta_slugs'] ?? null, 'manifest_delta');
        $target = $this->union($baseline, $receipts);
        $targetLocaleRows = $this->expectedLocaleRows($target);

        $this->assertFrozenSet($baseline, self::BASELINE_COUNT, self::BASELINE_SET_SHA256, 'baseline');
        $this->assertFrozenSet($receipts, self::RECEIPT_COUNT, self::RECEIPT_SET_SHA256, 'receipt');
        $this->assertFrozenSet($target, self::TARGET_COUNT, self::TARGET_SET_SHA256, 'target');
        $this->assertFrozenSet(
            $targetLocaleRows,
            self::TARGET_LOCALE_ROW_COUNT,
            self::TARGET_LOCALE_ROW_SET_SHA256,
            'target_locale_row',
        );
        $this->assertNoForbiddenSlugs($target, 'manifest');

        $this->assertExactAuthoritySet($baselineAuthoritySlugs, $baseline, 'baseline_authority');
        $this->assertExactAuthoritySet($databaseMatchingReceiptSlugs, $receipts, 'database_receipt_authority');

        $ledgerSlugs = $this->ledgerSlugs($ledger);
        $this->assertExactSet($ledgerSlugs, $target, 'ledger');

        [$projectionRows, $projectionSlugs, $projectionLocaleRows] = $this->projectionRows($projection);
        $this->assertExactSet($projectionSlugs, $target, 'projection');
        $this->assertExactSet($projectionLocaleRows, $targetLocaleRows, 'projection_locale_rows');

        [$detailsByLocale, $detailLocaleRows] = $this->detailRows($detailRows);
        $this->assertExactSet($detailLocaleRows, $targetLocaleRows, 'detail_locale_rows');

        $authority = [
            'frozen_manifest_sha256' => self::MANIFEST_SHA256,
            'baseline_set_sha256' => self::BASELINE_SET_SHA256,
            'receipt_set_sha256' => self::RECEIPT_SET_SHA256,
            'target_slug_set_sha256' => self::TARGET_SET_SHA256,
            'target_locale_row_set_sha256' => self::TARGET_LOCALE_ROW_SET_SHA256,
        ];
        $generationSeed = [
            'schema_version' => self::SCHEMA_VERSION,
            'authority' => $authority,
            'ledger' => $ledger,
            'projection_items' => $projectionRows,
            'detail_rows' => $detailsByLocale,
        ];
        $generationId = 'career-1046-'.substr(CareerGenerationCanonicalJson::sha256($generationSeed), 0, 32);
        $generationAuthority = [
            'frozen_manifest_sha256' => self::MANIFEST_SHA256,
            'target_slug_set_sha256' => self::TARGET_SET_SHA256,
            'target_locale_row_set_sha256' => self::TARGET_LOCALE_ROW_SET_SHA256,
            'receipt_set_sha256' => self::RECEIPT_SET_SHA256,
        ];

        $ledgerDocument = [
            ...$ledger,
            'generation_id' => $generationId,
            'artifact_identity' => 'career-full-release-ledger@'.$generationId,
            'generation_authority' => $generationAuthority,
        ];
        $projectionDocument = [
            ...$projection,
            'generation_id' => $generationId,
            'artifact_identity' => 'career-runtime-publish-projection@'.$generationId,
            'generation_authority' => $generationAuthority,
            'counts' => $this->projectionCounts($projectionRows),
            'items' => $projectionRows,
        ];
        $detailDocuments = [];
        $directoryDocuments = [];
        foreach (['en', 'zh'] as $locale) {
            $detailDocuments[$locale] = [
                'schema_version' => 'career.job_detail_generation.v1',
                'generation_id' => $generationId,
                'artifact_identity' => 'career-job-details-'.$locale.'@'.$generationId,
                'locale' => $locale,
                'count' => self::TARGET_COUNT,
                'items' => $detailsByLocale[$locale],
            ];
            $directoryDocuments[$locale] = [
                'schema_version' => 'career.directory_generation.v1',
                'generation_id' => $generationId,
                'artifact_identity' => 'career-directory-'.$locale.'@'.$generationId,
                'locale' => $locale,
                'public_count' => self::TARGET_COUNT,
                'items' => array_map(
                    static fn (array $row): array => [
                        'slug' => $row['slug'],
                        'locale' => $locale,
                        'canonical_path' => '/'.$locale.'/career/jobs/'.$row['slug'],
                        'detail_sha256' => CareerGenerationCanonicalJson::sha256($row['payload']),
                    ],
                    $detailsByLocale[$locale],
                ),
            ];
        }

        $documents = [
            CareerRuntimePublishProjectionExporter::PROJECTION_FILENAME => $projectionDocument,
            CareerFullReleaseLedgerProjectionService::LEDGER_FILENAME => $ledgerDocument,
            'career-job-details-en.json' => $detailDocuments['en'],
            'career-job-details-zh.json' => $detailDocuments['zh'],
            'career-directory-en.json' => $directoryDocuments['en'],
            'career-directory-zh.json' => $directoryDocuments['zh'],
        ];
        $artifactDescriptors = [];
        foreach ($documents as $path => $document) {
            $bytes = CareerGenerationCanonicalJson::encode($document)."\n";
            $artifactDescriptors[$path] = [
                'sha256' => hash('sha256', $bytes),
                'bytes' => strlen($bytes),
            ];
        }

        $generationManifest = [
            'schema_version' => 'career.generation_manifest.v1',
            'generation_id' => $generationId,
            'authority' => $authority,
            'counts' => [
                'unique_slugs' => self::TARGET_COUNT,
                'locale_rows' => self::TARGET_LOCALE_ROW_COUNT,
                'published_slugs' => self::TARGET_COUNT,
                'published_locale_rows' => self::TARGET_LOCALE_ROW_COUNT,
                'missing' => 0,
                'duplicate' => 0,
                'outside_target' => 0,
            ],
            'discoverability' => [
                'sitemap_released' => false,
                'llms_released' => false,
                'search_submission_enabled' => false,
            ],
            'artifacts' => $artifactDescriptors,
        ];
        $documents['generation-manifest.json'] = $generationManifest;
        $receipt = [
            'schema_version' => self::SCHEMA_VERSION,
            'generation_id' => $generationId,
            'generation_manifest_sha256' => CareerGenerationCanonicalJson::sha256($generationManifest),
            'authority' => $authority,
            'counts' => $generationManifest['counts'],
            'immutable_candidate_only' => true,
            'active_pointer_written' => false,
            'published' => false,
            'warmed' => false,
            'production_workflow_triggered' => false,
        ];
        $documents['candidate-receipt.json'] = $receipt;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generation_id' => $generationId,
            'authority' => $authority,
            'counts' => $generationManifest['counts'],
            'documents' => $documents,
            'candidate_receipt' => $receipt,
        ];
    }

    /** @param array<string, mixed> $candidate @return array<string, mixed> */
    public function materializeImmutable(string $root, array $candidate): array
    {
        $generationId = $this->requiredGenerationId($candidate['generation_id'] ?? null);
        $documents = $candidate['documents'] ?? null;
        if (! is_array($documents) || $documents === []) {
            throw new RuntimeException('career_1046_candidate_documents_invalid');
        }
        if (is_link($root)) {
            throw new RuntimeException('career_1046_candidate_root_symlink_forbidden');
        }
        if (! is_dir($root) && ! mkdir($root, 0750, true) && ! is_dir($root)) {
            throw new RuntimeException('career_1046_candidate_root_create_failed');
        }
        $rootReal = realpath($root);
        if (! is_string($rootReal)) {
            throw new RuntimeException('career_1046_candidate_root_invalid');
        }

        $candidateRoot = $rootReal.DIRECTORY_SEPARATOR.'candidates';
        if (! is_dir($candidateRoot) && ! mkdir($candidateRoot, 0750) && ! is_dir($candidateRoot)) {
            throw new RuntimeException('career_1046_candidate_container_create_failed');
        }
        $final = $candidateRoot.DIRECTORY_SEPARATOR.$generationId;
        if (file_exists($final) || is_link($final)) {
            throw new RuntimeException('career_1046_candidate_no_clobber');
        }
        $temporary = $candidateRoot.DIRECTORY_SEPARATOR.'.'.$generationId.'.'.bin2hex(random_bytes(8));
        if (! mkdir($temporary, 0750)) {
            throw new RuntimeException('career_1046_candidate_temporary_create_failed');
        }

        try {
            $readback = [];
            foreach ($documents as $filename => $document) {
                if (! is_string($filename) || preg_match('/^[a-z0-9][a-z0-9.-]{0,127}\.json$/', $filename) !== 1 || ! is_array($document)) {
                    throw new RuntimeException('career_1046_candidate_document_invalid');
                }
                $bytes = CareerGenerationCanonicalJson::encode($document)."\n";
                $path = $temporary.DIRECTORY_SEPARATOR.$filename;
                $handle = fopen($path, 'x');
                if ($handle === false) {
                    throw new RuntimeException('career_1046_candidate_document_create_failed');
                }
                try {
                    if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle)) {
                        throw new RuntimeException('career_1046_candidate_document_write_failed');
                    }
                } finally {
                    fclose($handle);
                }
                $actual = file_get_contents($path);
                if (! is_string($actual) || ! hash_equals(hash('sha256', $bytes), hash('sha256', $actual))) {
                    throw new RuntimeException('career_1046_candidate_document_readback_failed');
                }
                $readback[$filename] = hash('sha256', $actual);
            }
            if (! rename($temporary, $final)) {
                throw new RuntimeException('career_1046_candidate_finalize_failed');
            }

            return [
                'generation_id' => $generationId,
                'candidate_relative_path' => 'candidates/'.$generationId,
                'document_sha256' => $readback,
                'active_pointer_written' => false,
            ];
        } catch (\Throwable $failure) {
            $this->removeTemporaryDirectory($temporary);

            throw $failure;
        }
    }

    /** @return array<string, mixed> */
    private function readFrozenManifest(string $path): array
    {
        if ($path === '' || is_link($path) || ! is_file($path)) {
            throw new RuntimeException('career_1046_manifest_path_invalid');
        }
        $bytes = file_get_contents($path);
        if (! is_string($bytes) || ! hash_equals(self::MANIFEST_SHA256, hash('sha256', $bytes))) {
            throw new RuntimeException('career_1046_manifest_sha256_mismatch');
        }
        $manifest = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($manifest)) {
            throw new RuntimeException('career_1046_manifest_shape_invalid');
        }

        return $manifest;
    }

    /** @return list<string> */
    private function exactSlugSet(mixed $values, string $field): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new RuntimeException('career_1046_'.$field.'_invalid');
        }
        $normalized = [];
        foreach ($values as $value) {
            $slug = $this->requiredSlug($value);
            if (isset($normalized[$slug])) {
                throw new RuntimeException('career_1046_'.$field.'_duplicate');
            }
            $normalized[$slug] = true;
        }
        $slugs = array_keys($normalized);
        sort($slugs, SORT_STRING);

        return $slugs;
    }

    /** @param list<string> $left @param list<string> $right @return list<string> */
    private function union(array $left, array $right): array
    {
        $values = array_values(array_unique([...$left, ...$right]));
        sort($values, SORT_STRING);

        return $values;
    }

    /** @param list<string> $slugs @return list<string> */
    private function expectedLocaleRows(array $slugs): array
    {
        $rows = [];
        foreach ($slugs as $slug) {
            $rows[] = $slug.'|en';
            $rows[] = $slug.'|zh';
        }
        sort($rows, SORT_STRING);

        return $rows;
    }

    /** @param list<string> $values */
    private function assertFrozenSet(array $values, int $count, string $sha256, string $field): void
    {
        if (count($values) !== $count || ! hash_equals($sha256, CareerGenerationCanonicalJson::setSha256($values))) {
            throw new RuntimeException('career_1046_'.$field.'_authority_mismatch');
        }
    }

    /** @param list<string> $actual @param list<string> $expected */
    private function assertExactSet(array $actual, array $expected, string $field): void
    {
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException('career_1046_'.$field.'_set_mismatch');
        }
    }

    /** @param list<string> $actual @param list<string> $expected */
    private function assertExactAuthoritySet(array $actual, array $expected, string $field): void
    {
        $normalized = $this->exactSlugSet($actual, $field);
        $this->assertExactSet($normalized, $expected, $field);
    }

    /** @param list<string> $slugs */
    private function assertNoForbiddenSlugs(array $slugs, string $field): void
    {
        if (array_intersect(self::FORBIDDEN_SLUGS, $slugs) !== []) {
            throw new RuntimeException('career_1046_'.$field.'_contains_forbidden_slug');
        }
    }

    /** @return list<string> */
    private function ledgerSlugs(array $ledger): array
    {
        if (($ledger['ledger_kind'] ?? null) !== CareerFullReleaseLedgerService::LEDGER_KIND) {
            throw new RuntimeException('career_1046_ledger_kind_invalid');
        }
        $rows = data_get($ledger, 'public_resolution.rows');
        if (! is_array($rows) || $rows === []) {
            $rows = $ledger['members'] ?? null;
        }
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('career_1046_ledger_rows_invalid');
        }
        $slugs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('career_1046_ledger_row_invalid');
            }
            $slug = $this->requiredSlug($row['source_slug'] ?? $row['canonical_slug'] ?? $row['slug'] ?? null);
            if (isset($slugs[$slug])) {
                throw new RuntimeException('career_1046_ledger_duplicate_slug');
            }
            $slugs[$slug] = true;
        }
        $result = array_keys($slugs);
        sort($result, SORT_STRING);
        $this->assertNoForbiddenSlugs($result, 'ledger');

        return $result;
    }

    /** @return array{0:list<array<string,mixed>>,1:list<string>,2:list<string>} */
    private function projectionRows(array $projection): array
    {
        if (($projection['projection_kind'] ?? null) !== CareerRuntimePublishProjectionService::PROJECTION_KIND
            || ($projection['projection_version'] ?? null) !== CareerRuntimePublishProjectionService::PROJECTION_VERSION
            || ($projection['source_authority'] ?? null) !== 'CareerFullReleaseLedger') {
            throw new RuntimeException('career_1046_projection_identity_invalid');
        }
        $items = $projection['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new RuntimeException('career_1046_projection_items_invalid');
        }
        $rows = [];
        $slugs = [];
        $localeRows = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new RuntimeException('career_1046_projection_item_invalid');
            }
            $slug = $this->requiredSlug($item['slug'] ?? null);
            $locale = $this->requiredLocale($item['locale'] ?? null);
            $key = $slug.'|'.$locale;
            if (isset($localeRows[$key])) {
                throw new RuntimeException('career_1046_projection_duplicate_locale_row');
            }
            if (($item['public_resolution_type'] ?? null) !== CareerPublicResolutionTypeMatrix::PUBLIC_CANONICAL_JOB
                || ($item['runtime_publish_state'] ?? null) !== CareerRuntimePublishProjectionService::STATE_PUBLISHED
                || ($item['detail_route_enabled'] ?? null) !== true
                || ($item['dataset_visible'] ?? null) !== true) {
                throw new RuntimeException('career_1046_projection_row_not_published');
            }
            $item['sitemap_live'] = false;
            $item['llms_live'] = false;
            $item['llms_full_live'] = false;
            $rows[] = $item;
            $slugs[$slug] = true;
            $localeRows[$key] = true;
        }
        usort($rows, static fn (array $left, array $right): int => strcmp($left['slug'].'|'.$left['locale'], $right['slug'].'|'.$right['locale']));
        $slugList = array_keys($slugs);
        sort($slugList, SORT_STRING);
        $localeRowList = array_keys($localeRows);
        sort($localeRowList, SORT_STRING);
        $this->assertNoForbiddenSlugs($slugList, 'projection');

        return [$rows, $slugList, $localeRowList];
    }

    /** @param list<array<string,mixed>> $detailRows @return array{0:array{en:list<array<string,mixed>>,zh:list<array<string,mixed>>},1:list<string>} */
    private function detailRows(array $detailRows): array
    {
        $byLocale = ['en' => [], 'zh' => []];
        $keys = [];
        foreach ($detailRows as $row) {
            if (! is_array($row)) {
                throw new RuntimeException('career_1046_detail_row_invalid');
            }
            $slug = $this->requiredSlug($row['slug'] ?? null);
            $locale = $this->requiredLocale($row['locale'] ?? null);
            $payload = $row['payload'] ?? null;
            if (! is_array($payload) || $payload === []) {
                throw new RuntimeException('career_1046_detail_payload_invalid');
            }
            $key = $slug.'|'.$locale;
            if (isset($keys[$key])) {
                throw new RuntimeException('career_1046_detail_duplicate_locale_row');
            }
            $keys[$key] = true;
            $byLocale[$locale][] = ['slug' => $slug, 'locale' => $locale, 'payload' => $payload];
        }
        foreach ($byLocale as &$rows) {
            usort($rows, static fn (array $left, array $right): int => strcmp($left['slug'], $right['slug']));
        }
        unset($rows);
        $keyList = array_keys($keys);
        sort($keyList, SORT_STRING);

        return [$byLocale, $keyList];
    }

    /** @param list<array<string,mixed>> $items @return array<string,int> */
    private function projectionCounts(array $items): array
    {
        $counts = [
            'projection_rows' => count($items),
            'canonical_published' => count($items),
            'dataset_visible' => count($items),
            'search_visible' => count($items),
            'detail_route_enabled' => count($items),
            'sitemap_live' => 0,
            'llms_live' => 0,
            'llms_full_live' => 0,
            'blocked' => 0,
            'published_candidate' => 0,
            'published' => count($items),
            'quarantined' => 0,
        ];

        return $counts;
    }

    private function requiredSlug(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $value) !== 1) {
            throw new RuntimeException('career_1046_slug_invalid');
        }

        return $value;
    }

    private function requiredLocale(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, ['en', 'zh'], true)) {
            throw new RuntimeException('career_1046_locale_invalid');
        }

        return $value;
    }

    private function requiredGenerationId(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^career-1046-[0-9a-f]{32}$/', $value) !== 1) {
            throw new RuntimeException('career_1046_generation_id_invalid');
        }

        return $value;
    }

    private function removeTemporaryDirectory(string $path): void
    {
        if (! is_dir($path) || is_link($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path.DIRECTORY_SEPARATOR.$entry;
            if (is_file($child) && ! is_link($child)) {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
