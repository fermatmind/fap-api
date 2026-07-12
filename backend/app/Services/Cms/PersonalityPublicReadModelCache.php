<?php

declare(strict_types=1);

namespace App\Services\Cms;

use Illuminate\Support\Facades\Cache;

final class PersonalityPublicReadModelCache
{
    public const CACHE_KEY_PREFIX = 'public:personality:read-model:v1';

    public const TTL_SECONDS = 600;

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $surface, string $type, string $locale, int $orgId, string $scaleCode, string $version): ?array
    {
        if (! $this->isCacheable($surface, $type, $locale, $orgId, $scaleCode)) {
            return null;
        }

        $payload = Cache::get($this->key($surface, $type, $locale, $version));

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(string $surface, string $type, string $locale, int $orgId, string $scaleCode, string $version, array $payload): void
    {
        if (! $this->isCacheable($surface, $type, $locale, $orgId, $scaleCode)) {
            return;
        }

        Cache::put($this->key($surface, $type, $locale, $version), $payload, self::TTL_SECONDS);
    }

    public function key(string $surface, string $type, string $locale, string $version): string
    {
        return implode(':', [
            self::CACHE_KEY_PREFIX,
            strtolower($surface),
            strtolower($locale),
            strtolower($type),
            hash('xxh3', $version),
        ]);
    }

    private function isCacheable(string $surface, string $type, string $locale, int $orgId, string $scaleCode): bool
    {
        return $orgId === 0
            && strtoupper($scaleCode) === 'MBTI'
            && in_array($surface, ['detail', 'seo'], true)
            && in_array($locale, ['en', 'zh-CN'], true)
            && preg_match('/^[EI][SN][TF][JP]-[AT]$/', strtoupper($type)) === 1;
    }
}
