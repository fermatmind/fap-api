<?php

declare(strict_types=1);

namespace App\Services\Legacy;

use App\Services\Legacy\Mbti\Attempt\LegacyMbtiAttemptLifecycleService;
use App\Services\Legacy\Mbti\Content\LegacyMbtiPackRepository;
use App\Services\Legacy\Mbti\Report\LegacyMbtiReportPayloadBuilder;
use App\Support\CacheKeys;
use App\Support\OrgContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class LegacyMbtiAttemptService
{
    private const HOT_CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly OrgContext $orgContext,
        private readonly LegacyMbtiPackRepository $packRepo,
        private readonly LegacyMbtiReportPayloadBuilder $reportBuilder,
        private readonly LegacyMbtiAttemptLifecycleService $attemptLifecycle,
    ) {
    }

    private function defaultRegion(): string
    {
        return (string) config('content_packs.default_region', 'CN_MAINLAND');
    }

    private function defaultLocale(): string
    {
        return (string) config('content_packs.default_locale', 'zh-CN');
    }

    private function defaultDirVersion(): string
    {
        return (string) config(
            'content_packs.default_dir_version',
            config('content.default_versions.default', 'MBTI-CN-v0.2.1-TEST')
        );
    }

    private function hotCacheStore()
    {
        try {
            return Cache::store('hot_redis');
        } catch (\Throwable $e) {
            Log::warning('LEGACY_MBTI_HOT_CACHE_STORE_FALLBACK', [
                'store' => 'hot_redis',
                'request_id' => $this->requestId(),
                'exception' => $e,
            ]);

            return Cache::store();
        }
    }

    private function shouldLogHotCache(): bool
    {
        return (bool) config('app.debug') || (bool) env('FAP_CACHE_LOG', true);
    }

    private function logHotCacheQuestions(string $packId, string $dirVersion, bool $hit, float $startedAt): void
    {
        if (!$this->shouldLogHotCache()) {
            return;
        }

        $ms = (int) round((microtime(true) - $startedAt) * 1000);
        $flagHit = $hit ? 1 : 0;
        $flagMiss = $hit ? 0 : 1;

        Log::info("[HOTCACHE] kind=mbti_questions pack_id={$packId} dir={$dirVersion} ms={$ms} hit={$flagHit} miss={$flagMiss}");
    }

    /**
     * 健康检查：确认 API 服务在线
     */
    public function health()
    {
        return response()->json([
            'ok' => true,
            'service' => 'Fermat Assessment Platform API',
            'version' => 'v0.2-skeleton',
            'time' => now()->toIso8601String(),
        ]);
    }

    /**
     * 返回 MBTI 量表元信息
     */
    public function scaleMeta()
    {
        return response()->json([
            'scale_code' => 'MBTI',
            'title' => 'MBTI v2.5 · FermatMind',
            'question_count' => 144,
            'region' => 'CN_MAINLAND',
            'locale' => 'zh-CN',
            'version' => 'v0.2',
            'price_tier' => 'FREE',
        ]);
    }

    /**
     * GET /api/v0.2/scales/MBTI/questions
     * ✅ 读 content_packages/<pkg>/questions.json
     * ✅ 对外脱敏：不返回 score / key_pole / direction / irt / is_active
     */
    public function questions()
    {
        $startedAt = microtime(true);
        $region = (string) (request()->header('X-Region') ?: request()->input('region') ?: $this->defaultRegion());
        $locale = (string) (request()->header('X-Locale') ?: request()->input('locale') ?: $this->defaultLocale());
        $dirVersion = $this->defaultDirVersion();

        $contentDir = $this->packRepo->resolveContentDir(null, $dirVersion, $region, $locale);
        $manifest = $this->packRepo->loadManifestDoc($contentDir) ?? [];

        $packId = $manifest['pack_id'] ?? config('content_packs.default_pack_id');
        $contentPackageVersion = $manifest['content_package_version'] ?? $dirVersion;

        $packId = is_string($packId) ? trim($packId) : '';
        $contentPackageVersion = is_string($contentPackageVersion) ? $contentPackageVersion : $dirVersion;

        $cacheKey = CacheKeys::mbtiQuestions($packId, $dirVersion);
        $cache = $this->hotCacheStore();
        try {
            $cachedPayload = $cache->get($cacheKey);
        } catch (\Throwable $e) {
            Log::warning('LEGACY_MBTI_CACHE_READ_FAILED', [
                'key' => $cacheKey,
                'store' => 'hot_redis',
                'request_id' => $this->requestId(),
                'exception' => $e,
            ]);
            try {
                $cache = Cache::store();
                $cachedPayload = $cache->get($cacheKey);
            } catch (\Throwable $e2) {
                Log::warning('LEGACY_MBTI_CACHE_READ_DEGRADED', [
                    'key' => $cacheKey,
                    'store' => (string) config('cache.default', 'default'),
                    'request_id' => $this->requestId(),
                    'exception' => $e2,
                ]);
                $cachedPayload = null;
            }
        }

        if (is_array($cachedPayload)) {
            $this->logHotCacheQuestions($packId, $dirVersion, true, $startedAt);
            return response()->json($cachedPayload);
        }

        $json = $this->packRepo->loadQuestionsDoc($contentDir);
        if (!is_array($json)) {
            return response()->json([
                'ok' => false,
                'error' => 'questions.json not found',
                'path' => "(not found in package: {$contentDir})",
            ], 500);
        }

        $items = isset($json['items']) ? $json['items'] : $json;
        if (!is_array($items)) {
            return response()->json([
                'ok' => false,
                'error' => 'questions.json items invalid',
            ], 500);
        }

        $items = array_values(array_filter($items, fn ($q) => ($q['is_active'] ?? true) === true));
        usort($items, fn ($a, $b) => ($a['order'] ?? 0) <=> ($b['order'] ?? 0));

        if (count($items) !== 144) {
            return response()->json([
                'ok' => false,
                'error' => 'MBTI questions must be 144',
                'count' => count($items),
            ], 500);
        }

        $safe = array_map(function ($q) {
            $opts = array_map(function ($o) {
                return [
                    'code' => $o['code'],
                    'text' => $o['text'],
                ];
            }, $q['options'] ?? []);

            return [
                'question_id' => $q['question_id'] ?? null,
                'order' => $q['order'] ?? null,
                'dimension' => $q['dimension'] ?? null,
                'text' => $q['text'] ?? null,
                'options' => $opts,
            ];
        }, $items);

        $payload = [
            'ok' => true,
            'scale_code' => 'MBTI',
            'version' => 'v0.2',
            'region' => $region,
            'locale' => $locale,
            'pack_id' => $packId,
            'dir_version' => $dirVersion,
            'content_package_version' => $contentPackageVersion,
            'items' => $safe,
        ];

        try {
            $cache->put($cacheKey, $payload, self::HOT_CACHE_TTL_SECONDS);
        } catch (\Throwable $e) {
            Log::warning('LEGACY_MBTI_CACHE_WRITE_FAILED', [
                'key' => $cacheKey,
                'store' => 'hot_redis',
                'request_id' => $this->requestId(),
                'exception' => $e,
            ]);
            try {
                Cache::store()->put($cacheKey, $payload, self::HOT_CACHE_TTL_SECONDS);
            } catch (\Throwable $e2) {
                Log::warning('LEGACY_MBTI_CACHE_WRITE_DEGRADED', [
                    'key' => $cacheKey,
                    'store' => (string) config('cache.default', 'default'),
                    'request_id' => $this->requestId(),
                    'exception' => $e2,
                ]);
            }
        }

        $this->logHotCacheQuestions($packId, $dirVersion, false, $startedAt);

        return response()->json($payload);
    }

    public function startAttempt(Request $request, ?string $id = null)
    {
        return $this->attemptLifecycle->startAttempt($request, $id);
    }

    public function storeAttempt(Request $request)
    {
        return $this->attemptLifecycle->storeAttempt($request);
    }

    public function upsertResult(Request $request, string $attemptId)
    {
        return $this->attemptLifecycle->upsertResult($request, $attemptId);
    }

    private function requestId(): string
    {
        $request = request();
        if (!$request instanceof Request) {
            return '';
        }

        $requestId = trim((string) ($request->attributes->get('request_id') ?? ''));
        if ($requestId !== '') {
            return $requestId;
        }

        $requestId = trim((string) $request->header('X-Request-Id', ''));
        if ($requestId !== '') {
            return $requestId;
        }

        return trim((string) $request->header('X-Request-ID', ''));
    }
}
