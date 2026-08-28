<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3Contract;
use PHPUnit\Framework\TestCase;

final class CareerAccountantsZhEntryGuideTest extends TestCase
{
    public function test_entry_guide_is_complete_and_stays_inside_the_existing_path_block(): void
    {
        $root = dirname(__DIR__, 5).'/content_assets/career/current/careers/accountants-and-auditors';
        $zh = json_decode((string) file_get_contents($root.'/zh-CN.json'), true, 512, JSON_THROW_ON_ERROR);
        $en = json_decode((string) file_get_contents($root.'/en.json'), true, 512, JSON_THROW_ON_ERROR);

        CareerContentV3Contract::assert($zh);
        CareerContentV3Contract::assert($en);

        $publicBlocks = collect($zh['blocks'])->reject(
            static fn (array $block): bool => in_array($block['id'], ['navigation', 'source-register'], true),
        );
        self::assertCount(11, $publicBlocks);

        $path = collect($zh['blocks'])->firstWhere('id', 'path');
        self::assertIsArray($path);
        $items = collect($path['items'])->keyBy('copy_key');

        self::assertSame(5, count($items['career.item.entry-role-comparison']['data']['rows']));
        self::assertSame(8, count($items['career.item.employer-evidence']['data']['rows']));
        self::assertSame(4, count($items['career.item.entry-portfolio']['data']['entries']));
        self::assertSame(4, count($items['career.item.interview-probation']['data']['rows']));
        self::assertSame(7, count($items['career.item.seven-day-trial']['data']['entries']));
        self::assertSame(7, count($items['career.item.seven-day-decision']['data']['entries']));

        self::assertStringContainsString(
            '一次体验不能诊断职业适合度',
            implode(' ', $items['career.item.seven-day-decision']['data']['entries']),
        );
        self::assertStringContainsString(
            '课程结业证书不能代替这些工作证据',
            implode(' ', array_merge(...array_column($items['career.item.entry-portfolio']['data']['entries'], 'values'))),
        );

        $enCopyKeys = collect($en['blocks'])
            ->flatMap(static fn (array $block): array => $block['items'])
            ->pluck('copy_key');
        foreach ([
            'career.item.recruitment-sample',
            'career.item.entry-role-comparison',
            'career.item.employer-evidence',
            'career.item.entry-portfolio',
            'career.item.interview-probation',
            'career.item.seven-day-trial',
            'career.item.seven-day-decision',
        ] as $entryCopyKey) {
            self::assertFalse($enCopyKeys->contains($entryCopyKey));
        }
    }

    public function test_recruitment_register_contains_40_deduplicated_entry_jobs_and_six_cities(): void
    {
        $path = dirname(__DIR__, 5).'/content_assets/career/current/careers/accountants-and-auditors/zh-CN.json';
        $zh = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $sourcesItem = collect($zh['blocks'])
            ->flatMap(static fn (array $block): array => $block['items'])
            ->firstWhere('type', 'sources');
        $sources = collect($sourcesItem['data']['entries'])->keyBy('id');

        $sampleSources = collect(['source-11', 'source-12', 'source-13', 'source-14', 'source-15'])
            ->map(static fn (string $sourceId): array => $sources[$sourceId]);
        $sampleSources->each(static fn (array $source) => self::assertCount(8, $source['details']));

        $details = $sampleSources->flatMap(static fn (array $source): array => $source['details'])->all();
        $urls = [];
        foreach ($details as $detail) {
            self::assertSame(1, preg_match('/https:\/\/[^\s]+$/u', $detail, $match));
            $urls[] = $match[0];
            self::assertMatchesRegularExpression('/在校生|应届生|无需经验|1 年|1—2 年|2 年/u', $detail);
        }

        self::assertCount(40, $urls);
        self::assertCount(40, array_unique($urls));
        $joined = implode(' ', $details);
        foreach (['北京', '上海', '广州', '深圳', '杭州', '成都'] as $city) {
            self::assertStringContainsString($city, $joined);
        }

        $sampleNotice = collect($zh['blocks'])
            ->firstWhere('id', 'path')['items'][0];
        self::assertSame('career.item.recruitment-sample', $sampleNotice['copy_key']);
        self::assertStringContainsString('达到 30%', implode(' ', $sampleNotice['data']['paragraphs']));
        self::assertStringContainsString('至少三类雇主', implode(' ', $sampleNotice['data']['paragraphs']));
    }
}
