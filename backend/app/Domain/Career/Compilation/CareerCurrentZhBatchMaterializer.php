<?php

declare(strict_types=1);

namespace App\Domain\Career\Compilation;

use App\Domain\Career\Display\CareerCurrentAuthorityManifestRefresher;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use JsonException;

final class CareerCurrentZhBatchMaterializer
{
    public const CONTRACT_VERSION = 'career.current.zh_batch_materialization.v1';

    public const EXPECTED_SOURCE_AGGREGATE_SHA256 = '58503fb4f5b565d50c0bcb6e57632c27282e6e58f625ec5a2f63357e08dda186';

    public function __construct(
        private readonly CareerCurrentAuthorityPackage $package,
        private readonly CareerCurrentAuthorityManifestRefresher $manifestRefresher,
        private readonly CareerCurrentZhBatchPreparer $preparer,
    ) {}

    /** @return array<string,mixed> */
    public function materialize(
        string $sourceRoot,
        string $planRoot,
        string $batchId,
        string $expectedBaseAssetsSha256,
        string $backendRoot,
        ?string $outputRoot,
        bool $write,
        string $environment,
    ): array {
        if (preg_match('/\Abatch-[0-9]{3}\z/', $batchId) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/', $expectedBaseAssetsSha256) !== 1) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_MATERIALIZE_INPUT_INVALID');
        }
        if ($write) {
            if (! in_array($environment, ['local', 'testing'], true) || ! $this->isLinkedGitWorktree($backendRoot)) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_MATERIALIZE_WRITE_NOT_ALLOWED');
            }
            if ($outputRoot !== null && trim($outputRoot) !== '') {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_MATERIALIZE_OUTPUT_FORBIDDEN');
            }
        } else {
            $outputRoot = $this->emptyOutputRoot((string) $outputRoot);
        }

        $planRoot = $this->planRoot($planRoot);
        $sourceBefore = $this->preparer->inspectSource($sourceRoot);
        $this->assertSource($sourceBefore);
        $plan = $this->loadPlan($planRoot, $sourceBefore);
        $batch = $this->selectBatch($plan, $batchId);

        $assetsPath = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/assets.jsonl';
        $manifestPath = rtrim($backendRoot, '/').'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH.'/manifest.json';
        if (! is_file($assetsPath) || ! is_file($manifestPath) || is_link($assetsPath) || is_link($manifestPath)) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_CURRENT_PACKAGE_INVALID');
        }
        $baseAssetsSha256 = hash_file('sha256', $assetsPath);
        if (! is_string($baseAssetsSha256) || ! hash_equals($expectedBaseAssetsSha256, $baseAssetsSha256)) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_BASE_ASSETS_HASH_MISMATCH');
        }

        $baseline = $this->package->load($backendRoot);
        $progress = $this->assertSequence($baseline['rows'], $plan, (int) $batch['ordinal']);
        $targetSlugs = $batch['target_slugs'];
        $targetLookup = array_fill_keys($targetSlugs, true);
        $candidateRows = [];
        $targetProjectionHashes = [];
        $enBefore = [];
        $alreadyCurrent = [];
        foreach ($targetSlugs as $slug) {
            if ($slug === 'software-developers' || ! isset($baseline['rows'][$slug])) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_TARGET_SET_INVALID');
            }
            $before = $baseline['rows'][$slug];
            $candidate = $this->preparer->candidateRowForSource($sourceRoot, $slug, $before);
            $candidateHash = $this->projectionHash($candidate, 'zh-CN');
            if (! hash_equals((string) ($plan['manifest']['per_slug'][$slug]['zh_projection_sha256'] ?? ''), $candidateHash)) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_PLAN_PROJECTION_HASH_MISMATCH');
            }
            $beforeEn = $this->projectionHash($before, 'en');
            $afterEn = $this->projectionHash($candidate, 'en');
            if (! hash_equals($beforeEn, $afterEn)) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_EN_PROJECTION_CHANGED');
            }
            if (hash_equals($this->projectionHash($before, 'zh-CN'), $candidateHash)) {
                $alreadyCurrent[] = $slug;
            }
            $candidateRows[$slug] = $candidate;
            $targetProjectionHashes[$slug] = $candidateHash;
            $enBefore[$slug] = $beforeEn;
        }

        $assetsBytes = $this->replaceTargetRows($assetsPath, $candidateRows, $targetLookup);
        $candidateBackendRoot = $this->temporaryBackendRoot();
        try {
            $candidatePackageRoot = $candidateBackendRoot.'/'.CareerCurrentAuthorityPackage::RELATIVE_PATH;
            $this->makeDirectory($candidatePackageRoot);
            $this->writeFile($candidatePackageRoot.'/assets.jsonl', $assetsBytes);
            $manifestBytes = file_get_contents($manifestPath);
            if (! is_string($manifestBytes)) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_CURRENT_PACKAGE_INVALID');
            }
            $this->writeFile($candidatePackageRoot.'/manifest.json', $manifestBytes);
            $this->manifestRefresher->write($candidateBackendRoot);
            $candidate = $this->package->load($candidateBackendRoot);
            $candidateManifestBytes = file_get_contents($candidatePackageRoot.'/manifest.json');
            if (! is_string($candidateManifestBytes)) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_CANDIDATE_PACKAGE_INVALID');
            }

            $diff = $this->exactDiff($baseline['rows'], $candidate['rows'], $targetSlugs);
            if ($diff['changed_en_locale_pages'] !== 0
                || $diff['non_target_row_changes'] !== 0
                || $diff['changed_zh_locale_pages'] !== count($targetSlugs) - count($alreadyCurrent)) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_PACKAGE_DIFF_MISMATCH');
            }

            $report = [
                'status' => 'PASS_CURRENT_ZH_BATCH_MATERIALIZE',
                'contract_version' => self::CONTRACT_VERSION,
                'batch_id' => $batchId,
                'batch_ordinal' => $batch['ordinal'],
                'base_assets_sha256' => $baseAssetsSha256,
                'base_manifest_sha256' => hash_file('sha256', $manifestPath),
                'candidate_assets_sha256' => $candidate['summary']['assets_sha256'],
                'candidate_manifest_sha256' => $candidate['summary']['manifest_sha256'],
                'source_aggregate_sha256' => $sourceBefore['aggregate_sha256'],
                'target_count' => count($targetSlugs),
                'target_slugs' => $targetSlugs,
                'control_slugs' => $batch['control_slugs'],
                'already_current_slugs' => $alreadyCurrent,
                'completed_batch_count_before' => $progress['completed_batch_count'],
                'package' => [
                    'career_count' => $candidate['summary']['career_count'],
                    'locale_page_count' => $candidate['summary']['locale_page_count'],
                    'components_per_page' => $candidate['summary']['components_per_page'],
                ],
                'database_writes' => 0,
                'cache_writes' => 0,
                'cms_writes' => 0,
                'pointer_writes' => 0,
                'sitemap_writes' => 0,
                'discoverability_writes' => 0,
                'llms_writes' => 0,
                'search_submissions' => 0,
            ];

            if ($write) {
                $this->replaceAtomically($assetsPath, $assetsBytes);
                $manifestResult = $this->manifestRefresher->write($backendRoot);
                $written = $this->package->load($backendRoot);
                if (! hash_equals($candidate['summary']['assets_sha256'], $written['summary']['assets_sha256'])
                    || ! hash_equals($candidate['summary']['manifest_sha256'], $written['summary']['manifest_sha256'])) {
                    throw new CareerTenBlockCompileFailure('CURRENT_ZH_WRITTEN_PACKAGE_MISMATCH');
                }
                $report['write'] = [
                    'assets_replaced_atomically' => true,
                    'manifest_status' => $manifestResult['status'],
                    'current_package_files_changed' => 2,
                ];
            } else {
                $this->writeDryRun(
                    $outputRoot,
                    $batch,
                    $diff,
                    $targetProjectionHashes,
                    $sourceBefore,
                    $report,
                    $assetsBytes,
                    $candidateManifestBytes,
                );
                $report['current_package_files_changed'] = 0;
            }
        } finally {
            $this->removeTree($candidateBackendRoot);
        }

        $sourceAfter = $this->preparer->inspectSource($sourceRoot);
        if ($sourceBefore !== $sourceAfter) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_SOURCE_BYTES_CHANGED');
        }
        if (! $write && (! hash_equals($baseAssetsSha256, (string) hash_file('sha256', $assetsPath)))) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_CURRENT_PACKAGE_CHANGED');
        }

        return ['report' => $report, 'diff' => $diff, 'target_projection_hashes' => $targetProjectionHashes];
    }

    /** @param array<string,mixed> $source */
    private function assertSource(array $source): void
    {
        if (($source['career_count'] ?? null) !== CareerCurrentZhBatchPreparer::EXPECTED_CAREERS
            || ($source['file_count'] ?? null) !== CareerCurrentZhBatchPreparer::EXPECTED_FILES
            || ! hash_equals(self::EXPECTED_SOURCE_AGGREGATE_SHA256, (string) ($source['aggregate_sha256'] ?? ''))) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_SOURCE_HASH_MISMATCH');
        }
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function loadPlan(string $planRoot, array $source): array
    {
        $manifest = $this->readJson($planRoot.'/manifest.json');
        $batches = $this->readJson($planRoot.'/batches.json');
        $sourceLock = $this->readJson($planRoot.'/source-lock.json');
        $sourceSlugs = $this->readJson($planRoot.'/source-slugs.json');
        if (($manifest['contract_version'] ?? null) !== CareerCurrentZhBatchPreparer::CONTRACT_VERSION
            || ($manifest['batch_count'] ?? null) !== 21
            || ! hash_equals(self::EXPECTED_SOURCE_AGGREGATE_SHA256, (string) ($manifest['source_aggregate_sha256'] ?? ''))
            || ($sourceLock['contract_version'] ?? null) !== 'career.current.zh_source_lock.v1'
            || ($sourceLock['files'] ?? null) !== $source['files']
            || $sourceSlugs !== $source['slugs']
            || ! is_array($batches) || count($batches) !== 21) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_PLAN_INVALID');
        }
        foreach ($batches as $index => $batch) {
            $batchId = sprintf('batch-%03d', $index + 1);
            if (! is_array($batch) || ($batch['batch_id'] ?? null) !== $batchId
                || ($batch['ordinal'] ?? null) !== $index + 1
                || $this->readJson($planRoot.'/batches/'.$batchId.'.json') !== $batch) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_PLAN_INVALID');
            }
        }

        return ['manifest' => $manifest, 'batches' => $batches];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    private function selectBatch(array $plan, string $batchId): array
    {
        foreach ($plan['batches'] as $batch) {
            if (($batch['batch_id'] ?? null) === $batchId) {
                return $batch;
            }
        }
        throw new CareerTenBlockCompileFailure('CURRENT_ZH_BATCH_UNKNOWN');
    }

    /**
     * @param  array<string,array<string,mixed>>  $rows
     * @param  array<string,mixed>  $plan
     * @return array{completed_batch_count:int}
     */
    private function assertSequence(array $rows, array $plan, int $requestedOrdinal): array
    {
        $completed = 0;
        foreach ($plan['batches'] as $batch) {
            $current = 0;
            foreach ($batch['target_slugs'] as $slug) {
                $expected = (string) ($plan['manifest']['per_slug'][$slug]['zh_projection_sha256'] ?? '');
                if (isset($rows[$slug]) && hash_equals($expected, $this->projectionHash($rows[$slug], 'zh-CN'))) {
                    $current++;
                }
            }
            if ($current === count($batch['target_slugs'])) {
                $completed++;

                continue;
            }
            if ($current !== 0) {
                if ((int) $batch['ordinal'] !== $requestedOrdinal) {
                    throw new CareerTenBlockCompileFailure('CURRENT_ZH_BATCH_SEQUENCE_INVALID');
                }
                break;
            }
            break;
        }
        if ($requestedOrdinal <= $completed) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_BATCH_ALREADY_MATERIALIZED');
        }
        if ($requestedOrdinal !== $completed + 1) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_BATCH_SEQUENCE_INVALID');
        }
        foreach (array_slice($plan['batches'], $requestedOrdinal) as $future) {
            foreach ($future['target_slugs'] as $slug) {
                $expected = (string) ($plan['manifest']['per_slug'][$slug]['zh_projection_sha256'] ?? '');
                if (isset($rows[$slug]) && hash_equals($expected, $this->projectionHash($rows[$slug], 'zh-CN'))) {
                    throw new CareerTenBlockCompileFailure('CURRENT_ZH_BATCH_SEQUENCE_INVALID');
                }
            }
        }

        return ['completed_batch_count' => $completed];
    }

    /**
     * @param  array<string,array<string,mixed>>  $candidateRows
     * @param  array<string,bool>  $targetLookup
     */
    private function replaceTargetRows(string $assetsPath, array $candidateRows, array $targetLookup): string
    {
        $lines = file($assetsPath, FILE_IGNORE_NEW_LINES);
        if (! is_array($lines)) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_CURRENT_PACKAGE_INVALID');
        }
        $seen = [];
        foreach ($lines as $index => $line) {
            try {
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_CURRENT_PACKAGE_INVALID');
            }
            $slug = is_array($row) ? ($row['canonical_slug'] ?? null) : null;
            if (is_string($slug) && isset($targetLookup[$slug])) {
                $lines[$index] = CareerCurrentAuthorityPackage::encodeCanonical($candidateRows[$slug]);
                $seen[$slug] = true;
            }
        }
        if (array_keys($seen) !== array_keys($targetLookup)) {
            $actual = array_keys($seen);
            $expected = array_keys($targetLookup);
            sort($actual, SORT_STRING);
            sort($expected, SORT_STRING);
            if ($actual !== $expected) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_TARGET_SET_INVALID');
            }
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  array<string,array<string,mixed>>  $before
     * @param  array<string,array<string,mixed>>  $after
     * @param  list<string>  $targets
     * @return array<string,mixed>
     */
    private function exactDiff(array $before, array $after, array $targets): array
    {
        $targetLookup = array_fill_keys($targets, true);
        $changedRows = [];
        $changedZh = [];
        $changedEn = [];
        $nonTarget = [];
        foreach ($before as $slug => $row) {
            if (! hash_equals(CareerCurrentAuthorityPackage::hashValue($row), CareerCurrentAuthorityPackage::hashValue($after[$slug]))) {
                $changedRows[] = $slug;
                if (! isset($targetLookup[$slug])) {
                    $nonTarget[] = $slug;
                }
            }
            if (! hash_equals($this->projectionHash($row, 'zh-CN'), $this->projectionHash($after[$slug], 'zh-CN'))) {
                $changedZh[] = $slug;
            }
            if (! hash_equals($this->projectionHash($row, 'en'), $this->projectionHash($after[$slug], 'en'))) {
                $changedEn[] = $slug;
            }
        }

        return [
            'changed_row_slugs' => $changedRows,
            'changed_target_slugs' => array_values(array_intersect($targets, $changedRows)),
            'changed_zh_slugs' => $changedZh,
            'changed_zh_locale_pages' => count($changedZh),
            'changed_en_slugs' => $changedEn,
            'changed_en_locale_pages' => count($changedEn),
            'non_target_row_changes' => count($nonTarget),
            'non_target_changed_slugs' => $nonTarget,
            'source_asset_changes' => 0,
            'database_writes' => 0,
            'cache_writes' => 0,
            'cms_writes' => 0,
            'pointer_writes' => 0,
            'sitemap_writes' => 0,
            'discoverability_writes' => 0,
            'llms_writes' => 0,
            'search_submissions' => 0,
            'software_developers_included' => in_array('software-developers', $changedRows, true),
        ];
    }

    /** @param array<string,mixed> $row */
    private function projectionHash(array $row, string $locale): string
    {
        return CareerCurrentAuthorityPackage::hashValue($this->package->publicProjection($row, $locale));
    }

    /** @param array<string,mixed> $batch @param array<string,mixed> $diff @param array<string,string> $hashes @param array<string,mixed> $source @param array<string,mixed> $report */
    private function writeDryRun(string $outputRoot, array $batch, array $diff, array $hashes, array $source, array $report, string $assetsBytes, string $manifestBytes): void
    {
        $candidateRoot = $outputRoot.'/candidate';
        $this->makeDirectory($candidateRoot);
        $this->writeJson($outputRoot.'/batch-manifest.json', $batch);
        $this->writeJson($outputRoot.'/package-diff.json', $diff);
        $this->writeJson($outputRoot.'/target-projection-hashes.json', $hashes);
        $this->writeJson($outputRoot.'/source-lock.json', [
            'contract_version' => 'career.current.zh_source_lock.v1',
            'aggregate_sha256' => $source['aggregate_sha256'],
            'files' => $source['files'],
        ]);
        $this->writeJson($outputRoot.'/acceptance-report.json', $report);
        $this->writeFile($candidateRoot.'/assets.jsonl', $assetsBytes);
        $this->writeFile($candidateRoot.'/manifest.json', $manifestBytes);
    }

    private function planRoot(string $root): string
    {
        $resolved = is_link($root) ? false : realpath($root);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_PLAN_INVALID');
        }

        return $resolved;
    }

    private function emptyOutputRoot(string $root): string
    {
        $resolved = is_link($root) ? false : realpath($root);
        $temp = realpath(sys_get_temp_dir());
        $sharedTemp = realpath('/tmp');
        if ($resolved === false || ! is_dir($resolved) || (scandir($resolved) ?: []) !== ['.', '..']
            || ($temp === false || (! str_starts_with($resolved.'/', rtrim($temp, '/').'/')
                && ($sharedTemp === false || ! str_starts_with($resolved.'/', rtrim($sharedTemp, '/').'/'))))) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_OUTPUT_ROOT_FORBIDDEN');
        }

        return $resolved;
    }

    private function isLinkedGitWorktree(string $backendRoot): bool
    {
        $repositoryRoot = realpath(rtrim($backendRoot, '/').'/..');
        if ($repositoryRoot === false) {
            return false;
        }
        $gitEntry = $repositoryRoot.'/.git';

        return is_file($gitEntry) && ! is_link($gitEntry)
            && str_starts_with(trim((string) file_get_contents($gitEntry)), 'gitdir: ');
    }

    /** @return array<string,mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path) || is_link($path)) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_PLAN_INVALID');
        }
        try {
            $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_PLAN_INVALID');
        }
        if (! is_array($value)) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_PLAN_INVALID');
        }

        return $value;
    }

    private function temporaryBackendRoot(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'career-current-zh-');
        if (! is_string($path) || ! unlink($path) || ! mkdir($path, 0700)) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_TEMP_WRITE_FAILED');
        }

        return $path;
    }

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true)) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_OUTPUT_WRITE_FAILED');
        }
    }

    private function writeJson(string $path, mixed $value): void
    {
        $this->writeFile($path, CareerCurrentAuthorityPackage::encodePrettyCanonical($value));
    }

    private function writeFile(string $path, string $bytes): void
    {
        if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_OUTPUT_WRITE_FAILED');
        }
    }

    private function replaceAtomically(string $path, string $bytes): void
    {
        $temporary = tempnam(dirname($path), '.career-current-zh-');
        if (! is_string($temporary)) {
            throw new CareerTenBlockCompileFailure('CURRENT_ZH_CURRENT_WRITE_FAILED');
        }
        try {
            $mode = fileperms($path);
            if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)
                || ($mode !== false && ! chmod($temporary, $mode & 0777))
                || ! rename($temporary, $path)) {
                throw new CareerTenBlockCompileFailure('CURRENT_ZH_CURRENT_WRITE_FAILED');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function removeTree(string $root): void
    {
        if (! is_dir($root) || is_link($root) || ! str_starts_with(realpath($root) ?: '', realpath(sys_get_temp_dir()) ?: '/no-temp')) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($root);
    }
}
