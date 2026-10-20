<?php

declare(strict_types=1);

namespace Tests\Unit\SeoIntel;

use App\Domain\Career\Display\CareerContentV3AuthorityPackage;
use App\Domain\Career\Display\CareerContentV3CanonicalReader;
use App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility;
use App\Services\Career\CareerDirectoryReadModelBuilder;
use App\Services\Career\PublicCareerAuthorityResponseCache;
use App\Services\SeoIntel\Sources\CurrentPublicUrlAuthoritySource;
use App\Support\PublicProjectionCache;
use Mockery;
use Tests\TestCase;

final class BoundedCareerAuthoritySourceTest extends TestCase
{
    public function test_one_job_reads_only_its_publication_and_current_body(): void
    {
        $package = app(CareerContentV3AuthorityPackage::class);
        $index = $package->manifestIndex(base_path());
        $slug = null;
        foreach ($index['entries'] as $identity => $locales) {
            if (($locales['en']['body_qualification']['has_public_body'] ?? false) === true) {
                $slug = $identity;
                break;
            }
        }
        self::assertNotNull($slug);
        $reader = Mockery::mock(CareerContentV3CanonicalReader::class);
        $reader->shouldReceive('forgetLoadedAuthority')->once();
        $reader->shouldReceive('authority')->andReturn($index);
        $reader->shouldReceive('hasPublicBody')->once()->with($slug, 'en')->andReturnTrue();
        app()->instance(CareerContentV3CanonicalReader::class, $reader);
        $projection = Mockery::mock(CareerRuntimePublishProjectionVisibility::class);
        $projection->shouldReceive('itemForSlug')->once()->with($slug, 'en')->andReturn([
            'slug' => $slug, 'locale' => 'en', 'runtime_publish_state' => 'published',
            'public_resolution_type' => 'public_canonical_job', 'detail_route_enabled' => true,
            'robots_indexable' => true, 'release_gate_pass' => true, 'canonical_self' => true, 'blockers' => [],
        ]);
        app()->instance(CareerRuntimePublishProjectionVisibility::class, $projection);
        PublicProjectionCache::put(PublicCareerAuthorityResponseCache::DIRECTORY_READ_MODEL_CACHE_KEY_PREFIX.':en', [
            'read_model_version' => CareerDirectoryReadModelBuilder::READ_MODEL_VERSION, 'locale' => 'en',
            'items' => [['slug' => $slug, 'indexable' => true, 'detail_ready' => true],
                ['slug' => 'unrelated-job', 'indexable' => true, 'detail_ready' => true]],
        ], 60);
        $records = app(CurrentPublicUrlAuthoritySource::class)->candidatesForEntity('career_job', $slug, 'en');
        self::assertCount(1, $records);
        self::assertSame($slug, $records[0]->entityIdOrSlug);
        self::assertSame($index['entries'][$slug]['en']['sha256'], $records[0]->metadata['authority_revision']);
        self::assertSame('career_runtime_publish_projection', $records[0]->sourceAuthority);
    }

    public function test_missing_directory_authority_is_an_error_not_retirement(): void
    {
        $this->expectExceptionMessage('Career directory authority cache is unavailable');
        app(\App\Services\Career\CareerDirectoryAuthorityService::class)->indexableItem('accountants-and-auditors', 'en');
    }

    public function test_visibility_exceeds_longest_existing_job_and_reports_remain_separate(): void
    {
        $config = require config_path('queue.php');
        self::assertSame(660, $config['connections']['redis']['retry_after']);
        self::assertSame(660, $config['connections']['database']['retry_after']);
        self::assertSame(300, $config['connections']['database_reports']['retry_after']);
    }
}
