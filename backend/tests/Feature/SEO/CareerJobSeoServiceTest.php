<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\CareerJob;
use App\Models\CareerJobSeoMeta;
use App\Services\Cms\CareerJobSeoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerJobSeoServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_frontend_unavailable_public_job_is_forced_noindex(): void
    {
        $job = $this->createJob([
            'job_code' => 'backend-engineer',
            'slug' => 'backend-engineer',
            'title' => 'Backend Engineer',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createSeoMeta($job, [
            'canonical_url' => 'https://www.fermatmind.com/en/career/jobs/backend-engineer',
            'robots' => 'index,follow',
        ]);

        $service = app(CareerJobSeoService::class);

        $this->assertFalse($service->isFrontendDetailAvailable($job, 'en'));
        $this->assertFalse($service->isPublicIndexable($job, 'en'));

        $meta = $service->buildMeta($job, 'en');

        $this->assertSame('https://fermatmind.com/en/career/jobs/backend-engineer', $meta['canonical']);
        $this->assertSame('noindex,follow', $meta['robots']);
    }

    public function test_docx_backed_zh_job_keeps_indexable_meta_when_frontend_route_is_available(): void
    {
        $job = $this->createJob([
            'job_code' => 'accountants-and-auditors',
            'slug' => 'accountants-and-auditors',
            'locale' => 'zh-CN',
            'title' => '会计师和审计师',
            'subtitle' => 'Accountants and Auditors',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
            'market_demand_json' => [
                'source_refs' => [
                    ['url' => 'https://www.bls.gov/ooh/business-and-financial/accountants-and-auditors.htm'],
                ],
            ],
        ]);
        $this->createSeoMeta($job, [
            'canonical_url' => 'https://www.fermatmind.com/zh/career/jobs/accountants-and-auditors',
            'robots' => 'index,follow',
            'jsonld_overrides_json' => [
                'source_docx' => '01_会计师和审计师_accountants-and-auditors.docx',
            ],
        ]);

        $service = app(CareerJobSeoService::class);

        $this->assertTrue($service->isFrontendDetailAvailable($job, 'zh-CN'));
        $this->assertTrue($service->isPublicIndexable($job, 'zh-CN'));

        $meta = $service->buildMeta($job, 'zh-CN');

        $this->assertSame('https://fermatmind.com/zh/career/jobs/accountants-and-auditors', $meta['canonical']);
        $this->assertSame('index,follow', $meta['robots']);
    }

    public function test_noindex_robot_override_is_preserved_when_exposure_is_withheld(): void
    {
        $job = $this->createJob([
            'job_code' => 'frontend-engineer',
            'slug' => 'frontend-engineer',
            'title' => 'Frontend Engineer',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createSeoMeta($job, [
            'robots' => 'noindex,nofollow',
        ]);

        $meta = app(CareerJobSeoService::class)->buildMeta($job, 'en');

        $this->assertSame('noindex,nofollow', $meta['robots']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createJob(array $overrides = []): CareerJob
    {
        /** @var CareerJob */
        return CareerJob::query()->create(array_merge([
            'org_id' => 0,
            'job_code' => 'career-job',
            'slug' => 'career-job',
            'locale' => 'en',
            'title' => 'Career job',
            'subtitle' => 'Structured role profile',
            'excerpt' => 'A structured career job profile.',
            'body_md' => '# Career job',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createSeoMeta(CareerJob $job, array $overrides = []): CareerJobSeoMeta
    {
        /** @var CareerJobSeoMeta */
        return CareerJobSeoMeta::query()->create(array_merge([
            'job_id' => (int) $job->id,
            'seo_title' => null,
            'seo_description' => null,
            'canonical_url' => null,
            'og_title' => null,
            'og_description' => null,
            'og_image_url' => null,
            'twitter_title' => null,
            'twitter_description' => null,
            'twitter_image_url' => null,
            'robots' => null,
            'jsonld_overrides_json' => null,
        ], $overrides));
    }
}
