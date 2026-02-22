<?php

declare(strict_types=1);

namespace App\Services\Content\Publisher;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class ContentPackV2Publisher
{
    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function publishCompiled(string $packId, string $packVersion, array $options = []): array
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        if ($packId === '' || $packVersion === '') {
            throw new RuntimeException('PACK_ID_OR_VERSION_REQUIRED');
        }

        $sourceCompiledDir = base_path('content_packs/'.$packId.'/'.$packVersion.'/compiled');
        if (! File::isDirectory($sourceCompiledDir)) {
            throw new RuntimeException('COMPILED_DIR_NOT_FOUND: '.$sourceCompiledDir);
        }

        $manifestPath = $sourceCompiledDir.'/manifest.json';
        if (! is_file($manifestPath)) {
            throw new RuntimeException('MANIFEST_NOT_FOUND: '.$manifestPath);
        }

        $manifestRaw = (string) File::get($manifestPath);
        $manifest = json_decode($manifestRaw, true);
        if (! is_array($manifest)) {
            throw new RuntimeException('MANIFEST_INVALID_JSON');
        }

        $manifestHash = strtolower(trim((string) ($manifest['compiled_hash'] ?? '')));
        if ($manifestHash === '') {
            $manifestHash = strtolower(trim((string) ($manifest['content_hash'] ?? '')));
        }
        if ($manifestHash === '') {
            $manifestHash = hash('sha256', $manifestRaw);
        }

        $compiledHash = strtolower(trim((string) ($manifest['compiled_hash'] ?? $manifestHash)));
        $contentHash = strtolower(trim((string) ($manifest['content_hash'] ?? '')));
        $normsVersion = trim((string) ($manifest['norms_version'] ?? data_get($manifest, 'norms.norms_version', '')));

        $releaseId = (string) Str::uuid();
        $pathHash = $manifestHash !== '' ? $manifestHash : $releaseId;
        $storagePath = 'private/packs_v2/'.$packId.'/'.$packVersion.'/'.$pathHash;
        $targetRoot = storage_path('app/'.$storagePath);

        if (File::isDirectory($targetRoot)) {
            File::deleteDirectory($targetRoot);
        }

        File::ensureDirectoryExists(dirname($targetRoot));
        if (! File::copyDirectory($sourceCompiledDir, $targetRoot)) {
            throw new RuntimeException('COPY_COMPILED_FAILED');
        }

        $sourceCommit = trim((string) ($options['source_commit'] ?? ''));
        $createdBy = trim((string) ($options['created_by'] ?? 'packs2'));
        if ($createdBy === '') {
            $createdBy = 'packs2';
        }

        $now = now();
        $release = [
            'id' => $releaseId,
            'action' => 'packs2_publish',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => $packVersion,
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => null,
            'to_pack_id' => $packId,
            'status' => 'success',
            'message' => 'published via packs2',
            'created_by' => $createdBy,
            'probe_ok' => null,
            'probe_json' => null,
            'probe_run_at' => null,
            'manifest_hash' => $manifestHash,
            'compiled_hash' => $compiledHash,
            'content_hash' => $contentHash !== '' ? $contentHash : null,
            'norms_version' => $normsVersion !== '' ? $normsVersion : null,
            'git_sha' => $sourceCommit !== '' ? $sourceCommit : null,
            'pack_version' => $packVersion,
            'manifest_json' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'storage_path' => $storagePath,
            'source_commit' => $sourceCommit !== '' ? $sourceCommit : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('content_pack_releases')->insert($release);

        return $release;
    }

