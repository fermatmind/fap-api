<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Analytics;

use App\Services\Analytics\MeasurementAttributionDimensions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MeasurementAttributionDimensionsTest extends TestCase
{
    #[DataProvider('referrers')]
    public function test_existing_referrer_is_classified_without_exposing_the_url(mixed $referrer, string $expected): void
    {
        $dimensions = (new MeasurementAttributionDimensions)->fromAttempt([
            'scale_code' => 'RIASEC', 'locale' => 'zh-CN', 'channel' => 'web',
            'answers_summary_json' => ['meta' => ['form_code' => 'riasec_60', 'referrer' => $referrer]],
        ]);

        self::assertSame($expected, $dimensions['organic_channel']);
        self::assertArrayNotHasKey('referrer', $dimensions);
        self::assertArrayNotHasKey('referrer_host', $dimensions);
        self::assertStringNotContainsString('private-query', json_encode($dimensions, JSON_THROW_ON_ERROR));
    }

    public static function referrers(): array
    {
        return [
            ['https://www.google.com/search?q=private-query', 'google'],
            ['https://www.google.com.hk/', 'google'],
            ['https://cn.bing.com/search?q=private-query', 'bing'],
            ['https://www.baidu.com/s?wd=private-query', 'baidu'],
            ['https://www.sogou.com/', 'sogou'],
            ['https://google.com.evil.example/search?q=private-query', 'unknown'],
            ['https://evilgoogle.com/', 'unknown'],
            ['https://google.com@evil.example/', 'unknown'],
            ['https://user:private-query@google.com/', 'unknown'],
            ['ftp://google.com/', 'unknown'],
            ['https://fermatmind.com/zh/tests/holland-career-interest-test-riasec', 'unknown'],
            ['private_route:result', 'unknown'],
            [null, 'unknown'],
            [[], 'unknown'],
        ];
    }

    public function test_explicit_campaign_and_nonorganic_channels_keep_precedence(): void
    {
        foreach ([
            [['source' => 'google', 'medium' => 'cpc'], 'web', 'not_organic'],
            [['source' => 'bing', 'medium' => 'organic'], 'web', 'bing'],
            [['source' => 'newsletter'], 'web', 'unknown'],
            [['medium' => 'organic'], 'web', 'other_search'],
            [[], 'paid', 'not_organic'],
        ] as [$utm, $channel, $expected]) {
            $dimensions = (new MeasurementAttributionDimensions)->fromAttempt([
                'channel' => $channel,
                'answers_summary_json' => ['meta' => ['utm' => $utm, 'referrer' => 'https://www.google.com/']],
            ]);
            self::assertSame($expected, $dimensions['organic_channel']);
        }
    }

    public function test_existing_click_markers_do_not_become_an_organic_search_inference(): void
    {
        foreach (['gclid', 'msclkid', 'fbclid', 'bd_vid'] as $marker) {
            $dimensions = (new MeasurementAttributionDimensions)->fromAttempt([
                'channel' => 'web',
                'answers_summary_json' => ['meta' => [
                    'referrer' => 'https://www.google.com/', $marker => 'private-marker',
                ]],
            ]);
            self::assertSame('unknown', $dimensions['organic_channel']);
            self::assertStringNotContainsString('private-marker', json_encode($dimensions, JSON_THROW_ON_ERROR));
        }
    }

    public function test_partial_campaign_tags_preserve_unknown_instead_of_using_referrer(): void
    {
        foreach (['campaign', 'term', 'content'] as $field) {
            $dimensions = (new MeasurementAttributionDimensions)->fromAttempt([
                'channel' => 'web',
                'answers_summary_json' => ['meta' => [
                    'utm' => [$field => 'explicit-campaign'], 'referrer' => 'https://www.google.com/',
                ]],
            ]);
            self::assertSame('unknown', $dimensions['organic_channel']);
        }
    }

    public function test_existing_paid_path_keys_block_referrer_without_exposing_their_values(): void
    {
        foreach (['landing_path', 'current_path'] as $field) {
            foreach (['gclid', 'msclkid', 'fbclid', 'bd_vid', '%67clid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $key) {
                $dimensions = (new MeasurementAttributionDimensions)->fromAttempt([
                    'channel' => 'web',
                    'answers_summary_json' => ['meta' => [
                        $field => '/zh/tests/example?'.$key.'=private-marker&q=private-query',
                        'referrer' => 'https://www.google.com/',
                    ]],
                ]);
                self::assertSame('unknown', $dimensions['organic_channel']);
                self::assertStringNotContainsString('private-marker', json_encode($dimensions, JSON_THROW_ON_ERROR));
                self::assertStringNotContainsString('private-query', json_encode($dimensions, JSON_THROW_ON_ERROR));
            }
        }
    }
}
