<?php

namespace App\Services\Content;

use App\Support\CacheKeys;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

final class QuestionsService
{
    private const CACHE_TTL_SECONDS = 300;

    public function __construct(
        private ContentPacksIndex $index,
        private AssetsMapper $assetsMapper
    ) {}

    public function loadByPack(
        string $packId,
        string $dirVersion,
        ?string $assetsBaseUrlOverride = null
    ): array {
        $found = $this->index->find($packId, $dirVersion);
        if (! ($found['ok'] ?? false)) {
            return [
                'ok' => false,
                'error' => 'NOT_FOUND',
                'message' => 'pack not found',
            ];
        }

        $item = $found['item'] ?? [];
        $questionsPath = (string) ($item['questions_path'] ?? '');
        $manifestPath = (string) ($item['manifest_path'] ?? '');
        $cacheKey = $this->questionsCacheKey($packId, $dirVersion, $item);

        $questionsDoc = null;
        $manifestData = [];
        $contentPackageVersion = '';

        $cached = $this->readFromCache($cacheKey);
        if (is_array($cached)) {
            $questionsDoc = is_array($cached['questions_doc'] ?? null) ? $cached['questions_doc'] : null;
            $manifestData = is_array($cached['manifest'] ?? null) ? $cached['manifest'] : [];
            $contentPackageVersion = (string) ($cached['content_package_version'] ?? '');
        }

        if (! is_array($questionsDoc)) {
            $questions = $this->readJsonFile($questionsPath);
            if (! ($questions['ok'] ?? false)) {
                return $questions;
            }

            $manifest = $this->readJsonFile($manifestPath);
            $manifestData = ($manifest['ok'] ?? false) ? $manifest['data'] : [];
            $contentPackageVersion = (string) ($manifestData['content_package_version'] ?? ($item['content_package_version'] ?? ''));
            $questionsDoc = $questions['data'];

            $this->writeToCache($cacheKey, [
                'questions_doc' => $questionsDoc,
                'manifest' => $manifestData,
                'content_package_version' => $contentPackageVersion,
            ]);
        }

        if ($contentPackageVersion === '') {
            $contentPackageVersion = (string) ($manifestData['content_package_version'] ?? ($item['content_package_version'] ?? ''));
        }

        $assetsBaseUrl = is_string($assetsBaseUrlOverride) ? trim($assetsBaseUrlOverride) : null;
        if ($assetsBaseUrl === '') {
            $assetsBaseUrl = null;
        }

        $mapped = $this->assetsMapper->mapQuestionsDoc(
            $questionsDoc,
            $packId,
            $dirVersion,
            $assetsBaseUrl
        );

        return [
            'ok' => true,
            'questions' => $mapped,
            'content_package_version' => $contentPackageVersion,
            'manifest' => $manifestData,
        ];
    }

    private function readJsonFile(string $path): array
    {
        if ($path === '' || ! File::exists($path) || ! File::isFile($path)) {
            return [
                'ok' => false,
                'error' => 'READ_FAILED',
                'message' => $path === '' ? 'missing file path' : "file not found: {$path}",
            ];
        }

        try {
            $raw = File::get($path);
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'READ_FAILED',
                'message' => "failed to read: {$path}",
            ];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [
                'ok' => false,
                'error' => 'INVALID_JSON',
                'message' => "invalid json: {$path}",
            ];
        }

        return [
            'ok' => true,
            'data' => $decoded,
        ];
    }

    private function questionsCacheKey(string $packId, string $dirVersion, array $item): string
    {
        $manifestMtime = (int) ($item['manifest_mtime'] ?? 0);
        $manifestSize = (int) ($item['manifest_size'] ?? 0);
        $versionMtime = (int) ($item['version_mtime'] ?? 0);
        $versionSize = (int) ($item['version_size'] ?? 0);
        $questionsMtime = (int) ($item['questions_mtime'] ?? 0);
        $questionsSize = (int) ($item['questions_size'] ?? 0);
        $contentPackageVersion = trim((string) ($item['content_package_version'] ?? ''));

        return CacheKeys::packQuestions($packId, $dirVersion)
            .':mm='.$manifestMtime
            .':ms='.$manifestSize
            .':vm='.$versionMtime
            .':vs='.$versionSize
            .':qm='.$questionsMtime
            .':qs='.$questionsSize
            .':cv='.$contentPackageVersion;
    }

    private function readFromCache(string $cacheKey): ?array
    {
        try {
            $cached = $this->cacheStore()->get($cacheKey);

            return is_array($cached) ? $cached : null;
        } catch (\Throwable $e) {
            try {
                $cached = Cache::store()->get($cacheKey);

                return is_array($cached) ? $cached : null;
            } catch (\Throwable $fallback) {
                return null;
            }
        }
    }

    private function writeToCache(string $cacheKey, array $payload): void
    {
        $ttl = max(1, (int) config('content_packs.loader_cache_ttl_seconds', self::CACHE_TTL_SECONDS));

        try {
            $this->cacheStore()->put($cacheKey, $payload, $ttl);

            return;
        } catch (\Throwable $e) {
            try {
                Cache::store()->put($cacheKey, $payload, $ttl);

                return;
            } catch (\Throwable $fallback) {
                Log::warning('QUESTIONS_CACHE_WRITE_FAILED', [
                    'cache_key' => $cacheKey,
                    'ttl' => $ttl,
                    'exception' => $fallback,
                ]);
            }
        }
    }

    private function cacheStore(): CacheRepository
    {
        try {
            return Cache::store('hot_redis');
        } catch (\Throwable $e) {
            return Cache::store();
        }
    }
}
