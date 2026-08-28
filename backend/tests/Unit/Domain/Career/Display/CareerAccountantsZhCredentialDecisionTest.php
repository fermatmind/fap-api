<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3Contract;
use PHPUnit\Framework\TestCase;

final class CareerAccountantsZhCredentialDecisionTest extends TestCase
{
    public function test_credential_decision_distinguishes_exam_membership_and_practice(): void
    {
        $root = dirname(__DIR__, 5).'/content_assets/career/current/careers/accountants-and-auditors';
        $zh = json_decode((string) file_get_contents($root.'/zh-CN.json'), true, 512, JSON_THROW_ON_ERROR);
        $en = json_decode((string) file_get_contents($root.'/en.json'), true, 512, JSON_THROW_ON_ERROR);

        CareerContentV3Contract::assert($zh);
        CareerContentV3Contract::assert($en);

        $path = collect($zh['blocks'])->firstWhere('id', 'path');
        self::assertIsArray($path);
        $items = collect($path['items'])->keyBy('copy_key');
        $decision = $items['career.item.credential-decision'];
        $boundary = $items['career.item.credential-boundary'];

        self::assertSame([
            'credential',
            'audience',
            'job_value',
            'universal_entry_requirement',
            'conditions',
            'investment',
            'limitation',
        ], $decision['data']['column_keys']);
        self::assertSame([
            '无证书',
            '初级会计专业技术资格',
            '中级会计专业技术资格',
            'CPA 全国统一考试全科合格',
            '中注协非执业会员',
            '执业注册会计师',
        ], array_column($decision['data']['rows'], 0));

        $decisionText = implode(' ', array_merge(...$decision['data']['rows']));
        foreach ([
            '不是所有会计岗位的法定入职要求',
            '全科合格只表示考试结果',
            '非执业会员不是执业注册会计师',
            '审计报告责任仍须结合现行法律',
        ] as $requiredBoundary) {
            self::assertStringContainsString($requiredBoundary, $decisionText);
        }
        self::assertStringContainsString('不保证录用', $decisionText);
        self::assertStringNotContainsString('必然加薪', $decisionText);
        self::assertStringNotContainsString('通过率', $decisionText);
        self::assertStringNotContainsString('备考小时', $decisionText);

        self::assertSame(
            ['source-16', 'source-18', 'source-19', 'source-20', 'source-21'],
            $decision['source_refs'],
        );
        self::assertSame($decision['source_refs'], $boundary['source_refs']);

        $enCopyKeys = collect($en['blocks'])
            ->flatMap(static fn (array $block): array => $block['items'])
            ->pluck('copy_key');
        self::assertFalse($enCopyKeys->contains('career.item.credential-decision'));
        self::assertFalse($enCopyKeys->contains('career.item.credential-boundary'));
    }

    public function test_credential_sources_are_current_primary_authorities(): void
    {
        $path = dirname(__DIR__, 5).'/content_assets/career/current/careers/accountants-and-auditors/zh-CN.json';
        $zh = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $sourcesItem = collect($zh['blocks'])
            ->flatMap(static fn (array $block): array => $block['items'])
            ->firstWhere('type', 'sources');
        $sources = collect($sourcesItem['data']['entries'])->keyBy('id');

        self::assertSame('中华人民共和国财政部', $sources['source-18']['publisher']);
        self::assertSame('中国注册会计师协会', $sources['source-19']['publisher']);
        self::assertSame('中国注册会计师协会', $sources['source-20']['publisher']);
        self::assertSame('现行法律', $sources['source-21']['evidence_type']);

        foreach (['source-18', 'source-19', 'source-20', 'source-21'] as $sourceId) {
            self::assertSame('中国大陆', $sources[$sourceId]['market']);
            self::assertStringStartsWith('https://', $sources[$sourceId]['url']);
            self::assertSame('2026-08-29', $sources[$sourceId]['accessed_at']);
            self::assertNotSame('', $sources[$sourceId]['scope']);
            self::assertNotSame('', $sources[$sourceId]['limitation']);
        }
    }
}
