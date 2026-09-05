<?php

declare(strict_types=1);

namespace Tests\Feature\SEO;

use App\Models\Article;
use App\Models\ArticleTranslationRevision;
use App\Models\CmsTranslationRevision;
use App\Models\ContentPage;
use App\Models\LandingSurface;
use App\Models\PersonalityPublicContentAsset;
use App\Models\PersonalityPublicContentAssetRevision;
use App\Models\TopicProfile;
use App\Models\TopicProfileRevision;
use App\Services\BigFive\AuthorityV2\ContentOnlyRelease\BigFiveZhContentOnlyPublisher;
use App\Services\BigFive\AuthorityV2\ReleaseGate\BigFiveAuthorityV2DraftImportWriter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\UsesIsolatedSqliteDatabase;
use Tests\TestCase;

final class BigFiveAuthorityV2ZhContentOnlyPublisherTest extends TestCase
{
    use UsesIsolatedSqliteDatabase;

    private const RELEASE = '../generated/big-five-authority-v2/big5-authority-v2-zh-content-only-release/release-package.json';

    private const BASE = '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json';

    private const BASE_AUTHORIZATION = '../generated/big-five-authority-v2/big5-authority-v2-release-gate-37/production-authorization-packet.json';

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('scales_registry')
            ->where('org_id', 0)
            ->where('primary_slug', 'big-five-personality-test-ocean-model')
            ->delete();
        DB::table('scales_registry')->updateOrInsert(['code' => 'BIG5_OCEAN'], [
            'code' => 'BIG5_OCEAN',
            'org_id' => 0,
            'primary_slug' => 'big-five-personality-test-ocean-model',
            'slugs_json' => json_encode(['big-five-personality-test-ocean-model']),
            'driver_type' => 'big5_ocean',
            'is_public' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(BigFiveAuthorityV2DraftImportWriter::class)->write(
            self::BASE,
            self::BASE_AUTHORIZATION,
            231,
            0,
        );
    }

    protected function requiresIsolatedSqliteDatabase(): bool
    {
        return in_array($this->name(), [
            'test_console_defaults_to_preflight_and_execute_is_testing_guarded',
        ], true);
    }

    public function test_preflight_locks_exact_112_chinese_assets_without_writes(): void
    {
        $before = $this->tableCounts();

        $result = app(BigFiveZhContentOnlyPublisher::class)->preflight(self::RELEASE);

        $this->assertTrue($result['ok']);
        $this->assertSame('PASS_ZH_CONTENT_ONLY_PREFLIGHT', $result['status']);
        $this->assertSame(112, $result['asset_count']);
        $this->assertSame([
            'CMS Article' => 56,
            'CMS content_pages' => 2,
            'CMS landing_surfaces/page_blocks' => 1,
            'CMS personality_public_content_assets' => 52,
            'CMS topic_profiles' => 1,
        ], $result['surface_counts']);
        $this->assertSame(60, $result['media_deferred_by_operator_count']);
        $this->assertSame(52, $result['personality_no_media_field_count']);
        $this->assertSame(0, $result['media_library_write_count']);
        $this->assertSame(0, $result['english_write_count']);
        $this->assertFalse($result['writes_committed']);
        $this->assertSame($before, $this->tableCounts());
    }

    public function test_publish_releases_112_chinese_assets_with_full_content_and_is_idempotent(): void
    {
        $this->assertLessThanOrEqual(32, strlen(BigFiveZhContentOnlyPublisher::REVISION_WORKFLOW_STATE));
        $this->assertLessThanOrEqual(32, strlen(BigFiveZhContentOnlyPublisher::LANDING_SCHEMA_VERSION));

        $publisher = app(BigFiveZhContentOnlyPublisher::class);
        $englishBefore = $this->englishRows();

        $result = $publisher->publish(self::RELEASE);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['writes_committed']);
        $this->assertSame('PASS_ZH_CONTENT_ONLY_RELEASE', $result['status']);
        $this->assertTrue($result['cache_invalidation_ok']);
        $this->assertNull($result['cache_invalidation_warning']);
        $this->assertSame(112, $result['public_release_count']);
        $this->assertTrue($result['readback']['ok']);
        $this->assertSame(112, $result['readback']['public_count']);
        $this->assertSame(35, $result['readback']['zh6_faq_count']);
        $this->assertSame([], $result['readback']['issues']);
        $this->assertSame($englishBefore, $this->englishRows());

        $this->assertSame(56, Article::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->publiclyReadable()->where('is_indexable', true)->where('sitemap_eligible', true)->where('llms_eligible', true)->count());
        $this->assertSame(52, PersonalityPublicContentAsset::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->publiclyReadable()->where('index_eligible', true)->where('sitemap_eligible', true)->where('llms_eligible', true)->count());
        $this->assertSame(2, ContentPage::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->publiclyIndexable()->count());
        $this->assertSame(1, LandingSurface::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->publishedPublic()->where('is_indexable', true)->count());
        $this->assertSame(
            BigFiveZhContentOnlyPublisher::LANDING_SCHEMA_VERSION,
            LandingSurface::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->value('schema_version'),
        );
        $this->assertSame(1, TopicProfile::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->publishedPublic()->where('is_indexable', true)->count());
        $this->assertSame(
            BigFiveZhContentOnlyPublisher::REVISION_WORKFLOW_STATE,
            PersonalityPublicContentAssetRevision::query()
                ->where('authority_package_sha256', BigFiveZhContentOnlyPublisher::RELEASE_PACKAGE_SHA256)
                ->value('workflow_state'),
        );
        $this->assertSame(
            BigFiveZhContentOnlyPublisher::REVISION_WORKFLOW_STATE,
            TopicProfileRevision::query()
                ->where('authority_package_sha256', BigFiveZhContentOnlyPublisher::RELEASE_PACKAGE_SHA256)
                ->value('workflow_state'),
        );

        $expectedSectionCounts = [
            PersonalityPublicContentAsset::ENTITY_HUB => 14,
            PersonalityPublicContentAsset::ENTITY_DOMAIN => 9,
            PersonalityPublicContentAsset::ENTITY_POLARITY => 9,
            PersonalityPublicContentAsset::ENTITY_FACET_HUB => 6,
            PersonalityPublicContentAsset::ENTITY_FACET_DETAIL => 9,
        ];
        PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('locale', 'zh-CN')
            ->get()
            ->each(function (PersonalityPublicContentAsset $asset) use ($expectedSectionCounts): void {
                $this->assertArrayHasKey($asset->entity_type, $expectedSectionCounts);
                $this->assertCount(
                    $expectedSectionCounts[$asset->entity_type],
                    $asset->content_sections_json,
                    'Unexpected section count for '.$asset->slug,
                );
            });
        ArticleTranslationRevision::query()->withoutGlobalScopes()
            ->where('authority_package_sha256', BigFiveZhContentOnlyPublisher::RELEASE_PACKAGE_SHA256)
            ->get()
            ->each(fn (ArticleTranslationRevision $revision) => $this->assertNotSame('', trim((string) $revision->content_md)));

        $domain = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('locale', 'zh-CN')->where('entity_type', PersonalityPublicContentAsset::ENTITY_DOMAIN)
            ->where('entity_key', 'openness')->firstOrFail();
        $facet = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('locale', 'zh-CN')->where('entity_type', PersonalityPublicContentAsset::ENTITY_FACET_DETAIL)
            ->where('entity_key', 'straightforwardness')->firstOrFail();
        $this->assertGreaterThanOrEqual(9, count($domain->content_sections_json));
        $this->assertGreaterThanOrEqual(9, count($facet->content_sections_json));
        $this->assertArrayNotHasKey('media_json', $domain->getAttributes());
        $this->assertNull(data_get($domain->authority_json, 'media_deferred_by_operator'));

        $hub = PersonalityPublicContentAsset::query()->withoutGlobalScopes()
            ->where('locale', 'zh-CN')->where('entity_type', PersonalityPublicContentAsset::ENTITY_HUB)->firstOrFail();
        $this->assertSame('大五人格是什么：从 OCEAN 五维度读懂行为倾向', $hub->title);
        $this->assertCount(5, $hub->faq_json);

        $publicCopy = strtolower(implode("\n", [
            ArticleTranslationRevision::query()->withoutGlobalScopes()->where('authority_package_sha256', BigFiveZhContentOnlyPublisher::RELEASE_PACKAGE_SHA256)->pluck('content_md')->implode("\n"),
            PersonalityPublicContentAsset::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->get()->map(fn (PersonalityPublicContentAsset $asset): string => json_encode([
                $asset->title, $asset->summary, $asset->content_sections_json, $asset->faq_json,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))->implode("\n"),
            ContentPage::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->pluck('content_md')->implode("\n"),
        ]));
        foreach (['资产地图', 'cms', 'backend', 'schema', 'json-ld', 'sitemap', 'llms', 'working revision', 'promotion', '等待人工复审', '待审阅草稿', '本草稿', '公开草稿'] as $token) {
            $this->assertStringNotContainsString($token, $publicCopy);
        }

        $revisionCounts = $this->releaseRevisionCounts();
        $second = $publisher->publish(self::RELEASE);
        $this->assertTrue($second['ok']);
        $this->assertSame($revisionCounts, $this->releaseRevisionCounts());
        $this->assertSame($englishBefore, $this->englishRows());
    }

    public function test_publish_reports_cache_failure_as_a_post_commit_warning(): void
    {
        Cache::partialMock()
            ->shouldReceive('forget')
            ->andThrow(new RuntimeException('simulated cache failure'));

        $result = app(BigFiveZhContentOnlyPublisher::class)->publish(self::RELEASE);

        $this->assertTrue($result['ok']);
        $this->assertSame('PASS_ZH_CONTENT_ONLY_RELEASE', $result['status']);
        $this->assertTrue($result['writes_committed']);
        $this->assertFalse($result['cache_invalidation_ok']);
        $this->assertSame(
            'PUBLIC_CACHE_INVALIDATION_FAILED_AFTER_COMMIT',
            $result['cache_invalidation_warning'],
        );
        $this->assertSame(112, $result['public_release_count']);
        $this->assertSame(111, array_sum($this->releaseRevisionCounts()));
        $this->assertSame(1, LandingSurface::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->publishedPublic()->count());
    }

    public function test_console_defaults_to_preflight_and_execute_is_testing_guarded(): void
    {
        $before = $this->tableCounts();
        $this->artisan('personality:big-five-authority-v2-zh-content-publish', ['--json' => true])
            ->assertSuccessful();
        $this->assertSame($before, $this->tableCounts());

        $this->artisan('personality:big-five-authority-v2-zh-content-publish', [
            '--execute' => true,
            '--allow-testing' => true,
            '--json' => true,
        ])->assertSuccessful();
        $this->assertSame(112, collect($this->releaseRevisionCounts())->sum() + 1);
    }

    /** @return array<string,int> */
    private function tableCounts(): array
    {
        return [
            'articles' => Article::query()->withoutGlobalScopes()->count(),
            'article_revisions' => ArticleTranslationRevision::query()->withoutGlobalScopes()->count(),
            'content_pages' => ContentPage::query()->withoutGlobalScopes()->count(),
            'content_page_revisions' => CmsTranslationRevision::query()->withoutGlobalScopes()->count(),
            'landing_surfaces' => LandingSurface::query()->withoutGlobalScopes()->count(),
            'personality_assets' => PersonalityPublicContentAsset::query()->withoutGlobalScopes()->count(),
            'personality_revisions' => PersonalityPublicContentAssetRevision::query()->count(),
            'topics' => TopicProfile::query()->withoutGlobalScopes()->count(),
            'topic_revisions' => TopicProfileRevision::query()->count(),
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    private function englishRows(): array
    {
        return [
            'articles' => Article::query()->withoutGlobalScopes()->where('locale', 'en')->orderBy('id')->get(['id', 'status', 'is_public', 'is_indexable', 'published_revision_id'])->toArray(),
            'content_pages' => ContentPage::query()->withoutGlobalScopes()->where('locale', 'en')->orderBy('id')->get(['id', 'status', 'is_public', 'is_indexable', 'published_revision_id'])->toArray(),
            'landing_surfaces' => LandingSurface::query()->withoutGlobalScopes()->where('locale', 'en')->orderBy('id')->get(['id', 'status', 'is_public', 'is_indexable'])->toArray(),
            'personality_assets' => PersonalityPublicContentAsset::query()->withoutGlobalScopes()->where('locale', 'en')->orderBy('id')->get(['id', 'launch_state', 'is_public', 'index_eligible', 'published_revision_id'])->toArray(),
            'topics' => TopicProfile::query()->withoutGlobalScopes()->where('locale', 'en')->orderBy('id')->get(['id', 'status', 'is_public', 'is_indexable', 'published_revision_id'])->toArray(),
        ];
    }

    /** @return array<string,int> */
    private function releaseRevisionCounts(): array
    {
        return [
            'articles' => ArticleTranslationRevision::query()->withoutGlobalScopes()->where('authority_package_sha256', BigFiveZhContentOnlyPublisher::RELEASE_PACKAGE_SHA256)->count(),
            'content_pages' => CmsTranslationRevision::query()->withoutGlobalScopes()->where('authority_package_sha256', BigFiveZhContentOnlyPublisher::RELEASE_PACKAGE_SHA256)->count(),
            'personality' => PersonalityPublicContentAssetRevision::query()->where('authority_package_sha256', BigFiveZhContentOnlyPublisher::RELEASE_PACKAGE_SHA256)->count(),
            'topics' => TopicProfileRevision::query()->where('authority_package_sha256', BigFiveZhContentOnlyPublisher::RELEASE_PACKAGE_SHA256)->count(),
        ];
    }
}
