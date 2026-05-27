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
        ?string $assetsBaseUrlOverride = null,
        ?string $locale = null
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
        $mapped = $this->projectDisplayLocale($mapped, $manifestPath, $locale);

        return [
            'ok' => true,
            'questions' => $mapped,
            'content_package_version' => $contentPackageVersion,
            'manifest' => $manifestData,
        ];
    }

    /**
     * @param  array<string, mixed>  $questionsDoc
     * @return array<string, mixed>
     */
    private function projectDisplayLocale(array $questionsDoc, string $manifestPath, ?string $locale): array
    {
        if ($this->normalizeLocale($locale) !== 'en') {
            return $questionsDoc;
        }

        $i18nPath = dirname($manifestPath).DIRECTORY_SEPARATOR.'questions_i18n.en.json';
        if (! File::exists($i18nPath) || ! File::isFile($i18nPath)) {
            return $questionsDoc;
        }

        $i18n = $this->readJsonFile($i18nPath);
        if (! ($i18n['ok'] ?? false) || ! is_array($i18n['data'] ?? null)) {
            return $questionsDoc;
        }

        $data = $i18n['data'];
        $items = is_array($data['items'] ?? null) ? $data['items'] : [];
        $optionText = is_array($data['option_text'] ?? null) ? $data['option_text'] : [];
        if ($items === []) {
            return $questionsDoc;
        }

        foreach (['items', 'questions', 'data'] as $key) {
            if (isset($questionsDoc[$key]) && is_array($questionsDoc[$key])) {
                $questionsDoc[$key] = $this->projectQuestionListToEnglish($questionsDoc[$key], $items, $optionText);

                return $questionsDoc;
            }
        }

        if ($this->isList($questionsDoc)) {
            return $this->projectQuestionListToEnglish($questionsDoc, $items, $optionText);
        }

        return $questionsDoc;
    }

    /**
     * @param  array<int|string, mixed>  $questions
     * @param  array<string, mixed>  $items
     * @param  array<string, mixed>  $optionText
     * @return array<int|string, mixed>
     */
    private function projectQuestionListToEnglish(array $questions, array $items, array $optionText): array
    {
        foreach ($questions as $index => $question) {
            if (! is_array($question)) {
                continue;
            }

            $questionId = trim((string) ($question['question_id'] ?? $question['id'] ?? ''));
            $textEn = trim((string) ($items[$questionId] ?? ''));
            if ($questionId !== '' && $textEn !== '') {
                $question['text_zh'] = (string) ($question['text_zh'] ?? $question['text'] ?? '');
                $question['text_en'] = $textEn;
                $question['text'] = $textEn;
            }

            if (isset($question['options']) && is_array($question['options'])) {
                foreach ($question['options'] as $optionIndex => $option) {
                    if (! is_array($option)) {
                        continue;
                    }

                    $code = trim((string) ($option['code'] ?? ''));
                    $optionEn = trim((string) ($optionText[$code] ?? ''));
                    if ($code !== '' && $optionEn !== '') {
                        $option['text_zh'] = (string) ($option['text_zh'] ?? $option['text'] ?? '');
                        $option['text_en'] = $optionEn;
                        $option['text'] = $optionEn;
                    }
                    $question['options'][$optionIndex] = $option;
                }
            }

            $questions[$index] = $question;
        }

        return $this->isList($questions) ? array_values($questions) : $questions;
    }

    private function normalizeLocale(?string $locale): string
    {
        return str_starts_with(strtolower(trim((string) $locale)), 'zh') ? 'zh-CN' : 'en';
    }

    private function isList(array $items): bool
    {
        return $items === [] || array_keys($items) === range(0, count($items) - 1);
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
