<?php

declare(strict_types=1);

namespace App\Services\Content;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ContentPackV2Resolver
{
    private const MBTI_INACTIVE_RESULT_PACK_ID = 'MBTI.GLOBAL.EN.DEFAULT';

    private const MBTI_INACTIVE_RESULT_PACK_VERSION = 'v0.3';

    private const MBTI_INACTIVE_RESULT_DIR_ALIAS = 'MBTI-GLOBAL-en-v0.3';

    private const MBTI_INACTIVE_RESULT_RELEASE_ID = '2b6deff4-0fdf-5d7c-a86f-e3d4aa61c488';

    private const MBTI_INACTIVE_RESULT_MANIFEST_HASH = '649a61633a05728618477b97036718c582673c96a82c24d142287991b3d2d0e1';

    private const MBTI_INACTIVE_RESULT_PACKAGE_SHA256 = '9325013b870fd2496efc0882656240f91ce28ff4faaf1da42fb3dde3577b0ed3';

    private const MBTI_INACTIVE_RESULT_PHYSICAL_RELATIVE_PATH = 'default/GLOBAL/en/MBTI-GLOBAL-en-v0.3';

    private const MBTI_INACTIVE_RESULT_DRAFT_RELATIVE_PATH = 'drafts/en-parity-w1-mbti-result-content-v1.json';

    public function __construct(
        private readonly ContentPackV2Materializer $materializer,
        private readonly ContentPackV2RemoteRehydrateService $remoteRehydrate,
    ) {}

    public function resolveActiveCompiledPath(string $packId, string $packVersion): ?string
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        if ($packId === '' || $packVersion === '') {
            return null;
        }

