<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerContentV3FactResolver;
use App\Domain\Career\Display\CareerCurrentAuthorityPackage;
use PHPUnit\Framework\TestCase;

final class CareerAccountantsZhFactsTest extends TestCase
{
    public function test_accountants_zh_page_uses_one_current_bls_fact_set(): void
    {
        $path = dirname(__DIR__, 5).'/content_assets/career/current/careers/accountants-and-auditors/zh-CN.json';
        $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        CareerContentV3Contract::assert($raw);
        $facts = collect($raw['fact_register']['facts'])->keyBy('fact_id');

        self::assertSame('$83,680', $facts['bls-us-accountants-wage-median-2025']['display_value']);
        self::assertSame('1,595,200', $facts['bls-us-accountants-employment-2025']['display_value']);
        self::assertSame('1,674,600', $facts['bls-us-accountants-employment-2035']['display_value']);
        self::assertSame('79,400', $facts['bls-us-accountants-employment-net-change-2025-2035']['display_value']);
        self::assertSame('5%', $facts['bls-us-accountants-employment-growth-2025-2035']['display_value']);
        self::assertSame('115,300', $facts['bls-us-accountants-openings-2025-2035']['display_value']);
        self::assertSame('$6,973', $facts['editorial-us-accountants-monthly-median-2025']['display_value']);
        self::assertSame(
            '{{fact:bls-us-accountants-wage-median-2025}} ÷ 12，四舍五入',
            $facts['editorial-us-accountants-monthly-median-2025']['derivation'],
        );
        self::assertSame('¥78,500', $facts['mohrss-cn-economic-financial-wage-median-2024']['display_value']);
        self::assertSame('约 ¥6,540', $facts['editorial-cn-economic-financial-monthly-median-2024']['display_value']);
        self::assertSame('¥8,000–20,000', $facts['randstad-cn-first-tier-finance-monthly-range-2026']['display_value']);
        self::assertSame('¥6,000–15,000', $facts['randstad-cn-core-city-finance-monthly-range-2026']['display_value']);

        $resolved = (new CareerContentV3FactResolver)->resolve($raw);
        $bytes = CareerCurrentAuthorityPackage::encodeCanonical($resolved);

        self::assertStringNotContainsString('{{fact:', $bytes);
        foreach (['$81,680', '1,579,800', '124,200', '2024–2034', '2024—2034',
            '1,652,600', '72,800', '157.98 万 → 165.26 万'] as $stale) {
            self::assertStringNotContainsString($stale, $bytes);
        }

        $faq = collect($resolved['blocks'])
            ->flatMap(static fn (array $block): array => $block['items'])
            ->firstWhere('type', 'faq');
        $answers = collect($faq['data']['entries'])->keyBy('id');
        self::assertStringContainsString('$83,680', $answers['faq-6']['answer']);
        self::assertStringContainsString('115,300', $answers['faq-3']['answer']);
        self::assertSame(['source-6', 'source-2', 'source-5'], $answers['faq-6']['source_refs']);
        self::assertSame(['source-8', 'source-22', 'source-9'], $answers['faq-3']['source_refs']);
    }

    public function test_accountants_zh_sources_are_structured_used_and_market_correct(): void
    {
        $path = dirname(__DIR__, 5).'/content_assets/career/current/careers/accountants-and-auditors/zh-CN.json';
        $raw = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        CareerContentV3Contract::assert($raw);
        $sourceRegister = collect($raw['blocks'])->firstWhere('id', 'source-register');
        $sources = collect($sourceRegister['items'][0]['data']['entries'])->keyBy('id');
        self::assertFalse($sources->has('source-1'));
        self::assertFalse($sources->has('source-3'));
        self::assertFalse($sources->has('source-4'));

        foreach ($sources as $source) {
            foreach (['publisher', 'market', 'period', 'evidence_type', 'scope', 'limitation', 'accessed_at'] as $field) {
                self::assertIsString($source[$field] ?? null, "{$source['id']} 缺少 {$field}");
                self::assertNotSame('', trim($source[$field]), "{$source['id']} 的 {$field} 为空");
            }
            self::assertStringStartsWith('https://', $source['url']);
        }

        $referenced = collect();
        $walk = function (mixed $value) use (&$walk, $referenced): void {
            if (! is_array($value)) {
                return;
            }
            foreach ((array) ($value['source_refs'] ?? []) as $sourceRef) {
                $referenced->push($sourceRef);
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk($raw);
        self::assertEqualsCanonicalizing($sources->keys()->all(), $referenced->unique()->values()->all());

        $chinaSalary = collect($raw['blocks'])->firstWhere('id', 'china-salary');
        $chinaBytes = CareerCurrentAuthorityPackage::encodeCanonical($chinaSalary);
        self::assertStringNotContainsString('bls-us-', $chinaBytes);
        self::assertStringNotContainsString('source-6', $chinaBytes);
        self::assertStringNotContainsString('source-7', $chinaBytes);
        self::assertStringNotContainsString('source-8', $chinaBytes);
    }
}
