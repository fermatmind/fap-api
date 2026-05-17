<?php

declare(strict_types=1);

namespace App\Services\SeoIntel\Drift;

final class CrawlerUserAgentClassifier
{
    public function classify(string $userAgent): string
    {
        $normalized = strtolower($userAgent);

        return match (true) {
            str_contains($normalized, 'googlebot') => 'googlebot',
            str_contains($normalized, 'bingbot') => 'bingbot',
            str_contains($normalized, 'baiduspider') => 'baiduspider',
            str_contains($normalized, '360spider') || str_contains($normalized, 'haosouspider') => '360spider',
            str_contains($normalized, 'sogou') => 'sogou',
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

    /**
     * @return list<string>
     */
    public function supportedFamilies(): array
    {
        return [
            'googlebot',
            'bingbot',
            'baiduspider',
            '360spider',
            'sogou',
            'shenma_yisou',
            'bytespider',
            'ai_crawler',
            'unknown_bot',
            'human_or_unknown',
        ];
    }
}
