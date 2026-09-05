<?php

declare(strict_types=1);

namespace App\Services\BigFive\AuthorityV3\Release;

use App\Models\PersonalityPublicContentAsset;
use App\Services\SEO\BigFiveCanonicalRouteCatalog;
use App\Support\SchemaBaseline;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class BigFiveEn52ProductionEvidence
{
    public const SCHEMA_VERSION = 'big-five-en52-production-evidence.v1';

    public const BACKUP_SCHEMA_VERSION = 'big-five-en52-production-backup.v1';

    public const BACKUP_ARTIFACT_SCHEMA_VERSION = 'big-five-en52-production-backup-artifact.v1';

    public const BACKUP_CONFIRMATION = 'CREATE_BIG_FIVE_EN52_PRODUCTION_BACKUP';

    /** @var list<string> */
    private const SEARCH_TABLES = [
        'seo_domestic_submission_logs',
        'seo_indexnow_submissions',
        'seo_issue_queue',
        'seo_search_channel_queue_batches',
        'seo_search_channel_queue_items',
        'seo_search_channel_queue_events',
    ];

    /** @var list<string> */
    private const NON_PERSONALITY_TABLES = [
        'articles', 'topic_profiles', 'landing_surfaces', 'content_pages',
        'career_guides', 'career_guide_revisions', 'career_guide_seo_meta',
        'career_jobs', 'career_job_revisions', 'career_job_seo_meta', 'career_job_sections',
        'career_job_ai_impact_assets', 'career_job_display_assets',
        'career_job_page_assembly_assets', 'career_job_salary_assets',
        'media_assets', 'media_variants',
    ];

    /** @return array<string,mixed> */
    public function inspect(string $packagePath): array
    {
        $before = $this->databaseFingerprint();
        $snapshot = $this->snapshot($packagePath, false);
        $after = $this->databaseFingerprint();
        if (! hash_equals($before, $after)) {
            throw new RuntimeException('Read-only EN52 production evidence inspection changed the database.');
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ok' => true,
            'status' => 'PASS_BIG_FIVE_EN52_PRODUCTION_EVIDENCE',
            'mode' => 'read_only',
            'release_id' => BigFiveEn52PackageCompiler::RELEASE_ID,
            'release_package_sha256' => BigFiveEn52Publisher::PACKAGE_FILE_SHA256,
            'source_content_sha256' => BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256,
            'cohort_snapshot_sha256' => BigFiveEn52PackageCompiler::COHORT_SNAPSHOT_SHA256,
            'zh_canonical_count' => $snapshot['zh_canonical_count'],
            'en_canonical_count' => $snapshot['en_canonical_count'],
            'legacy_alias_count' => $snapshot['legacy_alias_count'],
            'source_hash_match_count' => $snapshot['source_hash_match_count'],
            'source_hash_mismatch_count' => BigFiveEn52PackageCompiler::ASSET_COUNT - $snapshot['source_hash_match_count'],
            'backup_tables' => $snapshot['table_evidence'],
            'baseline_fingerprints' => $snapshot['baseline_fingerprints'],
            'database_snapshot_before_sha256' => $before,
            'database_snapshot_after_sha256' => $after,
            'database_snapshot_unchanged' => true,
            'writes_committed' => false,
            'errors' => [],
        ];
    }

    /**
     * @param  array{sha:string,name:string}|null  $testingReleaseIdentity
     * @return array<string,mixed>
     */
    public function createBackup(
        string $packagePath,
        string $outputDirectory,
        int $operatorAdminUserId,
        string $approvedSha,
        string $releaseName,
        ?array $testingReleaseIdentity = null,
    ): array {
        if ($operatorAdminUserId !== BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID) {
            throw new RuntimeException('EN52 production backup is locked to operator admin_user:1.');
        }
        $identity = $this->assertReleaseIdentity($approvedSha, $releaseName, $testingReleaseIdentity);
        $snapshot = $this->snapshot($packagePath, false);
        if ($snapshot['legacy_alias_count'] !== 0) {
            throw new RuntimeException('EN52 production backup requires all twenty legacy alias rows to be absent.');
        }

        $resolvedDirectory = str_starts_with($outputDirectory, DIRECTORY_SEPARATOR)
            ? $outputDirectory
            : base_path($outputDirectory);
        if (! File::isDirectory($resolvedDirectory) || ! is_writable($resolvedDirectory)) {
            throw new RuntimeException('EN52 production backup output directory must already exist and be writable.');
        }
        $createdAt = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');
        $stamp = CarbonImmutable::parse($createdAt)->format('Ymd\THis\Z');
        $prefix = 'big-five-en52-production-backup-'.$stamp.'-'.substr($identity['sha'], 0, 10);
        $artifactFile = $prefix.'.json';
        $manifestFile = $prefix.'.manifest.json';
        $artifactPath = $resolvedDirectory.DIRECTORY_SEPARATOR.$artifactFile;
        $manifestPath = $resolvedDirectory.DIRECTORY_SEPARATOR.$manifestFile;

        $artifact = [
            'schema_version' => self::BACKUP_ARTIFACT_SCHEMA_VERSION,
            'created_at' => $createdAt,
            'operator_admin_user_id' => $operatorAdminUserId,
            'approved_sha' => $identity['sha'],
            'release_name' => $identity['name'],
            'release_id' => BigFiveEn52PackageCompiler::RELEASE_ID,
            'release_package_sha256' => BigFiveEn52Publisher::PACKAGE_FILE_SHA256,
            'source_content_sha256' => BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256,
            'cohort_snapshot_sha256' => BigFiveEn52PackageCompiler::COHORT_SNAPSHOT_SHA256,
            'tables' => $snapshot['rows'],
        ];
        $artifactPayload = $this->stableJson($artifact)."\n";
        $artifactSha = hash('sha256', $artifactPayload);
        $manifest = [
            'schema_version' => self::BACKUP_SCHEMA_VERSION,
            'created_at' => $createdAt,
            'operator_admin_user_id' => $operatorAdminUserId,
            'approved_sha' => $identity['sha'],
            'release_name' => $identity['name'],
            'release_id' => BigFiveEn52PackageCompiler::RELEASE_ID,
            'release_package_sha256' => BigFiveEn52Publisher::PACKAGE_FILE_SHA256,
            'source_content_sha256' => BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256,
            'cohort_snapshot_sha256' => BigFiveEn52PackageCompiler::COHORT_SNAPSHOT_SHA256,
            'artifact_file' => $artifactFile,
            'backup_artifact_sha256' => $artifactSha,
            'tables' => $snapshot['table_evidence'],
            'baseline_fingerprints' => $snapshot['baseline_fingerprints'],
            'source_hash_match_count' => $snapshot['source_hash_match_count'],
        ];
        $manifestPayload = $this->stableJson($manifest)."\n";

        $created = [];
        try {
            $this->writeAtomicExclusive($artifactPath, $artifactPayload);
            $created[] = $artifactPath;
            $this->writeAtomicExclusive($manifestPath, $manifestPayload);
            $created[] = $manifestPath;
            $manifestSha = hash('sha256', (string) File::get($manifestPath));
            $this->assertBackupManifest(
                $packagePath,
                $manifestPath,
                $manifestSha,
                $operatorAdminUserId,
                $identity['sha'],
                $identity['name'],
                false,
                $testingReleaseIdentity,
            );
        } catch (Throwable $throwable) {
            File::delete($created);

            throw $throwable;
        }

        return [
            'schema_version' => self::BACKUP_SCHEMA_VERSION,
            'ok' => true,
            'status' => 'PASS_BIG_FIVE_EN52_PRODUCTION_BACKUP_CREATED',
            'mode' => 'backup_create',
            'artifact_file' => $artifactFile,
            'manifest_file' => $manifestFile,
            'backup_artifact_sha256' => $artifactSha,
            'backup_manifest_sha256' => hash('sha256', $manifestPayload),
            'tables' => $snapshot['table_evidence'],
            'baseline_fingerprints' => $snapshot['baseline_fingerprints'],
            'source_hash_match_count' => $snapshot['source_hash_match_count'],
            'writes_committed' => false,
            'backup_files_created' => true,
            'errors' => [],
        ];
    }

    /**
     * @param  array{sha:string,name:string}|null  $testingReleaseIdentity
     * @return array<string,mixed>
     */
    public function assertBackupManifest(
        string $packagePath,
        string $manifestPath,
        string $manifestSha256,
        int $operatorAdminUserId,
        string $approvedSha,
        string $releaseName,
        bool $lockForUpdate = false,
        ?array $testingReleaseIdentity = null,
    ): array {
        $identity = $this->assertReleaseIdentity($approvedSha, $releaseName, $testingReleaseIdentity);
        if ($operatorAdminUserId !== BigFiveEn52Publisher::OPERATOR_ADMIN_USER_ID
            || preg_match('/^[a-f0-9]{64}$/', $manifestSha256) !== 1) {
            throw new RuntimeException('EN52 backup manifest operator or SHA-256 is invalid.');
        }
        $resolved = str_starts_with($manifestPath, DIRECTORY_SEPARATOR) ? $manifestPath : base_path($manifestPath);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('EN52 backup manifest file does not exist.');
        }
        $raw = (string) File::get($resolved);
        if (! hash_equals($manifestSha256, hash('sha256', $raw))) {
            throw new RuntimeException('EN52 backup manifest SHA-256 does not match the supplied file.');
        }
        $manifest = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest)
            || ($manifest['schema_version'] ?? null) !== self::BACKUP_SCHEMA_VERSION
            || (int) ($manifest['operator_admin_user_id'] ?? 0) !== $operatorAdminUserId
            || ($manifest['approved_sha'] ?? null) !== $identity['sha']
            || ($manifest['release_name'] ?? null) !== $identity['name']
            || ($manifest['release_id'] ?? null) !== BigFiveEn52PackageCompiler::RELEASE_ID
            || ($manifest['release_package_sha256'] ?? null) !== BigFiveEn52Publisher::PACKAGE_FILE_SHA256
            || ($manifest['source_content_sha256'] ?? null) !== BigFiveEn52PackageCompiler::SOURCE_CONTENT_SHA256
            || ($manifest['cohort_snapshot_sha256'] ?? null) !== BigFiveEn52PackageCompiler::COHORT_SNAPSHOT_SHA256) {
            throw new RuntimeException('EN52 backup manifest identity or locked package boundary is invalid.');
        }
        $createdAt = CarbonImmutable::parse((string) ($manifest['created_at'] ?? ''));
        if ($createdAt->utc()->format('Y-m-d\TH:i:s\Z') !== (string) $manifest['created_at']) {
            throw new RuntimeException('EN52 backup manifest created_at must be a normalized UTC timestamp.');
        }
        $artifactFile = (string) ($manifest['artifact_file'] ?? '');
        if (preg_match('/^[A-Za-z0-9._-]{1,180}\.json$/', $artifactFile) !== 1) {
            throw new RuntimeException('EN52 backup artifact filename is invalid.');
        }
        $artifactPath = dirname($resolved).DIRECTORY_SEPARATOR.$artifactFile;
        if (! File::isFile($artifactPath)
            || preg_match('/^[a-f0-9]{64}$/', (string) ($manifest['backup_artifact_sha256'] ?? '')) !== 1
            || ! hash_equals((string) $manifest['backup_artifact_sha256'], hash_file('sha256', $artifactPath))) {
            throw new RuntimeException('EN52 backup artifact is missing or its SHA-256 does not match.');
        }

        $snapshot = $this->snapshot($packagePath, $lockForUpdate);
        if ($snapshot['legacy_alias_count'] !== 0
            || ! hash_equals(
                $this->fingerprint($snapshot['table_evidence']),
                $this->fingerprint($manifest['tables'] ?? null),
            )
            || ! hash_equals(
                $this->fingerprint($snapshot['baseline_fingerprints']),
                $this->fingerprint($manifest['baseline_fingerprints'] ?? null),
            )
            || (int) ($manifest['source_hash_match_count'] ?? -1) !== $snapshot['source_hash_match_count']) {
            throw new RuntimeException('EN52 backup manifest no longer matches the locked live cohort.');
        }

        return $manifest;
    }

    /** @param array{sha:string,name:string}|null $testingReleaseIdentity @return array{sha:string,name:string} */
    public function assertReleaseIdentity(
        string $approvedSha,
        string $releaseName,
        ?array $testingReleaseIdentity = null,
    ): array {
        $approvedSha = strtolower(trim($approvedSha));
        $releaseName = trim($releaseName);
        if (preg_match('/^[a-f0-9]{40}$/', $approvedSha) !== 1
            || preg_match('/^[A-Za-z0-9._-]{1,128}$/', $releaseName) !== 1) {
            throw new RuntimeException('EN52 approved SHA or release name is invalid.');
        }
        if ($testingReleaseIdentity !== null) {
            if (! app()->environment('testing')) {
                throw new RuntimeException('EN52 release identity override is prohibited outside tests.');
            }
            $actualSha = strtolower(trim((string) ($testingReleaseIdentity['sha'] ?? '')));
            $actualName = trim((string) ($testingReleaseIdentity['name'] ?? ''));
        } else {
            $root = dirname(base_path());
            $revisionPath = $root.DIRECTORY_SEPARATOR.'REVISION';
            if (! app()->environment('production') || ! File::isFile($revisionPath)) {
                throw new RuntimeException('EN52 deployed release identity is unavailable.');
            }
            $actualSha = strtolower(trim((string) File::get($revisionPath)));
            $actualName = basename($root);
        }
        if (! hash_equals($approvedSha, $actualSha) || ! hash_equals($releaseName, $actualName)) {
            throw new RuntimeException('EN52 deployed release identity does not match the exact authorization.');
        }

        return ['sha' => $approvedSha, 'name' => $releaseName];
    }

    /** @return array<string,mixed> */
    private function snapshot(string $packagePath, bool $lockForUpdate): array
    {
        $package = $this->readPackage($packagePath);
        $enRows = $this->canonicalRows('en', $lockForUpdate);
        $zhRows = $this->canonicalRows('zh-CN', $lockForUpdate);
        $enIds = array_map(static fn (array $row): int => (int) $row['id'], $enRows);
        $assetRows = $this->rows('personality_public_content_assets', 'id', $enIds, $lockForUpdate);
        $revisionRows = $this->rows('personality_public_content_asset_revisions', 'asset_id', $enIds, $lockForUpdate);
        $reviewRows = $this->rows('personality_public_content_asset_revision_reviews', 'asset_id', $enIds, $lockForUpdate);
        $rows = [
            'personality_public_content_assets' => $assetRows,
            'personality_public_content_asset_revisions' => $revisionRows,
            'personality_public_content_asset_revision_reviews' => $reviewRows,
        ];
        $tableEvidence = [];
        foreach ($rows as $table => $tableRows) {
            $tableEvidence[$table] = [
                'row_count' => count($tableRows),
                'checksum_sha256' => $this->fingerprint($tableRows),
            ];
        }

        $expectedHashes = [];
        foreach ($package['assets'] as $entry) {
            $asset = $entry['asset'] ?? null;
            if (! is_array($asset)) {
                throw new RuntimeException('EN52 package contains an invalid asset entry.');
            }
            $expectedHashes[(string) $asset['entity_type'].':'.(string) $asset['entity_key']] =
                (string) ($entry['runtime_projection_sha256'] ?? '');
        }
        $matches = 0;
        foreach ($assetRows as $row) {
            $identity = (string) $row['entity_type'].':'.(string) $row['entity_key'];
            $expected = $expectedHashes[$identity] ?? '';
            if (preg_match('/^[a-f0-9]{64}$/', $expected) !== 1) {
                throw new RuntimeException('EN52 package source hash inventory is incomplete.');
            }
            if (hash_equals($expected, (string) ($row['source_hash'] ?? ''))) {
                $matches++;
            }
        }

        return [
            'rows' => $rows,
            'table_evidence' => $tableEvidence,
            'en_canonical_count' => count($enRows),
            'zh_canonical_count' => count($zhRows),
            'legacy_alias_count' => $this->aliasCount(),
            'source_hash_match_count' => $matches,
            'baseline_fingerprints' => [
                'zh_fingerprint' => $this->fingerprint($zhRows),
                'non_target_fingerprint' => $this->nonTargetFingerprint(),
                'search_fingerprint' => $this->searchFingerprint(),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function readPackage(string $path): array
    {
        $resolved = str_starts_with($path, DIRECTORY_SEPARATOR) ? $path : base_path($path);
        if (! File::isFile($resolved)) {
            throw new RuntimeException('EN52 release package does not exist.');
        }
        $raw = (string) File::get($resolved);
        if (! hash_equals(BigFiveEn52Publisher::PACKAGE_FILE_SHA256, hash('sha256', $raw))) {
            throw new RuntimeException('EN52 release package SHA-256 is not locked.');
        }
        $package = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($package)
            || ($package['release_id'] ?? null) !== BigFiveEn52PackageCompiler::RELEASE_ID
            || (int) ($package['asset_count'] ?? 0) !== BigFiveEn52PackageCompiler::ASSET_COUNT
            || ! is_array($package['assets'] ?? null)
            || count($package['assets']) !== BigFiveEn52PackageCompiler::ASSET_COUNT) {
            throw new RuntimeException('EN52 release package identity or asset count is invalid.');
        }

        return $package;
    }

    /** @return list<array<string,mixed>> */
    private function canonicalRows(string $locale, bool $lockForUpdate): array
    {
        $query = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('locale', $locale)
            ->orderBy('entity_type')
            ->orderBy('entity_key');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $rows = $query->get();
        $expected = collect(BigFiveCanonicalRouteCatalog::canonicalEntries($locale))
            ->keyBy(static fn (array $entry): string => $entry['entity_type'].':'.$entry['entity_key']);
        $canonical = [];
        foreach ($rows as $row) {
            $identity = (string) $row->entity_type.':'.(string) $row->entity_key;
            $entry = $expected->get($identity);
            if (is_array($entry) && (string) data_get($row->canonical_json, 'path', '') === (string) $entry['path']) {
                if (isset($canonical[$identity])) {
                    throw new RuntimeException('EN52 canonical authority contains a duplicate identity.');
                }
                $canonical[$identity] = $row->getAttributes();
            }
        }
        ksort($canonical);
        if (count($canonical) !== BigFiveEn52PackageCompiler::ASSET_COUNT
            || array_keys($canonical) !== $expected->keys()->sort()->values()->all()) {
            throw new RuntimeException($locale.' Big Five canonical authority must contain exactly 52 locked rows.');
        }

        return array_values($canonical);
    }

    private function aliasCount(): int
    {
        return PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('org_id', 0)
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->whereIn('locale', ['en', 'zh-CN'])
            ->whereIn('entity_key', array_keys(BigFiveCanonicalRouteCatalog::EN_REDIRECT_ONLY_ALIAS_TARGETS))
            ->count();
    }

    /** @param list<int> $ids @return list<array<string,mixed>> */
    private function rows(string $table, string $column, array $ids, bool $lockForUpdate): array
    {
        if (! SchemaBaseline::tableExists($table)) {
            throw new RuntimeException('Required EN52 evidence table is missing: '.$table.'.');
        }
        if ($ids === []) {
            return [];
        }
        $query = DB::table($table)->whereIn($column, $ids)->orderBy('id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    private function nonTargetFingerprint(): string
    {
        $assets = DB::table('personality_public_content_assets')->where(function ($query): void {
            $query->where('framework', '!=', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
                ->orWhere('locale', '!=', 'en');
        })->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $revisions = DB::table('personality_public_content_asset_revisions')->where(function ($query): void {
            $query->whereNull('authority_package_sha256')
                ->orWhere('authority_package_sha256', '!=', BigFiveEn52Publisher::PACKAGE_FILE_SHA256);
        })->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all();
        $authority = [];
        foreach (self::NON_PERSONALITY_TABLES as $table) {
            $authority[$table] = SchemaBaseline::tableExists($table)
                ? DB::table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all()
                : [];
        }
        $connection = trim((string) config('seo_intel.connection', 'seo_intel'));
        foreach (self::SEARCH_TABLES as $table) {
            $authority[$table] = \App\Support\SchemaBaseline::tableExists($table, $connection)
                ? DB::connection($connection)->table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all()
                : [];
        }

        return $this->fingerprint([
            'non_target_personality' => $assets,
            'non_target_personality_revisions' => $revisions,
            'non_personality_authority' => $authority,
        ]);
    }

    private function searchFingerprint(): string
    {
        $connection = trim((string) config('seo_intel.connection', 'seo_intel'));
        $rows = [];
        foreach (self::SEARCH_TABLES as $table) {
            $rows[$table] = \App\Support\SchemaBaseline::tableExists($table, $connection)
                ? DB::connection($connection)->table($table)->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all()
                : [];
        }

        return $this->fingerprint($rows);
    }

    private function databaseFingerprint(): string
    {
        return $this->fingerprint([
            SchemaBaseline::tableExists('personality_public_content_assets')
                ? DB::table('personality_public_content_assets')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all()
                : [],
            SchemaBaseline::tableExists('personality_public_content_asset_revisions')
                ? DB::table('personality_public_content_asset_revisions')->orderBy('id')->get()->map(static fn (object $row): array => (array) $row)->all()
                : [],
            $this->nonTargetFingerprint(),
            $this->searchFingerprint(),
        ]);
    }

    private function writeExclusive(string $path, string $payload): void
    {
        $handle = @fopen($path, 'x+b');
        if (! is_resource($handle)) {
            throw new RuntimeException('Unable to reserve EN52 backup output: '.basename($path).'.');
        }
        try {
            if (! @chmod($path, 0600)) {
                throw new RuntimeException('Unable to protect EN52 backup output.');
            }
            $written = 0;
            while ($written < strlen($payload)) {
                $chunk = fwrite($handle, substr($payload, $written));
                if (! is_int($chunk) || $chunk < 1) {
                    throw new RuntimeException('Unable to persist EN52 backup output.');
                }
                $written += $chunk;
            }
            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('Unable to durably persist EN52 backup output.');
            }
        } catch (Throwable $throwable) {
            fclose($handle);
            File::delete($path);

            throw $throwable;
        }
        fclose($handle);
    }

    private function writeAtomicExclusive(string $path, string $payload): void
    {
        if (File::exists($path)) {
            throw new RuntimeException('EN52 backup output already exists; refusing to overwrite it.');
        }
        $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(8));
        $this->writeExclusive($temporaryPath, $payload);
        try {
            if (! @link($temporaryPath, $path)) {
                throw new RuntimeException('Unable to atomically publish EN52 backup output: '.basename($path).'.');
            }
        } finally {
            File::delete($temporaryPath);
        }
    }

    private function fingerprint(mixed $value): string
    {
        return hash('sha256', $this->stableJson($value));
    }

    private function stableJson(mixed $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalize, $item);
        };

        return json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
