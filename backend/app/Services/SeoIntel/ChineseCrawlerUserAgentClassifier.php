<?php

declare(strict_types=1);

namespace App\Services\SeoIntel;

final class ChineseCrawlerUserAgentClassifier
{
    public function classify(string $userAgent): string
    {
        $normalized = strtolower($userAgent);

        return match (true) {
            str_contains($normalized, 'googlebot') => 'googlebot',
            str_contains($normalized, 'bingbot') => 'bingbot',
            str_contains($normalized, 'baiduspider') => 'baiduspider',
            str_contains($normalized, '360spider') || str_contains($normalized, 'haosouspider') => 'so360_spider',
            str_contains($normalized, 'sogou') => 'sogou_spider',
            str_contains($normalized, 'shenma') || str_contains($normalized, 'yisouspider') => 'shenma_yisou',
            str_contains($normalized, 'bytespider') => 'bytespider',
            str_contains($normalized, 'gptbot'),
            str_contains($normalized, 'claudebot'),
            str_contains($normalized, 'perplexitybot'),
            str_contains($normalized, 'ccbot') => 'ai_crawler',
            str_contains($normalized, 'bot'),
            str_contains($normalized, 'spider'),
            str_contains($normalized, 'crawler') => 'unknown_bot',
            default => 'human_or_unknown',
        };
    }

    public function sourceEngineFor(string $botFamily): string
    {
        $mapping = config('seo_intel.chinese_crawler_log_foundation.source_engine_mapping', []);

        if (! is_array($mapping)) {
            return 'unknown';
        }

        $sourceEngine = $mapping[$botFamily] ?? 'unknown';

        return is_string($sourceEngine) && $sourceEngine !== '' ? $sourceEngine : 'unknown';
    }

    /**
     * @return list<string>
     */
    public function supportedFamilies(): array
    {
        return [
            'googlebot',
            'bingbot',
            'baiduspider',
            'so360_spider',
            'sogou_spider',
            'shenma_yisou',
            'bytespider',
            'ai_crawler',
            'unknown_bot',
            'human_or_unknown',
        ];
    }
}