    public function activateRelease(string $releaseId): void
    {
        $releaseId = trim($releaseId);
        if ($releaseId === '') {
            throw new RuntimeException('RELEASE_ID_REQUIRED');
        }

        $release = DB::table('content_pack_releases')->where('id', $releaseId)->first();
        if (! $release) {
            throw new RuntimeException('RELEASE_NOT_FOUND');
        }

        if (! $this->releaseHasCompiledPayload($release)) {
            throw new RuntimeException('RELEASE_COMPILED_PAYLOAD_MISSING');
        }

        $packId = strtoupper(trim((string) ($release->to_pack_id ?? '')));
        $packVersion = trim((string) ($release->pack_version ?? $release->dir_alias ?? ''));
        if ($packId === '' || $packVersion === '') {
            throw new RuntimeException('RELEASE_PACK_CONTEXT_INVALID');
        }

        $now = now();
        DB::table('content_pack_activations')->updateOrInsert(
            [
                'pack_id' => $packId,
                'pack_version' => $packVersion,
            ],
            [
                'release_id' => $releaseId,
                'activated_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    public function rollbackToRelease(string $packId, string $packVersion, string $toReleaseId): void
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        $toReleaseId = trim($toReleaseId);

        if ($packId === '' || $packVersion === '' || $toReleaseId === '') {
            throw new RuntimeException('ROLLBACK_ARGUMENTS_REQUIRED');
        }

        $release = DB::table('content_pack_releases')->where('id', $toReleaseId)->first();
        if (! $release) {
            throw new RuntimeException('RELEASE_NOT_FOUND');
        }

        $releasePackId = strtoupper(trim((string) ($release->to_pack_id ?? '')));
        $releasePackVersion = trim((string) ($release->pack_version ?? $release->dir_alias ?? ''));

        if ($releasePackId !== $packId || $releasePackVersion !== $packVersion) {
            throw new RuntimeException('ROLLBACK_TARGET_MISMATCH');
        }

        $this->activateRelease($toReleaseId);

        DB::table('content_pack_releases')->insert([
            'id' => (string) Str::uuid(),
            'action' => 'packs2_rollback',
            'region' => 'GLOBAL',
            'locale' => 'global',
            'dir_alias' => $packVersion,
            'from_version_id' => null,
            'to_version_id' => null,
            'from_pack_id' => $packId,
            'to_pack_id' => $packId,
            'status' => 'success',
            'message' => 'rollback to release '.$toReleaseId,
            'created_by' => 'packs2',
            'manifest_hash' => (string) ($release->manifest_hash ?? ''),
            'compiled_hash' => (string) ($release->compiled_hash ?? ''),
            'content_hash' => (string) ($release->content_hash ?? ''),
            'norms_version' => (string) ($release->norms_version ?? ''),
            'git_sha' => (string) ($release->git_sha ?? ''),
            'pack_version' => $packVersion,
            'manifest_json' => (string) ($release->manifest_json ?? ''),
            'storage_path' => (string) ($release->storage_path ?? ''),
            'source_commit' => (string) ($release->source_commit ?? ''),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listReleases(string $packId, string $packVersion, int $limit = 20): array
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        if ($packId === '' || $packVersion === '') {
            return [];
        }

        $activeReleaseId = trim((string) DB::table('content_pack_activations')
            ->where('pack_id', $packId)
            ->where('pack_version', $packVersion)
            ->value('release_id'));

        $rows = DB::table('content_pack_releases')
            ->where('to_pack_id', $packId)
            ->where(function ($q) use ($packVersion): void {
                $q->where('pack_version', $packVersion)
                    ->orWhere(function ($q2) use ($packVersion): void {
                        $q2->whereNull('pack_version')->where('dir_alias', $packVersion);
                    });
            })
            ->whereIn('action', ['packs2_publish', 'packs2_rollback'])
            ->orderByDesc('created_at')
            ->limit(max(1, min($limit, 100)))
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'release_id' => (string) ($row->id ?? ''),
                'action' => (string) ($row->action ?? ''),
                'pack_id' => (string) ($row->to_pack_id ?? ''),
                'pack_version' => (string) ($row->pack_version ?? $row->dir_alias ?? ''),
                'manifest_hash' => (string) ($row->manifest_hash ?? ''),
                'storage_path' => (string) ($row->storage_path ?? ''),
                'source_commit' => (string) ($row->source_commit ?? $row->git_sha ?? ''),
                'created_at' => (string) ($row->created_at ?? ''),
                'is_active' => $activeReleaseId !== '' && (string) ($row->id ?? '') === $activeReleaseId,
            ];
        }

        return $items;
    }

    public function resolveLatestReleaseId(string $packId, string $packVersion): ?string
    {
        $packId = strtoupper(trim($packId));
        $packVersion = trim($packVersion);
        if ($packId === '' || $packVersion === '') {
            return null;
        }

        $row = DB::table('content_pack_releases')
            ->where('to_pack_id', $packId)
            ->where(function ($q) use ($packVersion): void {
                $q->where('pack_version', $packVersion)
                    ->orWhere(function ($q2) use ($packVersion): void {
                        $q2->whereNull('pack_version')->where('dir_alias', $packVersion);
                    });
            })
            ->where('action', 'packs2_publish')
            ->where('status', 'success')
            ->orderByDesc('created_at')
            ->first();

        if (! $row) {
            return null;
        }

        return (string) ($row->id ?? '');
    }

    private function releaseHasCompiledPayload(object $release): bool
    {
        $storagePath = trim((string) ($release->storage_path ?? ''));
        if ($storagePath === '') {
            return false;
        }

        $root = storage_path('app/'.ltrim(str_starts_with($storagePath, 'app/') ? substr($storagePath, 4) : $storagePath, '/'));

        return is_file($root.'/compiled/manifest.json') || is_file($root.'/manifest.json');
    }
}
