<?php

declare(strict_types=1);

namespace Tests\Unit\ContentPromotion;

use App\Models\Article;
use App\Services\ContentPromotion\IqEqTopicPrerequisites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class IqEqTopicPrerequisitesTest extends TestCase
{
    use RefreshDatabase;
    use \Tests\Unit\ContentPromotion\Concerns\SeedsIqEqTopicPrerequisites;

    public function test_absent_required_page_fails_before_scale_read_or_any_business_write(): void
    {
        $this->expectExceptionMessage('iq_eq_topic_required_article_unpublished');
        app(IqEqTopicPrerequisites::class)->assertPublished();
    }

    public function test_all_reviewed_article_bytes_do_not_replace_unpublished_iq_entry_copy(): void
    {
        $this->seedArticles();
        $this->expectExceptionMessage('iq_eq_topic_required_iq_entry_copy_drift');
        app(IqEqTopicPrerequisites::class)->assertPublished();
    }

    public function test_last_locale_authority_drift_refuses_the_whole_dependency_set(): void
    {
        $this->seedArticles();
        $last = Article::query()->withoutGlobalScopes()->where('slug', 'what-is-iq-and-how-it-is-measured')->where('locale', 'en')->firstOrFail();
        $last->publishedRevision->forceFill(['authority_package_sha256' => str_repeat('f', 64)])->saveQuietly();
        $this->expectExceptionMessage('iq_eq_topic_required_article_authority_drift');
        app(IqEqTopicPrerequisites::class)->assertPublished();
    }

    public function test_cached_scale_does_not_authorize_a_withdrawn_database_entry(): void
    {
        $this->seedArticles();
        $this->seedIqEntry();
        self::assertNotNull(app(\App\Services\Scale\ScaleRegistry::class)->getByCode('IQ_RAVEN', 0));
        foreach (['scales_registry', 'scales_registry_v2'] as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                \Illuminate\Support\Facades\DB::table($table)->where('org_id', 0)->where('code', 'IQ_RAVEN')->update(['is_public' => false]);
            }
        }
        $this->expectExceptionMessage('iq_eq_topic_required_scale_unavailable');
        app(IqEqTopicPrerequisites::class)->assertPublished();
    }

    public function test_exact_bilingual_articles_and_iq_entry_pass_without_business_writes(): void
    {
        $this->seedArticles();
        $this->seedIqEntry();
        $writes = [];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^(insert|update|delete)/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        app(IqEqTopicPrerequisites::class)->assertPublished();
        self::assertSame([], $writes);
    }
}
