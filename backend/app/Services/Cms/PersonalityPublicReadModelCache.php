<?php

declare(strict_types=1);

namespace App\Services\Cms;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PersonalityPublicReadModelCache
{
    public const CACHE_KEY_PREFIX = 'public:personality:read-model:v2';

    public const TTL_SECONDS = 600;

    public const LKG_TTL_SECONDS = 604800;

    public function versionToken(string $type, string $locale, int $orgId, string $scaleCode): string
    {
        if (! $this->isCacheable('detail', $type, $locale, $orgId, $scaleCode)) {
            return 'bypass';
        }

        try {
            $token = Cache::get($this->generationKey($type, $locale));

            return is_string($token) && $token !== '' ? $token : '0';
        } catch (Throwable $throwable) {
            $this->recordState('generation', $locale, 'bypass', $throwable);

            return '0';
        }
    }

    /**
     * @return array{state:'fresh'|'miss'|'bypass',payload:array<string,mixed>|null}
     */
    public function read(string $surface, string $type, string $locale, int $orgId, string $scaleCode, string $version): array
    {
        if (! $this->isCacheable($surface, $type, $locale, $orgId, $scaleCode)) {
            $this->recordState($surface, $locale, 'bypass');

            return ['state' => 'bypass', 'payload' => null];
        }

        try {
            $payload = Cache::get($this->key($surface, $type, $locale, $version));
        } catch (Throwable $throwable) {
            $this->recordState($surface, $locale, 'bypass', $throwable);

            return ['state' => 'bypass', 'payload' => null];
        }

        if (! is_array($payload)) {
            $this->recordState($surface, $locale, 'miss');

            return ['state' => 'miss', 'payload' => null];
        }

        try {
            Cache::put($this->activeKey($surface, $type, $locale), $version, self::TTL_SECONDS);
            if (! is_string(Cache::get($this->lkgKey($surface, $type, $locale)))) {
                Cache::put($this->lkgKey($surface, $type, $locale), $version, self::LKG_TTL_SECONDS);
            }
        } catch (Throwable $throwable) {
            $this->recordState($surface, $locale, 'fresh', $throwable);

            return ['state' => 'fresh', 'payload' => $payload];
        }

        $this->recordState($surface, $locale, 'fresh');

        return ['state' => 'fresh', 'payload' => $payload];
    }

    /**
     * @return array{state:'stale'|'miss'|'bypass',payload:array<string,mixed>|null}
     */
    public function stale(string $surface, string $type, string $locale, int $orgId, string $scaleCode): array
    {
        if (! $this->isCacheable($surface, $type, $locale, $orgId, $scaleCode)) {
            $this->recordState($surface, $locale, 'bypass');

            return ['state' => 'bypass', 'payload' => null];
        }

        try {
            foreach ([$this->activeKey($surface, $type, $locale), $this->lkgKey($surface, $type, $locale)] as $pointerKey) {
                $version = Cache::get($pointerKey);
                $payload = is_string($version) && $version !== ''
                    ? Cache::get($this->key($surface, $type, $locale, $version))
                    : null;
                if (is_array($payload)) {
                    $this->recordState($surface, $locale, 'stale');

                    return ['state' => 'stale', 'payload' => $payload];
                }
            }
        } catch (Throwable $throwable) {
            $this->recordState($surface, $locale, 'miss', $throwable);

            return ['state' => 'miss', 'payload' => null];
        }

        $this->recordState($surface, $locale, 'miss');

        return ['state' => 'miss', 'payload' => null];
    }

    /**
     * Compatibility reader for existing cache consumers.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $surface, string $type, string $locale, int $orgId, string $scaleCode, string $version): ?array
    {
        return $this->read($surface, $type, $locale, $orgId, $scaleCode, $version)['payload'];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function put(string $surface, string $type, string $locale, int $orgId, string $scaleCode, string $version, array $payload): void
    {
        if (! $this->isCacheable($surface, $type, $locale, $orgId, $scaleCode)) {
            return;
        }

        try {
            $activeKey = $this->activeKey($surface, $type, $locale);
            $lkgKey = $this->lkgKey($surface, $type, $locale);
            $previousVersion = Cache::get($activeKey);

            Cache::put($this->key($surface, $type, $locale, $version), $payload, self::LKG_TTL_SECONDS);
            if (is_string($previousVersion) && $previousVersion !== '' && $previousVersion !== $version) {
                Cache::put($lkgKey, $previousVersion, self::LKG_TTL_SECONDS);
            } elseif (! is_string(Cache::get($lkgKey))) {
                Cache::put($lkgKey, $version, self::LKG_TTL_SECONDS);
            }
            Cache::put($activeKey, $version, self::TTL_SECONDS);
        } catch (Throwable $throwable) {
            $this->recordState($surface, $locale, 'bypass', $throwable);
        }
    }

    public function forgetType(string $type, string $locale, int $orgId, string $scaleCode): bool
    {
        if (! $this->isCacheable('detail', $type, $locale, $orgId, $scaleCode)) {
            return false;
        }

        try {
            Cache::forever(
                $this->generationKey($type, $locale),
                hash('xxh3', microtime(true).':'.random_int(PHP_INT_MIN, PHP_INT_MAX)),
            );
        } catch (Throwable $throwable) {
            $this->recordState('generation', $locale, 'bypass', $throwable);

            return false;
        }

        foreach (['detail', 'seo'] as $surface) {
            try {
                Cache::forget($this->activeKey($surface, $type, $locale));
                Cache::forget($this->lkgKey($surface, $type, $locale));
            } catch (Throwable $throwable) {
                $this->recordState($surface, $locale, 'bypass', $throwable);

                return false;
            }
        }

        return true;
    }

    public function key(string $surface, string $type, string $locale, string $version): string
    {
        return implode(':', [
            self::CACHE_KEY_PREFIX,
            strtolower($surface),
            strtolower($locale),
            strtolower($type),
            'versions',
            hash('xxh3', $version),
        ]);
    }

    public function activeKey(string $surface, string $type, string $locale): string
    {
        return implode(':', [
            self::CACHE_KEY_PREFIX,
            strtolower($surface),
            strtolower($locale),
            strtolower($type),
            'active',
        ]);
    }

    public function lkgKey(string $surface, string $type, string $locale): string
    {
        return implode(':', [
            self::CACHE_KEY_PREFIX,
            strtolower($surface),
            strtolower($locale),
            strtolower($type),
            'lkg',
        ]);
    }

    public function generationKey(string $type, string $locale): string
    {
        return implode(':', [
            self::CACHE_KEY_PREFIX,
            strtolower($locale),
            strtolower($type),
            'generation',
        ]);
    }

    private function isCacheable(string $surface, string $type, string $locale, int $orgId, string $scaleCode): bool
    {
        return $orgId === 0
            && strtoupper($scaleCode) === 'MBTI'
            && in_array($surface, ['detail', 'seo'], true)
            && in_array($locale, ['en', 'zh-CN'], true)
            && preg_match('/^[EI][SN][TF][JP](?:-[AT])?$/', strtoupper($type)) === 1;
    }

    private function recordState(string $surface, string $locale, string $state, ?Throwable $throwable = null): void
    {
        try {
            Log::info('personality_public_read_model_cache', array_filter([
                'surface' => strtolower($surface),
                'locale' => $locale,
                'cache_state' => $state,
                'error_class' => $throwable !== null ? $throwable::class : null,
            ]));
        } catch (Throwable) {
            return;
        }
    }
}
