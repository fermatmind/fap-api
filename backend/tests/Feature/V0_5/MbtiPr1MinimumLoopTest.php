<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MbtiPr1MinimumLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_mbti_pr1_minimum_loop_builds_articles_guides_and_recommendation_linkage(): void
    {
        $this->artisan('personality:import-local-baseline', [
            '--locale' => ['en', 'zh-CN'],
            '--upsert' => true,
            '--status' => 'published',
        ])->assertExitCode(0);

        $this->artisan('career-jobs:import-local-baseline', [
            '--locale' => ['en', 'zh-CN'],
            '--upsert' => true,
            '--status' => 'published',
        ])->assertExitCode(0);

        $this->artisan('articles:import-local-baseline', [
            '--locale' => ['en', 'zh-CN'],
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])->assertExitCode(0);

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['en'],
            '--guide' => ['intj-career-playbook', 'enfp-career-playbook'],
            '--upsert' => true,
            '--status' => 'published',
        ])->assertExitCode(0);

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['zh-CN'],
            '--guide' => ['istj-career-playbook', 'esfp-career-playbook'],
            '--upsert' => true,
            '--status' => 'published',
        ])->assertExitCode(0);

        $articlesEn = $this->getJson('/api/v0.5/articles?locale=en&org_id=0&page=1');
        $articlesEn->assertOk();
        $this->assertGreaterThan(0, (int) $articlesEn->json('pagination.total'));

        $guidesEn = $this->getJson('/api/v0.5/career-guides?locale=en&org_id=0&page=1');
        $guidesEn->assertOk();
        $this->assertGreaterThan(0, (int) $guidesEn->json('pagination.total'));

        $intjGuide = $this->getJson('/api/v0.5/career-guides/intj-career-playbook?locale=en&org_id=0');
        $intjGuide->assertOk();
        $this->assertGreaterThanOrEqual(2, count((array) $intjGuide->json('related_articles')));

        $intjA = $this->getJson('/api/v0.5/career-recommendations/mbti/intj-a?locale=en&org_id=0');
        $intjA->assertOk();
        $this->assertGreaterThan(0, count((array) $intjA->json('matched_jobs')));
        $this->assertGreaterThan(0, count((array) $intjA->json('matched_guides')));

        $enfpT = $this->getJson('/api/v0.5/career-recommendations/mbti/enfp-t?locale=en&org_id=0');
        $enfpT->assertOk();
        $this->assertGreaterThan(0, count((array) $enfpT->json('matched_jobs')));
        $this->assertGreaterThan(0, count((array) $enfpT->json('matched_guides')));

        $istjA = $this->getJson('/api/v0.5/career-recommendations/mbti/istj-a?locale=zh-CN&org_id=0');
        $istjA->assertOk();
        $this->assertGreaterThan(0, count((array) $istjA->json('matched_jobs')));
        $this->assertGreaterThan(0, count((array) $istjA->json('matched_guides')));

        $esfpT = $this->getJson('/api/v0.5/career-recommendations/mbti/esfp-t?locale=zh-CN&org_id=0');
        $esfpT->assertOk();
        $this->assertGreaterThan(0, count((array) $esfpT->json('matched_jobs')));
        $this->assertGreaterThan(0, count((array) $esfpT->json('matched_guides')));
    }
}
