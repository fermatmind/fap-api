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

        $recruitmentSignals = collect($path['items'])->firstWhere('id', 'accountant-entry-recruitment-signals');
        $workSample = collect($path['items'])->firstWhere('id', 'accountant-entry-work-sample-transactions');
        self::assertCount(8, $recruitmentSignals['data']['rows']);
        self::assertCount(20, $workSample['data']['rows']);

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

    public function test_recruitment_register_contains_40_uniform_deduplicated_jobs_and_recomputable_signals(): void
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
        $records = [];
        $allowedEmployerTypes = ['会计师事务所', '大型企业/公共机构', '中小型企业', '共享服务/专业服务', '金融机构'];
        $allowedSignals = ['EXCEL', 'JE', 'RECON', 'CLOSE', 'EVIDENCE', 'ERP', 'ANOMALY', 'COMM'];
        foreach ($details as $detail) {
            $record = explode('｜', $detail);
            self::assertCount(10, $record);
            self::assertContains($record[3], $allowedEmployerTypes);
            self::assertNotSame('', $record[4]);
            self::assertNotSame('', $record[5]);
            self::assertNotSame('', $record[6]);
            self::assertSame('2026-08-29', $record[8]);
            self::assertSame(1, preg_match('/^https:\/\//', $record[9]));
            foreach (explode(',', $record[7]) as $signal) {
                self::assertContains($signal, $allowedSignals);
            }
            $records[] = $record;
        }

        self::assertCount(40, $records);
        self::assertCount(40, array_unique(array_column($records, 0)));
        self::assertCount(40, array_unique(array_column($records, 9)));
        foreach (['财务/会计助理', '应收应付/费用会计', '外部审计助理', '内审/稽核助理', '税务助理'] as $category) {
            self::assertCount(8, array_filter($records, static fn (array $record): bool => $record[4] === $category));
        }

        $pathBlock = collect($zh['blocks'])->firstWhere('id', 'path');
        $signalsItem = collect($pathBlock['items'])->firstWhere('id', 'accountant-entry-recruitment-signals');
        $signalCodeByLabel = [
            'Excel' => 'EXCEL',
            '分录分类' => 'JE',
            '对账' => 'RECON',
            '结账逻辑' => 'CLOSE',
            '底稿证据' => 'EVIDENCE',
            'ERP' => 'ERP',
            '异常分析' => 'ANOMALY',
            '沟通复核' => 'COMM',
        ];
        $facts = collect($zh['fact_register']['facts'])->keyBy('fact_id');
        foreach ($signalsItem['data']['rows'] as $row) {
            $code = $signalCodeByLabel[$row[0]];
            $matched = array_values(array_filter(
                $records,
                static fn (array $record): bool => in_array($code, explode(',', $record[7]), true),
            ));
            $expectedCount = count($matched).'/40';
            $expectedEmployerTypes = count(array_unique(array_column($matched, 3)));
            self::assertSame($expectedCount, $row[1]);
            self::assertSame((string) $expectedEmployerTypes, $row[2]);
            self::assertSame(
                count($matched) >= 12 && $expectedEmployerTypes >= 3 ? '常见' : '部分岗位可能要求',
                $row[3],
            );
            self::assertSame(array_column($matched, 0), explode('、', $row[4]));

            $factId = 'recruitment-signal-'.strtolower(str_replace('_', '-', $code)).'-2026';
            self::assertSame($expectedCount, $facts[$factId]['display_value']);
        }

        $sampleNotice = collect($zh['blocks'])
            ->firstWhere('id', 'path')['items'][0];
        self::assertSame('career.item.recruitment-sample', $sampleNotice['copy_key']);
        self::assertStringContainsString('达到 30%', implode(' ', $sampleNotice['data']['paragraphs']));
        self::assertStringContainsString('至少三类雇主', implode(' ', $sampleNotice['data']['paragraphs']));
    }

    public function test_fictional_work_sample_has_exactly_20_transactions_complete_supporting_material_and_credential_chain(): void
    {
        $path = dirname(__DIR__, 5).'/content_assets/career/current/careers/accountants-and-auditors/zh-CN.json';
        $zh = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $pathItems = collect(collect($zh['blocks'])->firstWhere('id', 'path')['items']);
        $rules = $pathItems->firstWhere('id', 'accountant-entry-work-sample-rules');
        $transactions = $pathItems->firstWhere('id', 'accountant-entry-work-sample-transactions')['data']['rows'];

        self::assertCount(20, $transactions);
        self::assertSame(array_map(static fn (int $id): string => sprintf('T%02d', $id), range(1, 20)), array_column($transactions, 0));
        self::assertSame(array_map(static fn (int $id): string => sprintf('E%02d', $id), range(1, 20)), array_column($transactions, 4));
        foreach ($transactions as $transaction) {
            self::assertStringStartsWith('2026-06-', $transaction[1]);
            self::assertNotSame('', $transaction[2]);
            self::assertNotSame('', $transaction[3]);
            self::assertNotSame('', $transaction[5]);
        }

        $rulesText = implode(' ', $rules['data']['paragraphs']);
        foreach (['编辑虚构练习', '人民币', '2026 年 6 月', '权责发生制', '忽略增值税和企业所得税', 'E01—E20', 'B01', 'B16', '五项交付物', '七天体验', '不提供下载按钮'] as $required) {
            self::assertStringContainsString($required, $rulesText);
        }
        foreach (['金额不一致', '重复付款', '缺失审批', '未入账手续费', '跨期', '科目错误'] as $exception) {
            self::assertStringContainsString($exception, $rulesText.' '.implode(' ', array_column($transactions, 3)));
        }

        $credentialBoundary = $pathItems->firstWhere('id', 'accountant-credential-boundary');
        $credentialText = implode(' ', $credentialBoundary['data']['paragraphs']);
        foreach (['审计助理是岗位', '全科合格是考试结果', '非执业会员与执业注册会计师是不同身份路径', '现行法律', '执业准则', '会计师事务所授权', '具体业务安排'] as $required) {
            self::assertStringContainsString($required, $credentialText);
        }
    }
}
