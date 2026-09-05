<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Career\Display;

use App\Domain\Career\Display\CareerContentV3Contract;
use App\Domain\Career\Display\CareerContentV3FactResolver;
use PHPUnit\Framework\TestCase;

final class CareerAccountantsZhEntryGuideTest extends TestCase
{
    public function test_entry_guide_preserves_blocks_and_existing_renderable_items(): void
    {
        $zh = $this->content();
        CareerContentV3Contract::assert($zh);
        $en = $this->content('en');
        CareerContentV3Contract::assert($en);
        self::assertCount(13, $zh['blocks']);
        $items = collect($this->pathItems())->keyBy('copy_key');
        self::assertCount(5, $items['career.item.entry-role-comparison']['data']['rows']);
        self::assertSame('cards', $items['career.item.employer-evidence']['type']);
        self::assertSame('cards', $items['career.item.entry-work-sample-data']['type']);
        self::assertCount(4, $items['career.item.entry-portfolio']['data']['entries']);
        self::assertCount(4, $items['career.item.interview-probation']['data']['rows']);
        self::assertCount(7, $items['career.item.seven-day-trial']['data']['entries']);
        self::assertCount(7, $items['career.item.seven-day-decision']['data']['entries']);
        foreach (['career.item.recruitment-sample', 'career.item.recruitment-signals'] as $removedKey) {
            self::assertFalse($items->has($removedKey));
        }
        $enKeys = collect($en['blocks'])->flatMap(static fn (array $block): array => $block['items'])->pluck('copy_key');
        foreach (['career.item.entry-role-comparison', 'career.item.employer-evidence', 'career.item.entry-work-sample-data', 'career.item.entry-portfolio', 'career.item.seven-day-trial'] as $key) {
            self::assertFalse($enKeys->contains($key));
        }
    }

    public function test_task_guidance_uses_official_sources_without_recruitment_statistics(): void
    {
        $zh = $this->content();
        $resolved = (new CareerContentV3FactResolver)->resolve($zh);
        $encoded = json_encode($resolved, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        foreach (['recruitment-signal-', 'source-11', 'source-12', 'source-13', 'source-14', 'source-15', '40 条', '20 笔', 'E01—E20', 'B01', 'B16', 'accountant-entry-work-sample-transactions', '{{fact:'] as $removed) {
            self::assertStringNotContainsString($removed, $encoded);
        }
        $employer = collect($this->pathItems())->firstWhere('id', 'accountant-entry-employer-evidence');
        self::assertSame(['source-10', 'source-8'], $employer['source_refs']);
        self::assertSame(['记录对应材料', '说明差异', '接受复核', '先看具体岗位'], array_column(array_column($employer['data']['entries'], 'values'), 0));
        $sources = collect($zh['blocks'])->flatMap(static fn (array $block): array => $block['items'])->firstWhere('type', 'sources');
        $sourceIds = array_column($sources['data']['entries'], 'id');
        self::assertContains('source-10', $sourceIds);
        self::assertContains('source-8', $sourceIds);
        self::assertStringContainsString('不构成中国入职或执业门槛', $encoded);
        self::assertStringNotContainsString('不用先做整套账。试着把一个数字差异讲清楚', $encoded);
        self::assertStringNotContainsString('下面是依据职业任务资料整理的阅读提示', $encoded);
    }

    public function test_fictional_expense_sample_is_self_contained_and_does_not_invent_a_conclusion(): void
    {
        $sample = collect($this->pathItems())->firstWhere('id', 'accountant-entry-work-sample');
        self::assertCount(4, $sample['data']['entries']);
        $text = implode(' ', array_merge(...array_column($sample['data']['entries'], 'values')));
        foreach (['编辑虚构练习', '非真实企业数据', '人民币', '报销申请：5,200元', '现有发票合计：4,800元', '付款记录：5,200元', '400元', '审批记录与差额说明尚未提供', '不能直接认定为违规', '不自行补造凭证', '有权限的负责人复核', '不构成完整做账、税务处理或审计结论'] as $required) {
            self::assertStringContainsString($required, $text);
        }
        self::assertSame(400, 5200 - 4800);
        self::assertArrayNotHasKey('rows', $sample['data']);
        self::assertArrayNotHasKey('fact_refs', $sample);
        self::assertArrayNotHasKey('source_refs', $sample);
    }

    public function test_portfolio_trial_and_credential_boundaries_remain_consistent(): void
    {
        $items = collect($this->pathItems())->keyBy('id');
        $portfolio = implode(' ', array_merge(...array_column($items['accountant-entry-portfolio']['data']['entries'], 'values')));
        foreach (['使用上方虚构报销材料', '完成一页核对说明', '不要求凭这三项摘要编制分录', '课程结业证书不能代替这些工作证据'] as $required) {
            self::assertStringContainsString($required, $portfolio);
        }
        $trial = implode(' ', array_merge(...array_column($items['accountant-entry-seven-day-trial']['data']['entries'], 'values')));
        self::assertStringContainsString('没有新增材料的问题仍标为待确认', $trial);
        self::assertStringContainsString('一次体验不能诊断职业适合度', implode(' ', $items['accountant-entry-seven-day-decision']['data']['entries']));
        $credential = implode(' ', $items['accountant-credential-boundary']['data']['paragraphs']);
        foreach (['审计助理是岗位', '全科合格是考试结果', '非执业会员与执业注册会计师是不同身份路径', '现行法律', '执业准则', '会计师事务所授权', '具体业务安排'] as $required) {
            self::assertStringContainsString($required, $credential);
        }
    }

    private function content(string $locale = 'zh-CN'): array
    {
        $path = dirname(__DIR__, 5).'/content_assets/career/current/careers/accountants-and-auditors/'.$locale.'.json';

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    private function pathItems(): array
    {
        return collect($this->content()['blocks'])->firstWhere('id', 'path')['items'];
    }
}
