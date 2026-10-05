<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Career\Display;

use App\Domain\Career\Display\CareerCurrentAuthorityPublisher;
use Tests\TestCase;

final class CareerCurrentAuthorityPublisherTest extends TestCase
{
    public function test_all_files_publish_without_display_database_rows_and_repeat_without_writes(): void
    {
        $this->app->instance(
            \App\Domain\Career\Publish\CareerRuntimePublishProjectionVisibility::class,
            new \Tests\Fixtures\Career\CareerRuntimePublishProjectionVisibilityFixture(defaultItemPublished: false),
        );
        $publisher = app(CareerCurrentAuthorityPublisher::class);
        $first = $publisher->execute(base_path());
        self::assertSame('file_authoritative', $first['file_readback']['delivery_mode']);
        self::assertSame(2092, $first['file_readback']['file_hash_match_count']);
        self::assertSame(0, $first['write_counts']['cache_candidate_write_count']);
        self::assertSame(0, $first['write_counts']['database_update_count']);
        self::assertSame(0, $first['write_counts']['cache_pointer_activation_count']);
        $this->travel(2)->days();
        $second = $publisher->execute(base_path(), false, [[
            'slug' => 'accountants-and-auditors',
            'locale' => 'en',
        ]]);
        self::assertTrue($second['idempotent_noop']);
        self::assertSame(1, $second['authority']['changed_slug_count']);
        self::assertSame(1, $second['authority']['changed_locale_page_count']);
        self::assertSame($first['state_sha256'], $second['state_sha256']);
        $page = app(\App\Domain\Career\Display\CareerPageProjector::class)->read('actors', 'en');
        \App\Support\PublicProjectionCache::forget(\App\Services\Career\CareerFilePageReader::cacheKey($page));
        $cold = $publisher->execute(base_path(), false, [['slug' => 'accountants-and-auditors', 'locale' => 'en']]);
        self::assertSame($first['state_sha256'], $cold['state_sha256']);
        self::assertSame(0, $cold['write_counts']['cache_candidate_write_count']);
        self::assertNull(\App\Support\PublicProjectionCache::get(\App\Services\Career\CareerFilePageReader::cacheKey($page)));
    }

    public function test_corrupt_installed_file_binding_fails_even_with_no_cache_requirement(): void
    {
        $index = app(\App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader::class)->indexForPublish(base_path());
        $index['entries']['actors']['zh-CN']['sha256'] = str_repeat('0', 64);
        $loader = \Mockery::mock(\App\Domain\Career\Display\CareerCurrentAuthorityPackageLoader::class);
        $loader->shouldReceive('indexForPublish')->once()->andReturn($index);
        $publisher = new CareerCurrentAuthorityPublisher($loader, app(\App\Services\Career\PublicCareerAuthorityResponseCache::class));
        $this->expectException(\App\Domain\Career\Display\CareerCurrentAuthorityPublisherFailure::class);
        $this->expectExceptionMessage('CURRENT_FILE_AUTHORITY_READBACK_FAILED');
        $publisher->execute(base_path());
    }
}
