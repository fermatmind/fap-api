<?php

declare(strict_types=1);

namespace Tests\Feature\TopicsCms;

use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileRevision;
use App\Models\TopicProfileSection;
use App\Models\TopicProfileSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class TopicBaselineImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purgeTopicTables();
    }

    public function test_dry_run_does_not_write_database(): void
    {
        $this->artisan('topics:import-local-baseline', [
            '--dry-run' => true,
            '--source-dir' => 'tests/Fixtures/topic_baseline',
        ])->assertExitCode(0);

        $this->assertSame(0, TopicProfile::query()->count());
        $this->assertSame(0, TopicProfileSection::query()->count());
        $this->assertSame(0, TopicProfileEntry::query()->count());
        $this->assertSame(0, TopicProfileSeoMeta::query()->count());
        $this->assertSame(0, TopicProfileRevision::query()->count());
    }

    public function test_default_mode_creates_missing_topics_sections_entries_seo_meta_and_revision(): void
    {
        $this->artisan('topics:import-local-baseline', [
            '--locale' => ['en'],
            '--topic' => ['mbti'],
            '--status' => 'draft',
            '--source-dir' => 'tests/Fixtures/topic_baseline',
        ])->assertExitCode(0);

        $this->assertSame(1, TopicProfile::query()->count());
        $this->assertSame(3, TopicProfileSection::query()->count());
        $this->assertSame(4, TopicProfileEntry::query()->count());
        $this->assertSame(1, TopicProfileSeoMeta::query()->count());
        $this->assertSame(1, TopicProfileRevision::query()->count());

        $profile = TopicProfile::query()
            ->withoutGlobalScopes()
            ->where('topic_code', 'mbti')
            ->where('locale', 'en')
            ->firstOrFail();

        $this->assertSame(0, (int) $profile->org_id);
        $this->assertSame('draft', $profile->status);
        $this->assertNull($profile->published_at);

        $revision = TopicProfileRevision::query()
            ->where('profile_id', (int) $profile->id)
            ->orderBy('revision_no')
            ->firstOrFail();

        $this->assertSame(1, (int) $revision->revision_no);
        $this->assertSame('baseline import', $revision->note);
    }

    public function test_default_mode_skips_existing_topics_without_overwriting(): void
    {
        $topic = TopicProfile::query()->create([
            'org_id' => 0,
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'en',
            'title' => 'Do Not Overwrite',
            'status' => 'draft',
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
        ]);

        TopicProfileRevision::query()->create([
            'profile_id' => (int) $topic->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'Do Not Overwrite'],
            'note' => 'seed',
            'created_at' => now(),
        ]);

        $this->artisan('topics:import-local-baseline', [
            '--locale' => ['en'],
            '--topic' => ['mbti'],
            '--source-dir' => 'tests/Fixtures/topic_baseline',
        ])->assertExitCode(0);

        $this->assertSame('Do Not Overwrite', (string) $topic->fresh()->title);
        $this->assertSame(1, TopicProfileRevision::query()->where('profile_id', (int) $topic->id)->count());
    }

    public function test_upsert_updates_existing_topics_and_only_creates_revision_when_changed(): void
    {
        $topic = TopicProfile::query()->create([
            'org_id' => 0,
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'en',
            'title' => 'Legacy MBTI',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subDay(),
            'schema_version' => 'v1',
            'sort_order' => 99,
        ]);

        TopicProfileSection::query()->create([
            'profile_id' => (int) $topic->id,
            'section_key' => 'overview',
            'title' => 'Legacy overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Legacy body',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        TopicProfileEntry::query()->create([
            'profile_id' => (int) $topic->id,
            'entry_type' => 'custom_link',
            'group_key' => 'related',
            'target_key' => 'legacy',
            'target_locale' => 'en',
            'title_override' => 'Legacy link',
            'target_url_override' => '/en/legacy',
            'sort_order' => 10,
            'is_featured' => false,
            'is_enabled' => true,
        ]);
        TopicProfileSeoMeta::query()->create([
            'profile_id' => (int) $topic->id,
            'seo_title' => 'Legacy title',
            'seo_description' => 'Legacy description',
        ]);
        TopicProfileRevision::query()->create([
            'profile_id' => (int) $topic->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'Legacy MBTI'],
            'note' => 'seed',
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('topics:import-local-baseline', [
            '--locale' => ['en'],
            '--topic' => ['mbti'],
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => 'tests/Fixtures/topic_baseline',
        ])->assertExitCode(0);

        $topic->refresh();

        $this->assertSame('MBTI Topic Cluster', $topic->title);
        $this->assertTrue((bool) $topic->is_indexable);
        $this->assertSame(10, (int) $topic->sort_order);
        $this->assertSame(3, TopicProfileSection::query()->where('profile_id', (int) $topic->id)->count());
        $this->assertSame(4, TopicProfileEntry::query()->where('profile_id', (int) $topic->id)->count());
        $this->assertSame(
            'MBTI topic hub',
            TopicProfileSeoMeta::query()->where('profile_id', (int) $topic->id)->firstOrFail()->seo_title,
        );
        $this->assertSame(
            2,
            TopicProfileRevision::query()->where('profile_id', (int) $topic->id)->max('revision_no'),
        );

        $this->artisan('topics:import-local-baseline', [
            '--locale' => ['en'],
            '--topic' => ['mbti'],
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => 'tests/Fixtures/topic_baseline',
        ])->assertExitCode(0);

        $this->assertSame(
            2,
            TopicProfileRevision::query()->where('profile_id', (int) $topic->id)->count(),
        );
    }

    public function test_locale_and_topic_filters_limit_import_scope(): void
    {
        $this->artisan('topics:import-local-baseline', [
            '--locale' => ['zh-CN'],
            '--topic' => ['big-five'],
            '--status' => 'draft',
            '--source-dir' => 'tests/Fixtures/topic_baseline',
        ])->assertExitCode(0);

        $this->assertSame(1, TopicProfile::query()->count());

        $profile = TopicProfile::query()->firstOrFail();
        $this->assertSame('big-five', $profile->topic_code);
        $this->assertSame('zh-CN', $profile->locale);
    }

    public function test_invalid_baseline_fails_with_non_zero_exit_code(): void
    {
        $sourceDir = base_path('tests/Fixtures/topic_baseline_invalid');

        File::deleteDirectory($sourceDir);
        File::ensureDirectoryExists($sourceDir);
        File::put($sourceDir.'/mbti.en.json', json_encode([
            'meta' => [
                'schema_version' => 'v1',
                'topic_code' => 'mbti',
                'locale' => 'en',
                'source' => 'test fixture',
                'generated_at' => '2026-03-08T00:00:00Z',
            ],
            'profile' => [
                'slug' => 'mbti',
                'title' => 'MBTI Topic Cluster',
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
            ],
            'sections' => [
                [
                    'section_key' => 'not-allowed',
                    'render_variant' => 'rich_text',
                    'body_md' => 'bad',
                    'sort_order' => 10,
                    'is_enabled' => true,
                ],
            ],
            'entries' => [],
            'seo_meta' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->artisan('topics:import-local-baseline', [
            '--source-dir' => 'tests/Fixtures/topic_baseline_invalid',
        ])
            ->assertExitCode(1);

        $this->assertSame(0, TopicProfile::query()->count());

        File::deleteDirectory($sourceDir);
    }

    public function test_unresolved_targets_do_not_block_import(): void
    {
        $this->artisan('topics:import-local-baseline', [
            '--locale' => ['en'],
            '--topic' => ['mbti'],
            '--status' => 'published',
            '--source-dir' => 'tests/Fixtures/topic_baseline',
        ])->assertExitCode(0);

        $topic = TopicProfile::query()->withoutGlobalScopes()
            ->where('topic_code', 'mbti')
            ->where('locale', 'en')
            ->firstOrFail();

        $entries = TopicProfileEntry::query()
            ->where('profile_id', (int) $topic->id)
            ->orderBy('sort_order')
            ->get();

        $this->assertCount(4, $entries);
        $this->assertSame('scale', $entries[0]->entry_type);
        $this->assertSame('personality_profile', $entries[1]->entry_type);
        $this->assertSame('custom_link', $entries[2]->entry_type);
    }

    private function purgeTopicTables(): void
    {
        TopicProfileRevision::query()->delete();
        TopicProfileSeoMeta::query()->delete();
        TopicProfileEntry::query()->delete();
        TopicProfileSection::query()->delete();
        TopicProfile::query()->withoutGlobalScopes()->delete();
    }
}
