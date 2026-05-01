<?php

namespace App\Services\Content\Publisher;

use App\Services\Storage\BlobCatalogService;
use App\Services\Storage\ContentReleaseManifestCatalogService;
use App\Support\CacheKeys;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

final class ContentPackPublisher
{
    private const SOURCE_UPLOAD = 'upload';

    private const SOURCE_S3 = 's3';

    public function __construct(
        private readonly BlobCatalogService $blobCatalogService,
        private readonly ContentReleaseManifestCatalogService $manifestCatalogService,
    ) {}

    public function ingest(?UploadedFile $file, ?string $s3Key, array $options = []): array
    {
        $createdBy = $this->trimOrDefault($options['created_by'] ?? 'admin', 'admin');
        $dirAliasOverride = $this->trimOrEmpty($options['dir_alias'] ?? '');
        $selfCheck = (bool) ($options['self_check'] ?? false);

        if ($file === null && $this->trimOrEmpty($s3Key ?? '') === '') {
            return [
                'ok' => false,
                'error' => 'MISSING_SOURCE',
                'message' => 'file or s3_key is required.',
            ];
        }

        $versionId = (string) Str::uuid();
        $releaseRoot = $this->releaseRoot($versionId);
        $this->ensureDirectory($releaseRoot);

        $sourceType = $file !== null ? self::SOURCE_UPLOAD : self::SOURCE_S3;
        $sourceRef = '';
        $sha256 = '';
        $manifestJson = '';
        $packId = '';
        $contentPackageVersion = '';
        $dirAlias = $dirAliasOverride;
        $region = '';
        $locale = '';
        $extractedRelPath = '';

        if ($file !== null) {
            $zipPath = $releaseRoot.DIRECTORY_SEPARATOR.'pack.zip';
            $file->move($releaseRoot, 'pack.zip');
            $sourceRef = $this->relativeToPrivate($zipPath);
            $sha256 = (string) hash_file('sha256', $zipPath);

            $extractRoot = $releaseRoot.DIRECTORY_SEPARATOR.'extracted';
            $this->ensureDirectory($extractRoot);

            try {
                $this->extractZip($zipPath, $extractRoot);
            } catch (\Throwable $e) {
                return [
                    'ok' => false,
                    'error' => 'ZIP_EXTRACT_FAILED',
                    'message' => $e->getMessage(),
                ];
            }

            $found = $this->findPackRoot($extractRoot);
            if (! ($found['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => (string) ($found['error'] ?? 'PACK_ROOT_NOT_FOUND'),
                    'message' => (string) ($found['message'] ?? 'pack root not found'),
                ];
            }

            $packRoot = (string) $found['pack_root'];
            $manifestPath = (string) $found['manifest_path'];

            $manifestRead = $this->readManifest($manifestPath);
            if (! ($manifestRead['ok'] ?? false)) {
                return [
                    'ok' => false,
                    'error' => (string) ($manifestRead['error'] ?? 'MANIFEST_INVALID'),
                    'message' => (string) ($manifestRead['message'] ?? 'manifest invalid'),
                ];
            }

            $manifest = (array) $manifestRead['manifest'];
            $manifestJson = (string) $manifestRead['raw'];

            $packId = (string) ($manifest['pack_id'] ?? '');
            $contentPackageVersion = (string) ($manifest['content_package_version'] ?? '');
            $region = (string) ($manifest['region'] ?? ($options['region'] ?? ''));
            $locale = (string) ($manifest['locale'] ?? ($options['locale'] ?? ''));

            if ($packId === '' || $contentPackageVersion === '') {
                return [
                    'ok' => false,
                    'error' => 'MANIFEST_FIELDS_MISSING',
                    'message' => 'manifest.pack_id and manifest.content_package_version are required.',
                ];
            }

            $questionsPath = $packRoot.DIRECTORY_SEPARATOR.'questions.json';
            if (! File::exists($questionsPath)) {
                return [
                    'ok' => false,
                    'error' => 'QUESTIONS_MISSING',
                    'message' => 'questions.json not found in pack root.',
                ];
            }

            if ($dirAlias === '') {
                $dirAlias = basename($packRoot);
            }

            $extractedRelPath = $this->relativeToPrivate($packRoot);

            if ($selfCheck) {
                $check = $this->runSelfCheck($manifestPath);
                if (! ($check['ok'] ?? false)) {
                    return [
                        'ok' => false,
                        'error' => 'SELF_CHECK_FAILED',
                        'message' => (string) ($check['message'] ?? 'self-check failed'),
                    ];
                }
            }
        } else {
            $sourceRef = $this->trimOrEmpty($s3Key ?? '');
            $region = $this->trimOrEmpty($options['region'] ?? '');
            $locale = $this->trimOrEmpty($options['locale'] ?? '');
            $packId = $this->trimOrEmpty($options['pack_id'] ?? '');
            $contentPackageVersion = $this->trimOrEmpty($options['content_package_version'] ?? '');
            if ($dirAlias === '') {
                $dirAlias = $this->trimOrEmpty($options['dir_alias'] ?? '');
            }
        }

        DB::table('content_pack_versions')->insert([
            'id' => $versionId,
            'region' => $region,
            'locale' => $locale,
            'pack_id' => $packId,
            'content_package_version' => $contentPackageVersion,
            'dir_version_alias' => $dirAlias,
            'source_type' => $sourceType,
            'source_ref' => $sourceRef,
            'sha256' => $sha256,
            'manifest_json' => $manifestJson,
            'extracted_rel_path' => $extractedRelPath,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'ok' => true,
            'version_id' => $versionId,
            'pack_id' => $packId,
            'content_package_version' => $contentPackageVersion,
            'dir_version_alias' => $dirAlias,
        ];
    }

    public function publish(string $versionId, string $region, string $locale, string $dirAlias, bool $probe, ?string $baseUrl = null): array
    {
        $releaseId = (string) Str::uuid();
        $status = 'failed';
        $message = '';
        $probes = [
            'health' => false,
            'questions' => false,
            'content_packs' => false,
        ];

        $fromPackId = '';
        $toPackId = '';
        $fromVersionId = null;
        $toVersionId = $versionId !== '' ? $versionId : null;
        $expectedPackId = '';
        $targetDir = '';
        $releaseEvidence = $this->emptyReleaseEvidence();
        $version = null;

        $tmpDir = '';
        $previousPackPath = '';
        $sourcePath = '';
        $packVersion = '';
        $releaseManifestJson = '';
        $gitSha = $this->resolveGitSha();
        $probeScaleCode = '';
        $probeFormCode = '';
        $probeSlug = '';

        try {
            $version = DB::table('content_pack_versions')->where('id', $versionId)->first();
            if (! $version) {
                throw new RuntimeException('VERSION_NOT_FOUND');
            }

            $storedRegion = trim((string) ($version->region ?? ''));
            $storedLocale = trim((string) ($version->locale ?? ''));
            if ($storedRegion !== '' && $storedRegion !== $region) {
                throw new RuntimeException('REGION_MISMATCH');
            }
            if ($storedLocale !== '' && $storedLocale !== $locale) {
                throw new RuntimeException('LOCALE_MISMATCH');
            }

            $sourceRel = trim((string) ($version->extracted_rel_path ?? ''));
            if ($sourceRel === '') {
                throw new RuntimeException('SOURCE_PATH_MISSING');
            }

            $sourcePath = $this->absoluteFromPrivate($sourceRel);
            if ($sourcePath === '' || ! File::isDirectory($sourcePath)) {
                throw new RuntimeException('SOURCE_PACK_NOT_FOUND');
            }

            $sourceManifest = $this->manifestFieldsFromDir($sourcePath);
            $toPackId = (string) ($sourceManifest['pack_id'] ?? '');
            $expectedPackId = $toPackId;
            $packVersion = trim((string) ($sourceManifest['content_package_version'] ?? ''));
            $probeScaleCode = trim((string) ($sourceManifest['scale_code'] ?? ''));
            $probeFormCode = trim((string) ($sourceManifest['form_code'] ?? ''));
            $probeSlug = trim((string) ($sourceManifest['primary_slug'] ?? ''));
            if ($toPackId === '') {
                $toPackId = (string) ($version->pack_id ?? '');
                $expectedPackId = $toPackId;
            }
            if ($packVersion === '') {
                $packVersion = trim((string) ($version->content_package_version ?? ''));
            }

            $packsRoot = $this->packsRoot();
            $targetDir = $this->targetPackDir($packsRoot, $region, $locale, $dirAlias);
            $tmpDir = $targetDir.'.tmp.'.Str::uuid();

            $this->ensureDirectory(dirname($targetDir));

            if (! File::copyDirectory($sourcePath, $tmpDir)) {
                throw new RuntimeException('COPY_TO_TMP_FAILED');
            }

            $backupRoot = $this->backupsRoot($releaseId);
            $this->ensureDirectory($backupRoot);
            $previousPackPath = $backupRoot.DIRECTORY_SEPARATOR.'previous_pack';

            if (File::isDirectory($targetDir)) {
                if (! File::moveDirectory($targetDir, $previousPackPath)) {
                    throw new RuntimeException('BACKUP_MOVE_FAILED');
                }

                $prevFields = $this->manifestFieldsFromDir($previousPackPath);
                $fromPackId = (string) ($prevFields['pack_id'] ?? '');
                $fromVersionId = $this->findVersionId(
                    (string) ($prevFields['pack_id'] ?? ''),
                    (string) ($prevFields['content_package_version'] ?? ''),
                    $region,
                    $locale
                );
            }

            if (! File::moveDirectory($tmpDir, $targetDir)) {
                throw new RuntimeException('SWAP_FAILED');
            }

            $releaseEvidence = $this->resolveReleaseEvidence(
                is_string($version->manifest_json ?? null) ? (string) $version->manifest_json : '',
                $targetDir
            );
            $releaseManifestJson = $this->manifestJsonFromDir($targetDir);
            if ($releaseManifestJson === '' && is_string($version->manifest_json ?? null)) {
                $releaseManifestJson = (string) $version->manifest_json;
            }
            $status = 'success';
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        } finally {
            if ($tmpDir !== '' && File::isDirectory($tmpDir)) {
                File::deleteDirectory($tmpDir);
            }
        }

        if ($status === 'success') {
            $this->clearCaches();
            if ($probe) {
                $probeResult = $this->probe($baseUrl, $region, $locale, $expectedPackId, $probeScaleCode, $probeFormCode, $probeSlug);
                $probes = $probeResult['probes'];
                if (! ($probeResult['ok'] ?? false)) {
                    $status = 'failed';
                    $message = (string) ($probeResult['message'] ?? 'probe_failed');
                }
            } else {
                $message = $message === '' ? 'probe_skipped' : $message;
            }
        }

        $releaseStoragePath = $status === 'success' && $sourcePath !== ''
            ? $this->absoluteContentPath($sourcePath)
            : null;

        $releaseActor = is_object($version)
            ? $this->trimOrDefault($version->created_by ?? 'admin', 'admin')
            : 'admin';

        $releaseRow = [
            'id' => $releaseId,
            'action' => 'publish',
            'region' => $region,
            'locale' => $locale,
            'dir_alias' => $dirAlias,
            'from_version_id' => $fromVersionId,
            'to_version_id' => $toVersionId,
            'from_pack_id' => $fromPackId,
            'to_pack_id' => $toPackId,
            'status' => $status,
            'message' => $message === '' ? null : $message,
            'created_by' => $releaseActor,
            'manifest_hash' => $releaseEvidence['manifest_hash'] !== '' ? $releaseEvidence['manifest_hash'] : null,
            'compiled_hash' => $releaseEvidence['compiled_hash'] !== '' ? $releaseEvidence['compiled_hash'] : null,
            'content_hash' => $releaseEvidence['content_hash'] !== '' ? $releaseEvidence['content_hash'] : null,
            'norms_version' => $releaseEvidence['norms_version'] !== '' ? $releaseEvidence['norms_version'] : null,
            'git_sha' => $gitSha,
            'pack_version' => $status === 'success' && $packVersion !== '' ? $packVersion : null,
            'manifest_json' => $status === 'success' && $releaseManifestJson !== '' ? $releaseManifestJson : null,
            'storage_path' => $releaseStoragePath,
            'source_commit' => $status === 'success' ? $gitSha : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('content_pack_releases')->insert($releaseRow);
        $this->shadowActivateLegacyRelease($releaseRow);
        $this->dualWriteLegacyReleaseMetadata($releaseRow);
        $resolvedScaleCode = strtoupper(trim($toPackId));
        if (! in_array($resolvedScaleCode, ['BIG5_OCEAN', 'SDS_20'], true)) {
            $resolvedScaleCode = 'BIG5_OCEAN';
        }
        $auditAction = $resolvedScaleCode === 'SDS_20' ? 'sds_pack_publish' : 'big5_pack_publish';

        $this->recordReleaseAudit(
            $auditAction,
            $releaseId,
            $status,
            $message,
            [
                'scale_code' => $resolvedScaleCode,
                'region' => $region,
                'locale' => $locale,
                'dir_alias' => $dirAlias,
                'content_publish_mode' => strtolower(trim((string) config('scale_identity.content_publish_mode', 'legacy'))),
                'staged_source_type' => is_object($version) ? (string) ($version->source_type ?? '') : '',
                'staged_source_ref' => is_object($version) ? (string) ($version->source_ref ?? '') : '',
                'from_pack_id' => $fromPackId,
                'to_pack_id' => $toPackId,
                'from_version_id' => $fromVersionId,
                'to_version_id' => $toVersionId,
                'probes' => $probes,
                'manifest_hash' => $releaseEvidence['manifest_hash'],
                'compiled_hash' => $releaseEvidence['compiled_hash'],
                'content_hash' => $releaseEvidence['content_hash'],
                'norms_version' => $releaseEvidence['norms_version'],
                'git_sha' => $gitSha,
                'created_by' => $releaseActor,
                'org_id' => $this->orgIdFromActor($releaseActor),
            ]
        );

        return [
            'ok' => true,
            'status' => $status,
            'release_id' => $releaseId,
            'from_pack_id' => $fromPackId,
            'to_pack_id' => $toPackId,
            'probes' => $probes,
            'message' => $message,
        ];
    }

    public function rollback(
        string $region,
        string $locale,
        string $dirAlias,
        bool $probe,
        ?string $baseUrl = null,
        ?string $toReleaseId = null
    ): array {
        $releaseId = (string) Str::uuid();
        $status = 'failed';
        $message = '';
        $probes = [
            'health' => false,
            'questions' => false,
            'content_packs' => false,
        ];

        $rolledBack = [
            'version_id' => '',
            'pack_id' => '',
            'content_package_version' => '',
        ];

        $fromVersionId = null;
        $toVersionId = null;
        $fromPackId = '';
        $toPackId = '';
        $sourceReleaseId = '';
        $releaseEvidence = $this->emptyReleaseEvidence();
        $targetDir = '';
        $releaseManifestJson = '';
        $restoredPackVersion = '';
        $gitSha = $this->resolveGitSha();
        $manifestVersion = '';
        $rollbackSourcePath = '';
        $probeScaleCode = '';
        $probeFormCode = '';
        $probeSlug = '';

        $tmpDir = '';

        try {
            $selected = $this->selectRollbackSourceRelease($region, $locale, $dirAlias, $toReleaseId);
            if (! ($selected['ok'] ?? false)) {
                throw new RuntimeException((string) ($selected['error'] ?? 'NO_PUBLISH_TO_ROLLBACK'));
            }

            $last = $selected['release'] ?? null;
            $backupPath = (string) ($selected['backup_path'] ?? '');
            if (! is_object($last) || $backupPath === '') {
                throw new RuntimeException('BACKUP_NOT_FOUND');
            }
            $sourceReleaseId = (string) ($last->id ?? '');
            $rollbackSourcePath = $backupPath;

            $fromVersionId = $last->to_version_id ?? null;
            $fromPackId = (string) ($last->to_pack_id ?? '');

            $packsRoot = $this->packsRoot();
            $targetDir = $this->targetPackDir($packsRoot, $region, $locale, $dirAlias);
            $tmpDir = $targetDir.'.tmp.'.Str::uuid();

            $this->ensureDirectory(dirname($targetDir));

            if (! File::copyDirectory($backupPath, $tmpDir)) {
                throw new RuntimeException('COPY_TO_TMP_FAILED');
            }

            $rollbackBackupRoot = $this->backupsRoot($releaseId);
            $this->ensureDirectory($rollbackBackupRoot);
            if (File::isDirectory($targetDir)) {
                $currentBackup = $rollbackBackupRoot.DIRECTORY_SEPARATOR.'current_pack';
                File::moveDirectory($targetDir, $currentBackup);
            }

            if (! File::moveDirectory($tmpDir, $targetDir)) {
                throw new RuntimeException('SWAP_FAILED');
            }

            $restored = $this->manifestFieldsFromDir($targetDir);
            $rolledBack['pack_id'] = (string) ($restored['pack_id'] ?? '');
            $rolledBack['content_package_version'] = (string) ($restored['content_package_version'] ?? '');
            $probeScaleCode = trim((string) ($restored['scale_code'] ?? ''));
            $probeFormCode = trim((string) ($restored['form_code'] ?? ''));
            $probeSlug = trim((string) ($restored['primary_slug'] ?? ''));
            $rolledBack['version_id'] = $this->findVersionId(
                $rolledBack['pack_id'],
                $rolledBack['content_package_version'],
                $region,
                $locale
            ) ?? '';

            $toVersionId = $rolledBack['version_id'] !== '' ? $rolledBack['version_id'] : ($last->from_version_id ?? null);
            $toPackId = $rolledBack['pack_id'];
            $restoredPackVersion = trim((string) ($rolledBack['content_package_version'] ?? ''));
            if ($toPackId === '') {
                $toPackId = (string) ($last->from_pack_id ?? '');
                if ($toPackId === '') {
                    $toPackId = (string) ($last->to_pack_id ?? '');
                }
                $rolledBack['pack_id'] = $toPackId;
            }

            $manifestJson = '';
            if (is_string($last->from_version_id ?? null) && trim((string) $last->from_version_id) !== '') {
                $versionRow = DB::table('content_pack_versions')->where('id', (string) $last->from_version_id)->first();
                if (is_object($versionRow)) {
                    $manifestJson = is_string($versionRow->manifest_json ?? null) ? (string) $versionRow->manifest_json : '';
                    $manifestVersion = trim((string) ($versionRow->content_package_version ?? ''));
                }
            }
            if ($manifestJson === '' && is_string($last->to_version_id ?? null) && trim((string) $last->to_version_id) !== '') {
                $versionRow = DB::table('content_pack_versions')->where('id', (string) $last->to_version_id)->first();
                if (is_object($versionRow)) {
                    $manifestJson = is_string($versionRow->manifest_json ?? null) ? (string) $versionRow->manifest_json : '';
                    if ($manifestVersion === '') {
                        $manifestVersion = trim((string) ($versionRow->content_package_version ?? ''));
                    }
                }
            }
            if ($restoredPackVersion === '') {
                $restoredPackVersion = $manifestVersion;
            }
            $releaseEvidence = $this->resolveReleaseEvidence($manifestJson, $targetDir);
            $releaseManifestJson = $this->manifestJsonFromDir($targetDir);
            if ($releaseManifestJson === '') {
                $releaseManifestJson = $manifestJson;
            }
            $status = 'success';
        } catch (\Throwable $e) {
            $message = $e->getMessage();
        } finally {
            if ($tmpDir !== '' && File::isDirectory($tmpDir)) {
                File::deleteDirectory($tmpDir);
            }
        }

        if ($status === 'success') {
            $this->clearCaches();
            if ($probe) {
                $probeResult = $this->probe($baseUrl, $region, $locale, $toPackId, $probeScaleCode, $probeFormCode, $probeSlug);
                $probes = $probeResult['probes'];
                if (! ($probeResult['ok'] ?? false)) {
                    $status = 'failed';
                    $message = (string) ($probeResult['message'] ?? 'probe_failed');
                }
            } else {
                $message = $message === '' ? 'probe_skipped' : $message;
            }
        }

        $releaseStoragePath = $status === 'success' && $rollbackSourcePath !== ''
            ? $this->absoluteContentPath($rollbackSourcePath)
            : null;

        $releaseRow = [
            'id' => $releaseId,
            'action' => 'rollback',
            'region' => $region,
            'locale' => $locale,
            'dir_alias' => $dirAlias,
            'from_version_id' => $fromVersionId,
            'to_version_id' => $toVersionId,
            'from_pack_id' => $fromPackId,
            'to_pack_id' => $toPackId,
            'status' => $status,
            'message' => $message === '' ? null : $message,
            'manifest_hash' => $releaseEvidence['manifest_hash'] !== '' ? $releaseEvidence['manifest_hash'] : null,
            'compiled_hash' => $releaseEvidence['compiled_hash'] !== '' ? $releaseEvidence['compiled_hash'] : null,
            'content_hash' => $releaseEvidence['content_hash'] !== '' ? $releaseEvidence['content_hash'] : null,
            'norms_version' => $releaseEvidence['norms_version'] !== '' ? $releaseEvidence['norms_version'] : null,
            'git_sha' => $gitSha,
            'pack_version' => $status === 'success' && $restoredPackVersion !== '' ? $restoredPackVersion : null,
            'manifest_json' => $status === 'success' && $releaseManifestJson !== '' ? $releaseManifestJson : null,
            'storage_path' => $releaseStoragePath,
            'source_commit' => $status === 'success' ? $gitSha : null,
            'created_by' => 'admin',
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('content_pack_releases')->insert($releaseRow);
        $this->shadowActivateLegacyRelease($releaseRow);
        $this->dualWriteLegacyReleaseMetadata($releaseRow);
        $this->recordReleaseAudit(
            'big5_pack_rollback',
            $releaseId,
            $status,
            $message,
            [
                'scale_code' => 'BIG5_OCEAN',
                'region' => $region,
                'locale' => $locale,
                'dir_alias' => $dirAlias,
                'source_release_id' => $sourceReleaseId,
                'from_pack_id' => $fromPackId,
                'to_pack_id' => $toPackId,
                'from_version_id' => $fromVersionId,
                'to_version_id' => $toVersionId,
                'rolled_back_to' => $rolledBack,
                'probes' => $probes,
                'manifest_hash' => $releaseEvidence['manifest_hash'],
                'compiled_hash' => $releaseEvidence['compiled_hash'],
                'content_hash' => $releaseEvidence['content_hash'],
                'norms_version' => $releaseEvidence['norms_version'],
                'git_sha' => $gitSha,
            ]
        );

        return [
            'ok' => true,
            'status' => $status,
            'release_id' => $releaseId,
            'source_release_id' => $sourceReleaseId,
            'rolled_back_to' => $rolledBack,
            'probes' => $probes,
            'message' => $message,
        ];
    }

    /**
     * @return array{manifest_hash:string,compiled_hash:string,content_hash:string,norms_version:string}
     */
    private function emptyReleaseEvidence(): array
    {
        return [
            'manifest_hash' => '',
            'compiled_hash' => '',
            'content_hash' => '',
            'norms_version' => '',
        ];
    }

    /**
     * @return array{ok:bool,release?:object,backup_path?:string,error?:string}
     */
    private function selectRollbackSourceRelease(
        string $region,
        string $locale,
        string $dirAlias,
        ?string $toReleaseId
    ): array {
        $toReleaseId = trim((string) $toReleaseId);
        if ($toReleaseId !== '') {
            $candidate = DB::table('content_pack_releases')
                ->where('id', $toReleaseId)
                ->where('action', 'publish')
                ->where('status', 'success')
                ->where('region', $region)
                ->where('locale', $locale)
                ->where('dir_alias', $dirAlias)
                ->first();

            if (! $candidate) {
                return [
                    'ok' => false,
                    'error' => 'TARGET_RELEASE_NOT_FOUND',
                ];
            }

            $backupPath = $this->backupsRoot((string) $candidate->id).DIRECTORY_SEPARATOR.'previous_pack';
            if (! File::isDirectory($backupPath)) {
                return [
                    'ok' => false,
                    'error' => 'TARGET_RELEASE_BACKUP_NOT_FOUND',
                ];
            }

            return [
                'ok' => true,
                'release' => $candidate,
                'backup_path' => $backupPath,
            ];
        }

        $publishRows = DB::table('content_pack_releases')
            ->where('action', 'publish')
            ->where('status', 'success')
            ->where('region', $region)
            ->where('locale', $locale)
            ->where('dir_alias', $dirAlias)
            ->orderByDesc('created_at')
            ->orderByDesc('updated_at')
            ->get();

        if ($publishRows->isEmpty()) {
            return [
                'ok' => false,
                'error' => 'NO_PUBLISH_TO_ROLLBACK',
            ];
        }

        foreach ($publishRows as $candidate) {
            $backupPath = $this->backupsRoot((string) $candidate->id).DIRECTORY_SEPARATOR.'previous_pack';
            if (! File::isDirectory($backupPath)) {
                continue;
            }

            return [
                'ok' => true,
                'release' => $candidate,
                'backup_path' => $backupPath,
            ];
        }

        return [
            'ok' => false,
            'error' => 'BACKUP_NOT_FOUND',
        ];
    }

    /**
     * @param  array<string,mixed>  $release
     */
    private function shadowActivateLegacyRelease(array $release): void
    {
        if (! $this->shouldDualWriteLegacyBridge()) {
            return;
        }
        if (($release['status'] ?? '') !== 'success') {
            return;
        }

        $releaseId = trim((string) ($release['id'] ?? ''));
        $packId = strtoupper(trim((string) ($release['to_pack_id'] ?? '')));
        $packVersion = trim((string) ($release['pack_version'] ?? ''));
        if ($releaseId === '' || $packId === '' || $packVersion === '') {
            return;
        }

        try {
            DB::table('content_pack_activations')->updateOrInsert(
                [
                    'pack_id' => $packId,
                    'pack_version' => $packVersion,
                ],
                [
                    'release_id' => $releaseId,
                    'activated_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            Log::warning('LEGACY_CONTENT_ACTIVATION_WRITE_FAILED', [
                'release_id' => $releaseId,
                'pack_id' => $packId,
                'pack_version' => $packVersion,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $release
     */
    private function dualWriteLegacyReleaseMetadata(array $release): void
    {
        if (! $this->shouldDualWriteLegacyBridge()) {
            return;
        }
        if (($release['status'] ?? '') !== 'success') {
            return;
        }
        if (! $this->shouldCatalogLegacyBlobs() && ! $this->shouldCatalogLegacyManifest()) {
            return;
        }

        $storagePath = trim((string) ($release['storage_path'] ?? ''));
        if ($storagePath === '' || ! File::isDirectory($storagePath)) {
            return;
        }

        try {
            $compiledFiles = $this->collectCompiledFilesFromRoot($storagePath);
        } catch (\Throwable $e) {
            Log::warning('LEGACY_CONTENT_METADATA_FILE_SCAN_FAILED', [
                'release_id' => (string) ($release['id'] ?? ''),
                'storage_path' => $storagePath,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $blobCatalogHadFailures = false;
        if ($this->shouldCatalogLegacyBlobs()) {
            foreach ($compiledFiles as $file) {
                try {
                    $this->blobCatalogService->upsertBlob([
                        'hash' => $file['hash'],
                        'disk' => 'local',
                        'storage_path' => $this->blobCatalogService->storagePathForHash($file['hash']),
                        'size_bytes' => $file['size_bytes'],
                        'content_type' => $file['content_type'],
                        'encoding' => 'identity',
                        'ref_count' => 0,
                        'last_verified_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    Log::warning('LEGACY_CONTENT_BLOB_CATALOG_WRITE_FAILED', [
                        'release_id' => (string) ($release['id'] ?? ''),
                        'logical_path' => (string) ($file['logical_path'] ?? ''),
                        'blob_hash' => (string) ($file['hash'] ?? ''),
                        'error' => $e->getMessage(),
                    ]);
                    $blobCatalogHadFailures = true;
                }
            }
        }

        if (! $this->shouldCatalogLegacyManifest()) {
            return;
        }

        $manifestHash = trim((string) ($release['manifest_hash'] ?? ''));
        if ($manifestHash === '') {
            return;
        }

        if ($this->shouldCatalogLegacyBlobs() && $blobCatalogHadFailures) {
            Log::warning('LEGACY_CONTENT_MANIFEST_CATALOG_SKIPPED_DUE_TO_BLOB_FAILURE', [
                'release_id' => (string) ($release['id'] ?? ''),
                'manifest_hash' => $manifestHash,
                'storage_path' => $storagePath,
            ]);

            return;
        }

        $existingManifest = $this->manifestCatalogService->findByManifestHash($manifestHash);
        $manifestReleaseId = $existingManifest?->content_pack_release_id ?: ($release['id'] ?? null);
        $manifestStorageDisk = trim((string) ($existingManifest?->storage_disk ?? ''));
        if ($manifestStorageDisk === '') {
            $manifestStorageDisk = 'local';
        }
        $manifestStoragePath = trim((string) ($existingManifest?->storage_path ?? ''));
        if ($manifestStoragePath === '') {
            $manifestStoragePath = $storagePath;
        }

        $files = [];
        foreach ($compiledFiles as $file) {
            $files[] = [
                'logical_path' => $file['logical_path'],
                'blob_hash' => $file['hash'],
                'size_bytes' => $file['size_bytes'],
                'role' => $file['role'],
                'content_type' => $file['content_type'],
                'encoding' => 'identity',
                'checksum' => 'sha256:'.$file['hash'],
            ];
        }

        try {
            $this->manifestCatalogService->upsertManifest([
                'content_pack_release_id' => $manifestReleaseId,
                'manifest_hash' => $manifestHash,
                'storage_disk' => $manifestStorageDisk,
                'storage_path' => $manifestStoragePath,
                'pack_id' => $release['to_pack_id'] ?? null,
                'pack_version' => $release['pack_version'] ?? null,
                'compiled_hash' => $release['compiled_hash'] ?? null,
                'content_hash' => $release['content_hash'] ?? null,
                'norms_version' => $release['norms_version'] ?? null,
                'source_commit' => $release['source_commit'] ?? null,
                'payload_json' => $this->decodeManifestJson($release['manifest_json'] ?? null),
            ], $files);
        } catch (\Throwable $e) {
            Log::warning('LEGACY_CONTENT_MANIFEST_CATALOG_WRITE_FAILED', [
                'release_id' => (string) ($release['id'] ?? ''),
                'manifest_hash' => $manifestHash,
                'storage_path' => $storagePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{manifest_hash:string,compiled_hash:string,content_hash:string,norms_version:string}
     */
    private function resolveReleaseEvidence(string $manifestJson, string $targetDir): array
    {
        $evidence = $this->emptyReleaseEvidence();

        $manifestJson = trim($manifestJson);
        if ($manifestJson !== '') {
            $evidence['manifest_hash'] = hash('sha256', $manifestJson);
            $manifestDecoded = json_decode($manifestJson, true);
            if (is_array($manifestDecoded)) {
                $evidence['compiled_hash'] = trim((string) ($manifestDecoded['compiled_hash'] ?? ''));
                $evidence['content_hash'] = trim((string) ($manifestDecoded['content_hash'] ?? ''));
                $evidence['norms_version'] = trim((string) ($manifestDecoded['norms_version'] ?? ''));
            }
        }

        if ($targetDir !== '' && File::isDirectory($targetDir)) {
            $manifestPath = $targetDir.DIRECTORY_SEPARATOR.'compiled'.DIRECTORY_SEPARATOR.'manifest.json';
            if (File::exists($manifestPath)) {
                $compiledManifestJson = (string) File::get($manifestPath);
                $compiledManifestDecoded = json_decode($compiledManifestJson, true);
                if (is_array($compiledManifestDecoded)) {
                    if ($evidence['manifest_hash'] === '') {
                        $evidence['manifest_hash'] = hash('sha256', $compiledManifestJson);
                    }
                    if ($evidence['compiled_hash'] === '') {
                        $evidence['compiled_hash'] = trim((string) ($compiledManifestDecoded['compiled_hash'] ?? ''));
                        if ($evidence['compiled_hash'] === '') {
                            $hashes = is_array($compiledManifestDecoded['hashes'] ?? null) ? $compiledManifestDecoded['hashes'] : [];
                            $evidence['compiled_hash'] = $this->hashMap($hashes);
                        }
                    }
                    if ($evidence['content_hash'] === '') {
                        $evidence['content_hash'] = trim((string) ($compiledManifestDecoded['content_hash'] ?? ''));
                    }
                    if ($evidence['norms_version'] === '') {
                        $evidence['norms_version'] = trim((string) ($compiledManifestDecoded['norms_version'] ?? ''));
                    }
                }
            }

            if ($evidence['content_hash'] === '') {
                $rawDir = $targetDir.DIRECTORY_SEPARATOR.'raw';
                $evidence['content_hash'] = $this->hashDirectory($rawDir);
            }
            if ($evidence['norms_version'] === '') {
                $normsPath = $targetDir.DIRECTORY_SEPARATOR.'compiled'.DIRECTORY_SEPARATOR.'norms.compiled.json';
                if (File::exists($normsPath)) {
                    $normsDecoded = json_decode((string) File::get($normsPath), true);
                    if (is_array($normsDecoded)) {
                        $groups = is_array($normsDecoded['groups'] ?? null) ? $normsDecoded['groups'] : [];
                        $evidence['norms_version'] = $this->resolveNormsVersionFromGroups($groups);
                    }
                }
            }
        }

        return $evidence;
    }

    /**
     * @param  array<string,mixed>  $hashes
     */
    private function hashMap(array $hashes): string
    {
        if ($hashes === []) {
            return '';
        }
        ksort($hashes);
        $rows = [];
        foreach ($hashes as $name => $hash) {
            $rows[] = (string) $name.':'.(string) $hash;
        }

        return hash('sha256', implode("\n", $rows));
    }

    private function hashDirectory(string $dir): string
    {
        if (! File::isDirectory($dir)) {
            return '';
        }

        $files = File::allFiles($dir);
        usort($files, static fn ($a, $b): int => strcmp($a->getPathname(), $b->getPathname()));
        $prefix = rtrim($dir, '/\\').DIRECTORY_SEPARATOR;
        $rows = [];
        foreach ($files as $file) {
            $path = $file->getPathname();
            $relative = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $file->getFilename();
            $rows[] = $relative.':'.hash_file('sha256', $path);
        }

        return hash('sha256', implode("\n", $rows));
    }

    /**
     * @param  array<string,mixed>  $groups
     */
    private function resolveNormsVersionFromGroups(array $groups): string
    {
        $preferred = '';
        $fallback = '';
        foreach ($groups as $group) {
            if (! is_array($group)) {
                continue;
            }
            $normsVersion = trim((string) ($group['norms_version'] ?? ''));
            if ($normsVersion === '') {
                continue;
            }
            $status = strtoupper(trim((string) ($group['status'] ?? '')));
            if ($status === 'CALIBRATED') {
                return $normsVersion;
            }
            if ($preferred === '' && $status === 'PROVISIONAL') {
                $preferred = $normsVersion;
            }
            if ($fallback === '') {
                $fallback = $normsVersion;
            }
        }

        return $preferred !== '' ? $preferred : $fallback;
    }

    private function resolveGitSha(): ?string
    {
        $sha = trim((string) config('app.git_sha', ''));
        if ($sha === '') {
            return null;
        }

        return substr($sha, 0, 64);
    }

    private function manifestJsonFromDir(string $packDir): string
    {
        $manifestPath = $packDir.DIRECTORY_SEPARATOR.'manifest.json';
        if (! File::exists($manifestPath)) {
            return '';
        }

        try {
            return (string) File::get($manifestPath);
        } catch (\Throwable) {
            return '';
        }
    }

    private function recordReleaseAudit(
        string $action,
        string $releaseId,
        string $status,
        string $message,
        array $meta
    ): void {
        if (! \Illuminate\Support\Facades\Schema::hasTable('audit_logs')) {
            return;
        }

        try {
            DB::table('audit_logs')->insert([
                'org_id' => $this->orgIdFromAuditMeta($meta),
                'actor_admin_id' => null,
                'action' => $action,
                'target_type' => 'content_pack_release',
                'target_id' => $releaseId,
                'meta_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ip' => null,
                'user_agent' => 'cli/content_pack_publisher',
                'request_id' => null,
                'reason' => $message === '' ? 'ok' : $message,
                'result' => $status === 'success' ? 'success' : 'failed',
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('content pack release audit log failed', [
                'action' => $action,
                'release_id' => $releaseId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function orgIdFromAuditMeta(array $meta): int
    {
        $orgId = $meta['org_id'] ?? null;
        if (is_int($orgId) || is_string($orgId) || is_numeric($orgId)) {
            $raw = trim((string) $orgId);
            if ($raw !== '' && preg_match('/\A\d+\z/', $raw) === 1) {
                return max(0, (int) $raw);
            }
        }

        return $this->orgIdFromActor($meta['created_by'] ?? '');
    }

    private function orgIdFromActor(mixed $actor): int
    {
        $raw = trim((string) $actor);
        if ($raw !== '' && preg_match('/@org:(\d+)\z/', $raw, $matches) === 1) {
            return max(0, (int) $matches[1]);
        }

        return 0;
    }

    private function packsRoot(): string
    {
        $driver = (string) config('content_packs.driver', 'local');
        $driver = $driver === 's3' ? 's3' : 'local';

        $packsRoot = $driver === 's3'
            ? (string) config('content_packs.cache_dir', '')
            : (string) config('content_packs.root', '');

        $packsRoot = rtrim($packsRoot, '/\\');
        if ($packsRoot === '') {
            throw new RuntimeException('PACKS_ROOT_MISSING');
        }

        return $packsRoot;
    }

    private function absoluteContentPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return rtrim($path, '/\\');
        }

        return rtrim(base_path($path), '/\\');
    }

    private function targetPackDir(string $packsRoot, string $region, string $locale, string $dirAlias): string
    {
        return $packsRoot
            .DIRECTORY_SEPARATOR.'default'
            .DIRECTORY_SEPARATOR.$region
            .DIRECTORY_SEPARATOR.$locale
            .DIRECTORY_SEPARATOR.$dirAlias;
    }

    private function releaseRoot(string $versionId): string
    {
        return $this->privateRoot()
            .DIRECTORY_SEPARATOR.'content_releases'
            .DIRECTORY_SEPARATOR.$versionId;
    }

    private function backupsRoot(string $releaseId): string
    {
        return $this->privateRoot()
            .DIRECTORY_SEPARATOR.'content_releases'
            .DIRECTORY_SEPARATOR.'backups'
            .DIRECTORY_SEPARATOR.$releaseId;
    }

    private function privateRoot(): string
    {
        return rtrim(storage_path('app/private'), '/\\');
    }

    private function ensureDirectory(string $path): void
    {
        if (! File::isDirectory($path)) {
            File::makeDirectory($path, 0775, true);
        }
    }

    private function extractZip(string $zipPath, string $extractRoot): void
    {
        $zip = new ZipArchive;
        $opened = $zip->open($zipPath);
        if ($opened !== true) {
            throw new RuntimeException('ZIP_OPEN_FAILED');
        }

        $ok = $zip->extractTo($extractRoot);
        $zip->close();

        if (! $ok) {
            throw new RuntimeException('ZIP_EXTRACT_FAILED');
        }
    }

    private function findPackRoot(string $extractRoot): array
    {
        $candidates = [];

        foreach (File::allFiles($extractRoot) as $file) {
            if ($file->getFilename() !== 'manifest.json') {
                continue;
            }

            $dir = $file->getPath();
            $questionsPath = $dir.DIRECTORY_SEPARATOR.'questions.json';
            if (! File::exists($questionsPath)) {
                continue;
            }

            $candidates[] = [
                'pack_root' => $dir,
                'manifest_path' => $file->getPathname(),
            ];
        }

        if (empty($candidates)) {
            return [
                'ok' => false,
                'error' => 'MANIFEST_NOT_FOUND',
                'message' => 'manifest.json with questions.json not found.',
            ];
        }

        usort($candidates, function (array $a, array $b): int {
            return strlen((string) $a['pack_root']) <=> strlen((string) $b['pack_root']);
        });

        $selected = $candidates[0];

        return [
            'ok' => true,
            'pack_root' => (string) $selected['pack_root'],
            'manifest_path' => (string) $selected['manifest_path'],
        ];
    }

    private function readManifest(string $manifestPath): array
    {
        if ($manifestPath === '' || ! File::exists($manifestPath)) {
            return [
                'ok' => false,
                'error' => 'MANIFEST_NOT_FOUND',
                'message' => 'manifest.json not found.',
            ];
        }

        try {
            $raw = File::get($manifestPath);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'MANIFEST_READ_FAILED',
                'message' => $e->getMessage(),
            ];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'MANIFEST_INVALID_JSON',
                'message' => 'manifest.json invalid JSON.',
            ];
        }

        return [
            'ok' => true,
            'manifest' => $decoded,
            'raw' => $raw,
        ];
    }

    private function manifestFieldsFromDir(string $packDir): array
    {
        $manifestPath = $packDir.DIRECTORY_SEPARATOR.'manifest.json';
        $read = $this->readManifest($manifestPath);
        if (! ($read['ok'] ?? false)) {
            return [
                'pack_id' => '',
                'content_package_version' => '',
            ];
        }

        $manifest = (array) $read['manifest'];

        return [
            'pack_id' => (string) ($manifest['pack_id'] ?? ''),
            'content_package_version' => (string) ($manifest['content_package_version'] ?? ''),
            'scale_code' => (string) ($manifest['scale_code'] ?? ''),
            'form_code' => (string) ($manifest['form_code'] ?? ''),
            'primary_slug' => (string) ($manifest['primary_slug'] ?? ''),
        ];
    }

    private function runSelfCheck(string $manifestPath): array
    {
        try {
            $code = Artisan::call('fap:self-check', ['--path' => $manifestPath]);
            if ($code !== 0) {
                return [
                    'ok' => false,
                    'message' => 'fap:self-check failed',
                ];
            }
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
        }

        return ['ok' => true];
    }

    private function clearCaches(): void
    {
        try {
            Artisan::call('cache:clear');
        } catch (\Throwable $e) {
            Log::warning('CONTENT_PACK_PUBLISHER_CACHE_CLEAR_FAILED', [
                'action' => 'cache:clear',
                'exception' => $e,
            ]);
        }

        try {
            Cache::store('hot_redis')->flush();
        } catch (\Throwable $e) {
            Log::warning('CONTENT_PACK_PUBLISHER_CACHE_CLEAR_FAILED', [
                'action' => 'hot_redis.flush',
                'exception' => $e,
            ]);
        }

        try {
            Cache::forget(CacheKeys::packsIndex());
        } catch (\Throwable $e) {
            Log::warning('CONTENT_PACK_PUBLISHER_CACHE_CLEAR_FAILED', [
                'action' => 'default.forget',
                'cache_key' => CacheKeys::packsIndex(),
                'exception' => $e,
            ]);
        }

        try {
            Cache::store('hot_redis')->forget(CacheKeys::packsIndex());
        } catch (\Throwable $e) {
            Log::warning('CONTENT_PACK_PUBLISHER_CACHE_CLEAR_FAILED', [
                'action' => 'hot_redis.forget',
                'cache_key' => CacheKeys::packsIndex(),
                'exception' => $e,
            ]);
        }
    }

    private function probe(
        ?string $baseUrl,
        string $region,
        string $locale,
        string $expectedPackId = '',
        ?string $scaleCode = null,
        ?string $formCode = null,
        ?string $slug = null,
    ): array {
        return (new ContentProbeService)->probe(
            $this->normalizeBaseUrl($baseUrl),
            $region,
            $locale,
            $expectedPackId,
            $scaleCode,
            $formCode,
            $slug
        );
    }

    private function normalizeBaseUrl(?string $baseUrl): string
    {
        $baseUrl = trim((string) $baseUrl);
        if ($baseUrl === '') {
            $baseUrl = trim((string) config('app.url', ''));
        }
        if ($baseUrl === '') {
            $baseUrl = trim((string) \App\Support\RuntimeConfig::value('APP_URL', ''));
        }

        return rtrim($baseUrl, '/');
    }

    private function findVersionId(string $packId, string $contentPackageVersion, string $region, string $locale): ?string
    {
        $packId = trim($packId);
        $contentPackageVersion = trim($contentPackageVersion);

        if ($packId === '' || $contentPackageVersion === '') {
            return null;
        }

        $query = DB::table('content_pack_versions')
            ->where('pack_id', $packId)
            ->where('content_package_version', $contentPackageVersion);

        if ($region !== '') {
            $query->where('region', $region);
        }
        if ($locale !== '') {
            $query->where('locale', $locale);
        }

        $row = $query->orderByDesc('created_at')->first();

        return $row ? (string) $row->id : null;
    }

    private function shouldDualWriteLegacyBridge(): bool
    {
        $mode = strtolower(trim((string) config('scale_identity.content_publish_mode', 'legacy')));

        return in_array($mode, ['dual', 'v2'], true);
    }

    private function shouldCatalogLegacyBlobs(): bool
    {
        return $this->shouldDualWriteLegacyBridge()
            && (bool) config('storage_rollout.blob_catalog_enabled', false);
    }

    private function shouldCatalogLegacyManifest(): bool
    {
        return $this->shouldDualWriteLegacyBridge()
            && (bool) config('storage_rollout.manifest_catalog_enabled', false);
    }

    /**
     * @return list<array{logical_path:string,hash:string,size_bytes:int,content_type:?string,role:?string}>
     */
    private function collectCompiledFilesFromRoot(string $root): array
    {
        $compiledDir = $root.DIRECTORY_SEPARATOR.'compiled';
        if (! File::isDirectory($compiledDir)) {
            return [];
        }

        $files = [];
        foreach (File::allFiles($compiledDir) as $file) {
            $absolutePath = $file->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($absolutePath, strlen(rtrim($root, '/\\')))), '/');
            $bytes = (string) File::get($absolutePath);
            $hash = hash('sha256', $bytes);
            $files[] = [
                'logical_path' => $relative,
                'hash' => $hash,
                'size_bytes' => strlen($bytes),
                'content_type' => $this->contentTypeForLogicalPath($relative),
                'role' => $relative === 'compiled/manifest.json' ? 'manifest' : null,
            ];
        }

        usort($files, static fn (array $a, array $b): int => strcmp((string) $a['logical_path'], (string) $b['logical_path']));

        return $files;
    }

    private function contentTypeForLogicalPath(string $logicalPath): ?string
    {
        return str_ends_with(strtolower($logicalPath), '.json') ? 'application/json' : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeManifestJson(mixed $manifestJson): ?array
    {
        if (! is_string($manifestJson) || trim($manifestJson) === '') {
            return null;
        }

        $decoded = json_decode($manifestJson, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function relativeToPrivate(string $path): string
    {
        $privateRoot = $this->privateRoot();
        $pathNorm = str_replace(DIRECTORY_SEPARATOR, '/', $path);
        $rootNorm = str_replace(DIRECTORY_SEPARATOR, '/', $privateRoot);

        if ($rootNorm !== '' && str_starts_with($pathNorm, $rootNorm.'/')) {
            return substr($pathNorm, strlen($rootNorm) + 1);
        }

        return ltrim($pathNorm, '/');
    }

    private function absoluteFromPrivate(string $relPath): string
    {
        $relPath = trim($relPath);
        if ($relPath === '') {
            return '';
        }

        return $this->privateRoot().DIRECTORY_SEPARATOR.ltrim($relPath, '/\\');
    }

    private function trimOrEmpty($value): string
    {
        if (! is_string($value)) {
            $value = (string) $value;
        }

        return trim($value);
    }

    private function trimOrDefault($value, string $default): string
    {
        $value = $this->trimOrEmpty($value);

        return $value === '' ? $default : $value;
    }
}
