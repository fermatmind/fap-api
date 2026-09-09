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
        self::assertSame('career.detail.page.v1', $first['public_readback']['render_contract_version']);
        self::assertSame(2092, $first['public_readback']['cache_content_match_count']);
        self::assertSame(2092, $first['write_counts']['cache_candidate_write_count']);
        self::assertSame(0, $first['write_counts']['database_update_count']);
        self::assertSame(0, $first['write_counts']['cache_pointer_activation_count']);
        $second = $publisher->execute(base_path());
        self::assertTrue($second['idempotent_noop']);
        self::assertSame($first['state_sha256'], $second['state_sha256']);
    }
}
