<?php

declare(strict_types=1);

namespace Tests\Feature\Ops;

use App\Filament\Ops\Pages\AccessTestStatisticsPage;
use App\Support\OrgContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AccessTestStatisticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_uses_one_exact_filter_cube_and_keeps_organizations_isolated(): void
    {
        $this->setOpsOrg(21);
        $this->insertRow(21, 'MBTI', 'MBTI_FORM', 'zh-CN', 3, 2, 2, 4, 8);
        $this->insertRow(99, 'MBTI', 'MBTI_FORM', 'zh-CN', 30, 20, 20, 40, 80);
        $this->insertRow(21, 'RIASEC', 'RIASEC_FORM', 'en', 7, 6, 5, 9, 12);

        $page = app(AccessTestStatisticsPage::class);
        $page->fromDate = '2026-09-18';
        $page->toDate = '2026-09-18';
        $page->scope = 'current_org';
        $page->scaleCode = 'MBTI';
        $page->formCode = 'MBTI_FORM';
        $page->locale = 'zh-CN';
        $page->applyFilters();

        $this->assertSame([], $page->warnings);
        $this->assertSame([
            'valid_visit_ips' => 3,
            'started_test_ips' => 2,
            'completed_test_ips' => 2,
            'successful_attempts' => 4,
            'valid_page_views' => 8,
        ], $page->summary);
        $this->assertCount(1, $page->dailyRows);
        $this->assertCount(1, $page->testRows);
        $this->assertSame('MBTI', $page->testRows[0]['scale_code']);
        $this->assertSame('2026-09-18 18:00:00', $page->meta['data_through_at']);
        $this->assertSame('2026-09-18 18:00:00', $page->dailyRows[0]['data_through_at']);
    }

    private function setOpsOrg(int $orgId): void
    {
        $context = app(OrgContext::class);
        $context->set($orgId, null, null, null, OrgContext::KIND_TENANT);
        app()->instance(OrgContext::class, $context);
    }

    private function insertRow(
        int $orgId,
        string $scale,
        string $form,
        string $locale,
        int $visitors,
        int $started,
        int $completed,
        int $successes,
        int $pageViews,
    ): void {
        DB::table('analytics_access_test_daily')->insert([
            'day' => '2026-09-18',
            'org_id' => $orgId,
            'scale_code' => $scale,
            'form_code' => $form,
            'locale' => $locale,
            'valid_visit_ips' => $visitors,
            'started_test_ips' => $started,
            'completed_test_ips' => $completed,
            'successful_attempts' => $successes,
            'valid_page_views' => $pageViews,
            'coverage_status' => 'complete',
            'source_version' => 'access_test_statistics.v1',
            'data_through_at' => '2026-09-18 10:00:00',
            'last_successful_refresh_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
