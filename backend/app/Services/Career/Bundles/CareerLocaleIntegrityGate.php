<?php

declare(strict_types=1);

namespace App\Services\Career\Bundles;

use App\DTO\Career\CareerJobDetailBundle;
use App\Models\CareerJob;
use App\Models\CareerJobDisplayAsset;

final class CareerLocaleIntegrityGate
{
    /**
     * @param  array<string, mixed>  $pageContent
     */
    public function displaySurfaceReadyForLocale(
        CareerJobDisplayAsset $asset,
        array $pageContent,
        string $locale
    ): bool {
        if (! $this->isZhLocale($locale)) {
            return true;
        }

        if ($this->hasExplicitNotReadyFlag($asset->metadata_json) || $this->hasExplicitNotReadyFlag($pageContent)) {
            return false;
        }

        $heroTitle = $this->firstText(
            data_get($pageContent, 'hero.h1'),
            data_get($pageContent, 'hero.title'),
            data_get($pageContent, 'hero.headline')
        );
        if (! $this->isZhAuthorityText($heroTitle)) {
            return false;
        }

        foreach (['faq_block', 'sections', 'content_sections', 'definition_block', 'responsibilities_block', 'work_context_block'] as $key) {
            $strings = $this->collectStrings($pageContent[$key] ?? null);
            if ($strings !== [] && ! $this->stringsContainCjk($strings) && $this->stringsLookEnglish($strings)) {
                return false;
            }
        }

        return true;
    }

    public function bundleReadyForPublicLocale(
        CareerJobDetailBundle $bundle,
        ?array $displaySurface,
        string $locale
    ): bool {
        if (! $this->isZhLocale($locale)) {
            return true;
        }

        if ($displaySurface !== null) {
            return true;
        }

        if (! $this->isZhAuthorityText($bundle->titles['canonical_zh'] ?? null)) {
            return false;
        }

        $contentStrings = array_merge(
            $this->collectStrings($bundle->contentBodyMd),
            $this->collectStrings($bundle->contentSections)
        );

        return $contentStrings !== [] && $this->stringsContainCjk($contentStrings);
    }

    public function careerJobReadyForPublicLocale(CareerJob $job, string $locale): bool
    {
        if (! $this->isZhLocale($locale)) {
            return true;
        }

        return $this->isZhAuthorityText($job->title);
    }

    public function validZhAuthorityText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);
        if ($text === '' || ! $this->isZhAuthorityText($text)) {
            return null;
        }

        return $text;
    }

    public function isZhLocale(string $locale): bool
    {
        return in_array(strtolower(trim($locale)), ['zh', 'zh-cn', 'zh_cn'], true);
    }

    private function isZhAuthorityText(mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        $text = trim((string) $value);
        if ($text === '' || ! $this->containsCjk($text)) {
            return false;
        }

        return ! $this->looksEnglish($text);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function hasExplicitNotReadyFlag(?array $payload): bool
    {
        if (! is_array($payload)) {
            return false;
        }

        foreach ([
            'locale_readiness.zh-CN',
            'locale_readiness.zh',
            'locales.zh-CN',
            'locales.zh',
            'release_gates.zh-CN',
            'release_gates.zh',
        ] as $path) {
            $value = data_get($payload, $path);
            if ($this->flagMeansNotReady($value)) {
                return true;
            }
        }

        return false;
    }

    private function flagMeansNotReady(mixed $value): bool
    {
        if ($value === false) {
            return true;
        }

        if (is_array($value)) {
            foreach (['ready', 'is_ready', 'public_ready', 'release_ready'] as $key) {
                if (($value[$key] ?? null) === false) {
                    return true;
                }
            }

            $status = strtolower(trim((string) ($value['status'] ?? $value['state'] ?? '')));

            return in_array($status, ['not_ready', 'locale_not_ready', 'held', 'hold', 'blocked', 'unavailable'], true);
        }

        if (is_string($value)) {
            return in_array(strtolower(trim($value)), ['not_ready', 'locale_not_ready', 'held', 'hold', 'blocked', 'unavailable'], true);
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function collectStrings(mixed $value): array
    {
        if (is_scalar($value)) {
            $text = trim((string) $value);

            return $text === '' ? [] : [$text];
        }

        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        array_walk_recursive($value, static function (mixed $item) use (&$strings): void {
            if (! is_scalar($item)) {
                return;
            }

            $text = trim((string) $item);
            if ($text !== '') {
                $strings[] = $text;
            }
        });

        return $strings;
    }

    private function firstText(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $text = trim((string) $value);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $strings
     */
    private function stringsContainCjk(array $strings): bool
    {
        foreach ($strings as $string) {
            if ($this->containsCjk($string)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $strings
     */
    private function stringsLookEnglish(array $strings): bool
    {
        foreach ($strings as $string) {
            if ($this->looksEnglish($string)) {
                return true;
            }
        }

        return false;
    }

    private function containsCjk(string $text): bool
    {
        return preg_match('/[\x{3400}-\x{9FFF}\x{F900}-\x{FAFF}]/u', $text) === 1;
    }

    private function looksEnglish(string $text): bool
    {
        if ($this->containsCjk($text)) {
            return false;
        }

        preg_match_all('/[A-Za-z]+/', $text, $matches);
        $words = $matches[0] ?? [];
        $letterCount = strlen(implode('', $words));

        return count($words) >= 2 && $letterCount >= 8;
    }
}