        try {
            $activation = DB::table('content_pack_activations')
                ->where('pack_id', $packId)
                ->where('pack_version', $packVersion)
                ->first();
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'content_pack_activations')) {
                return null;
            }

            throw $e;
        }

        if (! $activation) {
            return null;
        }

        $releaseId = trim((string) ($activation->release_id ?? ''));
        if ($releaseId === '') {
            return null;
        }

        $release = DB::table('content_pack_releases')->where('id', $releaseId)->first();
        if (! $release) {
            return null;
        }

        $releasePack = strtoupper(trim((string) ($release->to_pack_id ?? '')));
        if ($releasePack !== $packId) {
            return null;
        }

        return $this->resolveCompiledPathFromRelease($release);
    }

    /** @return array<string,mixed>|null */
    public function resolveActiveMbtiResultAuthority(): ?array
    {
        try {
            $activation = DB::table('content_pack_activations')
                ->whereRaw('LOWER(pack_id) = ?', [strtolower(self::MBTI_INACTIVE_RESULT_PACK_ID)])
                ->where('pack_version', self::MBTI_INACTIVE_RESULT_PACK_VERSION)
                ->first();
            $releaseId = trim((string) ($activation->release_id ?? ''));
            if ($releaseId === '') {
                return null;
            }
            $release = DB::table('content_pack_releases')->where('id', $releaseId)->first();
        } catch (QueryException) {
            return null;
        }

        return $release === null ? null : $this->resolveDatabaseBackedMbtiResultAuthority($release);
    }

    public function resolveCompiledPathByManifestHash(string $packId, string $packVersion, string $manifestHash): ?string
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        $manifestHash = strtolower(trim($manifestHash));
        if ($packId === '' || $packVersion === '' || $manifestHash === '') {
            return null;
        }

        $query = DB::table('content_pack_releases')
            ->whereRaw('UPPER(to_pack_id) = ?', [$packId])
            ->where('manifest_hash', $manifestHash)
            ->where('status', 'success')
            ->orderByDesc('created_at');

        if ($packId === self::MBTI_INACTIVE_RESULT_PACK_ID
            && $packVersion === self::MBTI_INACTIVE_RESULT_PACK_VERSION
            && $manifestHash === self::MBTI_INACTIVE_RESULT_MANIFEST_HASH) {
            $query->where('id', self::MBTI_INACTIVE_RESULT_RELEASE_ID);
        }

        $rows = $query->get();
        foreach ($rows as $release) {
            $releaseVersion = trim((string) ($release->pack_version ?? $release->dir_alias ?? ''));
            if ($releaseVersion !== '' && $releaseVersion !== $packVersion) {
                continue;
            }

            $compiledPath = $this->resolveCompiledPathFromRelease($release);
            if ($compiledPath !== null) {
                return $compiledPath;
            }
        }

        return null;
    }

    private function resolveCompiledPathFromRelease(object $release): ?string
    {
        $storagePath = trim((string) ($release->storage_path ?? ''));
        if ($storagePath === '') {
            return null;
        }

        if (str_starts_with(str_replace('\\', '/', $storagePath), 'database/content_pack_releases/')) {
            return $this->resolveExactDatabaseBackedMbtiInactiveResultPack($release);
        }

        $roots = $this->candidateRootsFromStoragePath($storagePath);
        foreach ($roots as $root) {
            $compiledDir = is_dir($root.'/compiled') ? $root.'/compiled' : $root;
            if (is_file($compiledDir.'/manifest.json')) {
                if (! $this->shouldMaterialize()) {
                    return $compiledDir;
                }

                try {
                    return $this->materializer->materialize($release, $compiledDir);
                } catch (Throwable $e) {
                    $lastKnownGood = $this->materializer->lastKnownGoodCompiledDir(
                        strtoupper(trim((string) ($release->to_pack_id ?? ''))),
                        trim((string) ($release->pack_version ?? $release->dir_alias ?? '')),
                    );
                    Log::warning('PACKS2_RESOLVER_MATERIALIZATION_FAILED', [
                        'metric' => 'content_pack.materialization_failure',
                        'release_id' => trim((string) ($release->id ?? '')),
                        'manifest_hash' => strtolower(trim((string) ($release->manifest_hash ?? ''))),
                        'storage_path' => $storagePath,
                        'source_compiled_dir' => $compiledDir,
                        'error' => $e->getMessage(),
                        'fallback' => $lastKnownGood !== null ? 'lkg' : 'source',
                    ]);

                    return $lastKnownGood ?? $compiledDir;
                }
            }
        }

        if (! $this->shouldRemoteRehydrate()) {
            return null;
        }

        try {
            return $this->remoteRehydrate->materializeFromRemote($release, $this->remoteRehydrateDisk());
        } catch (Throwable $e) {
            $lastKnownGood = $this->materializer->lastKnownGoodCompiledDir(
                strtoupper(trim((string) ($release->to_pack_id ?? ''))),
                trim((string) ($release->pack_version ?? $release->dir_alias ?? '')),
            );
            Log::warning('PACKS2_RESOLVER_REMOTE_REHYDRATE_FAILED', [
                'metric' => 'content_pack.remote_rehydrate_failure',
                'release_id' => trim((string) ($release->id ?? '')),
                'manifest_hash' => strtolower(trim((string) ($release->manifest_hash ?? ''))),
                'storage_path' => $storagePath,
                'error' => $e->getMessage(),
                'fallback' => $lastKnownGood !== null ? 'lkg' : 'none',
            ]);

            return $lastKnownGood;
        }
    }

    private function resolveExactDatabaseBackedMbtiInactiveResultPack(object $release): ?string
    {
        $releaseId = trim((string) ($release->id ?? ''));
        $storagePath = str_replace('\\', '/', trim((string) ($release->storage_path ?? '')));
        if ($releaseId !== self::MBTI_INACTIVE_RESULT_RELEASE_ID
            || $storagePath !== 'database/content_pack_releases/'.$releaseId
            || trim((string) ($release->action ?? '')) !== 'mbti_target_authority_draft_receipt'
            || strtoupper(trim((string) ($release->to_pack_id ?? ''))) !== self::MBTI_INACTIVE_RESULT_PACK_ID
            || trim((string) ($release->pack_version ?? '')) !== self::MBTI_INACTIVE_RESULT_PACK_VERSION
            || trim((string) ($release->dir_alias ?? '')) !== self::MBTI_INACTIVE_RESULT_DIR_ALIAS
            || trim((string) ($release->region ?? '')) !== 'GLOBAL'
            || trim((string) ($release->locale ?? '')) !== 'en'
            || trim((string) ($release->status ?? '')) !== 'success'
            || strtolower(trim((string) ($release->manifest_hash ?? ''))) !== self::MBTI_INACTIVE_RESULT_MANIFEST_HASH
            || strtolower(trim((string) ($release->compiled_hash ?? ''))) !== self::MBTI_INACTIVE_RESULT_PACKAGE_SHA256) {
            return null;
        }

        try {
            $releasePayload = $this->decodeJsonObject((string) ($release->manifest_json ?? ''));
            if (! $this->isExactInactiveResultPayload($releasePayload)) {
                return null;
            }

            $releaseManifest = DB::table('content_release_manifests')
                ->where('content_pack_release_id', $releaseId)
                ->where('manifest_hash', self::MBTI_INACTIVE_RESULT_MANIFEST_HASH)
                ->first();
        } catch (QueryException) {
            return null;
        }

        if (! $releaseManifest
            || trim((string) ($releaseManifest->storage_disk ?? '')) !== 'database'
            || trim((string) ($releaseManifest->storage_path ?? '')) !== 'content_pack_releases/'.$releaseId
            || strtoupper(trim((string) ($releaseManifest->pack_id ?? ''))) !== self::MBTI_INACTIVE_RESULT_PACK_ID
            || trim((string) ($releaseManifest->pack_version ?? '')) !== self::MBTI_INACTIVE_RESULT_PACK_VERSION
            || strtolower(trim((string) ($releaseManifest->compiled_hash ?? ''))) !== self::MBTI_INACTIVE_RESULT_PACKAGE_SHA256
            || $this->hasActivePointer()) {
            return null;
        }

        $releaseManifestPayload = $this->decodeJsonObject((string) ($releaseManifest->payload_json ?? ''));
        if (! $this->isExactInactiveResultPayload($releaseManifestPayload)) {
            return null;
        }

        $packRoot = rtrim((string) config('content_packs.root'), '/\\').'/'.self::MBTI_INACTIVE_RESULT_PHYSICAL_RELATIVE_PATH;
        if (! is_dir($packRoot) || is_link($packRoot)) {
            return null;
        }
        $manifest = $this->readJsonObject($packRoot.'/manifest.json');
        $draft = $this->readJsonObject($packRoot.'/'.self::MBTI_INACTIVE_RESULT_DRAFT_RELATIVE_PATH);
        if ($manifest === null || $draft === null || ! $this->isExactInactiveResultPhysicalManifest($manifest) || ! $this->isExactInactiveResultPayload($draft)) {
            return null;
        }

        return $packRoot;
    }

    /** @return array<string,mixed>|null */
    private function resolveDatabaseBackedMbtiResultAuthority(object $release): ?array
    {
        $releaseId = trim((string) ($release->id ?? ''));
        if ($releaseId === ''
            || str_replace('\\', '/', trim((string) ($release->storage_path ?? ''))) !== 'database/content_pack_releases/'.$releaseId
            || trim((string) ($release->action ?? '')) !== 'content_promotion_w1_mbti_results_v2'
            || strtoupper(trim((string) ($release->to_pack_id ?? ''))) !== self::MBTI_INACTIVE_RESULT_PACK_ID
            || trim((string) ($release->pack_version ?? '')) !== self::MBTI_INACTIVE_RESULT_PACK_VERSION
            || trim((string) ($release->region ?? '')) !== 'GLOBAL'
            || trim((string) ($release->locale ?? '')) !== 'en'
            || trim((string) ($release->status ?? '')) !== 'success') {
            return null;
        }
        $payload = $this->decodeJsonObject((string) ($release->manifest_json ?? ''));
        if (! $this->isPromotedMbtiResultAuthority($payload, (string) ($release->compiled_hash ?? ''))) {
            return null;
        }
        try {
            $manifest = DB::table('content_release_manifests')
                ->where('content_pack_release_id', $releaseId)
                ->where('manifest_hash', (string) $release->manifest_hash)
                ->first();
        } catch (QueryException) {
            return null;
        }
        if ($manifest === null
            || trim((string) ($manifest->schema_version ?? '')) !== 'mbti_result_promotion.v2'
            || trim((string) ($manifest->storage_disk ?? '')) !== 'database'
            || trim((string) ($manifest->storage_path ?? '')) !== 'content_pack_releases/'.$releaseId
            || strtoupper(trim((string) ($manifest->pack_id ?? ''))) !== self::MBTI_INACTIVE_RESULT_PACK_ID
            || trim((string) ($manifest->pack_version ?? '')) !== self::MBTI_INACTIVE_RESULT_PACK_VERSION
            || strtolower(trim((string) ($manifest->compiled_hash ?? ''))) !== strtolower(trim((string) ($release->compiled_hash ?? '')))) {
            return null;
        }
        $manifestPayload = $this->decodeJsonObject((string) ($manifest->payload_json ?? ''));
        if ($manifestPayload === null || json_encode($payload) !== json_encode($manifestPayload)) {
            return null;
        }

        return $payload;
    }

    /** @param array<string,mixed>|null $payload */
    private function isPromotedMbtiResultAuthority(?array $payload, string $compiledHash): bool
    {
        if ($payload === null || ($payload['schema_version'] ?? null) !== 'mbti_result_promotion.v2') {
            return false;
        }
        $authority = is_array($payload['authority'] ?? null) ? $payload['authority'] : [];
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];

        return strcasecmp((string) ($authority['pack_id'] ?? ''), self::MBTI_INACTIVE_RESULT_PACK_ID) === 0
            && ($authority['pack_version'] ?? null) === self::MBTI_INACTIVE_RESULT_PACK_VERSION
            && ($authority['region'] ?? null) === 'GLOBAL'
            && ($authority['locale'] ?? null) === 'en'
            && ($source['package_sha256'] ?? null) === $compiledHash
            && (int) ($counts['rows'] ?? 0) === 46
            && is_array($payload['rows'] ?? null)
            && count($payload['rows']) === 46;
    }

    private function hasActivePointer(): bool
    {
        return DB::table('content_pack_activations')
            ->whereRaw('LOWER(pack_id) = ?', [strtolower(self::MBTI_INACTIVE_RESULT_PACK_ID)])
            ->where('pack_version', self::MBTI_INACTIVE_RESULT_PACK_VERSION)
            ->exists();
    }

    /** @param array<string, mixed>|null $payload */
    private function isExactInactiveResultPayload(?array $payload): bool
    {
        if ($payload === null) {
            return false;
        }

        $authority = is_array($payload['authority'] ?? null) ? $payload['authority'] : [];
        $source = is_array($payload['source'] ?? null) ? $payload['source'] : [];
        $counts = is_array($payload['counts'] ?? null) ? $payload['counts'] : [];
        $permissions = is_array($payload['permissions'] ?? null) ? $payload['permissions'] : [];

        return ($payload['schema_version'] ?? null) === 'fermatmind.mbti.en_result_content_inactive_draft.v1'
            && strcasecmp((string) ($authority['pack_id'] ?? ''), self::MBTI_INACTIVE_RESULT_PACK_ID) === 0
            && ($authority['region'] ?? null) === 'GLOBAL'
            && ($authority['locale'] ?? null) === 'en'
            && ($authority['content_package_version'] ?? null) === self::MBTI_INACTIVE_RESULT_PACK_VERSION
            && ($authority['state'] ?? null) === 'inactive_draft'
            && ($authority['runtime_available'] ?? null) === false
            && ($authority['active_pointer_registered'] ?? null) === false
            && ($source['package_sha256'] ?? null) === self::MBTI_INACTIVE_RESULT_PACKAGE_SHA256
            && ($counts['total_rows'] ?? null) === 46
            && ($counts['authority_content_rows'] ?? null) === 21
            && ($permissions['private_payload_read'] ?? null) === false
            && ($permissions['activation'] ?? null) === false
            && ($permissions['publication'] ?? null) === false
            && ($permissions['indexability'] ?? null) === false
            && ($permissions['sitemap'] ?? null) === false
            && ($permissions['llms'] ?? null) === false
            && ($permissions['search_submission'] ?? null) === false
            && ($permissions['deployment'] ?? null) === false;
    }

    /** @param array<string, mixed> $manifest */
    private function isExactInactiveResultPhysicalManifest(array $manifest): bool
    {
        $lifecycle = is_array($manifest['lifecycle'] ?? null) ? $manifest['lifecycle'] : [];
        $capabilities = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];

        return ($manifest['schema_version'] ?? null) === 'pack-manifest@v1'
            && ($manifest['pack_type'] ?? null) === 'content_pack'
            && ($manifest['pack_id'] ?? null) === 'MBTI.global.en.default'
            && ($manifest['scale_code'] ?? null) === 'MBTI'
            && ($manifest['region'] ?? null) === 'GLOBAL'
            && ($manifest['locale'] ?? null) === 'en'
            && ($manifest['content_package_version'] ?? null) === self::MBTI_INACTIVE_RESULT_PACK_VERSION
            && ($manifest['fallback'] ?? null) === []
            && ($lifecycle['state'] ?? null) === 'inactive_draft'
            && ($lifecycle['runtime_available'] ?? null) === false
            && ($lifecycle['active_pointer_registered'] ?? null) === false
            && ($lifecycle['publication_allowed'] ?? null) === false
            && ($lifecycle['indexability_allowed'] ?? null) === false
            && ($capabilities['result_runtime'] ?? null) === false;
    }

    /** @return array<string, mixed>|null */
    private function readJsonObject(string $path): ?array
    {
        if (! is_file($path) || is_link($path)) {
            return null;
        }

        $bytes = file_get_contents($path);
        if (! is_string($bytes)) {
            return null;
        }

        return $this->decodeJsonObject($bytes);
    }

    /** @return array<string, mixed>|null */
    private function decodeJsonObject(string $bytes): ?array
    {
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function shouldRemoteRehydrate(): bool
    {
        return (bool) config('storage_rollout.packs_v2_remote_rehydrate_enabled', false);
    }

    private function remoteRehydrateDisk(): string
    {
        return trim((string) config('storage_rollout.blob_offload_disk', 's3'));
    }

    private function shouldMaterialize(): bool
    {
        return (bool) config('storage_rollout.resolver_materialization_enabled', false);
    }

    /**
     * @return list<string>
     */
    private function candidateRootsFromStoragePath(string $storagePath): array
    {
        $normalized = str_replace('\\', '/', trim($storagePath));
        if ($normalized === '') {
            return [];
        }

        $candidates = [];
        if (str_starts_with($normalized, '/')) {
            $candidates[] = rtrim($normalized, '/');
        } else {
            $relative = ltrim($normalized, '/');
            if (str_starts_with($relative, 'app/')) {
                $relative = substr($relative, 4);
            }
            $relative = ltrim($relative, '/');
            if ($relative !== '') {
                $candidates[] = rtrim(storage_path('app/'.$relative), '/');
            }

            if (str_starts_with($relative, 'private/packs_v2/')) {
                $mirror = 'content_packs_v2/'.substr($relative, strlen('private/packs_v2/'));
                $candidates[] = rtrim(storage_path('app/'.$mirror), '/');
            } elseif (str_starts_with($relative, 'content_packs_v2/')) {
                $mirror = 'private/packs_v2/'.substr($relative, strlen('content_packs_v2/'));
                $candidates[] = rtrim(storage_path('app/'.$mirror), '/');
            }
        }

        $candidates = array_values(array_unique(array_filter($candidates, static fn (string $root): bool => $root !== '')));

        return $candidates;
    }
}
