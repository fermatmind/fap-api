<?php

declare(strict_types=1);

namespace Tests\Feature\TopicsCms;

use App\Models\TopicProfile;
use App\Models\TopicProfileEntry;
use App\Models\TopicProfileRevision;
use App\Models\TopicProfileSection;
use App\Models\TopicProfileSeoMeta;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TopicSchemaSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_relations_scopes_and_casts_work(): void
    {
        $profile = TopicProfile::query()->create([
            'org_id' => 0,
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'en',
            'title' => 'MBTI topic hub',
            'subtitle' => 'Structured guidance for MBTI readers',
            'excerpt' => 'A short topic overview.',
            'hero_kicker' => 'Topic hub',
            'hero_quote' => 'Find the next read with context.',
            'cover_image_url' => 'https://cdn.example.test/topic.png',
            'status' => TopicProfile::STATUS_PUBLISHED,
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 10,
            'published_at' => now(),
        ]);

        TopicProfileSection::query()->create([
            'profile_id' => $profile->id,
            'section_key' => 'overview',
            'title' => 'Overview',
            'render_variant' => 'rich_text',
            'body_md' => 'A topic overview.',
            'payload_json' => ['chips' => ['MBTI']],
            'sort_order' => 10,
            'is_enabled' => true,
        ]);

        TopicProfileSection::query()->create([
            'profile_id' => $profile->id,
            'section_key' => 'faq',
            'title' => 'FAQ',
            'render_variant' => 'faq',
            'payload_json' => [
                'items' => [
                    ['q' => 'What is MBTI?', 'a' => 'A personality framework.'],
                ],
            ],
            'sort_order' => 20,
            'is_enabled' => true,
        ]);

        $entryA = TopicProfileEntry::query()->create([
            'profile_id' => $profile->id,
            'entry_type' => 'custom_link',
            'group_key' => 'featured',
            'target_key' => 'mbti-intro',
            'target_locale' => 'en',
            'title_override' => 'Start here',
            'target_url_override' => 'https://example.test/en/topics/mbti',
            'payload_json' => ['style' => 'hero'],
            'sort_order' => 10,
            'is_featured' => true,
            'is_enabled' => true,
        ]);

        $entryB = TopicProfileEntry::query()->create([
            'profile_id' => $profile->id,
            'entry_type' => 'article',
            'group_key' => 'articles',
            'target_key' => 'mbti-personality-test-16-personality-types',
            'target_locale' => null,
            'excerpt_override' => 'Why MBTI matters in practice.',
            'payload_json' => ['rank' => 1],
            'sort_order' => 20,
            'is_featured' => false,
            'is_enabled' => true,
        ]);

        TopicProfileSeoMeta::query()->create([
            'profile_id' => $profile->id,
            'seo_title' => 'MBTI topic hub',
            'seo_description' => 'Understand MBTI and its adjacent content.',
            'jsonld_overrides_json' => ['@type' => 'CollectionPage'],
        ]);

        TopicProfileRevision::query()->create([
            'profile_id' => $profile->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'MBTI topic hub'],
            'note' => 'Initial draft',
            'created_at' => now()->subMinute(),
        ]);

        TopicProfileRevision::query()->create([
            'profile_id' => $profile->id,
            'revision_no' => 2,
            'snapshot_json' => ['title' => 'MBTI topic hub v2'],
            'note' => 'Publish update',
            'created_at' => now(),
        ]);

        $freshProfile = TopicProfile::query()->findOrFail($profile->id);

        $this->assertSame(
            ['overview', 'faq'],
            $freshProfile->sections()->pluck('section_key')->all()
        );
        $this->assertSame(
            [$entryA->id, $entryB->id],
            $freshProfile->entries()->pluck('id')->all()
        );
        $this->assertSame('MBTI topic hub', $freshProfile->seoMeta?->seo_title);
        $this->assertSame([2, 1], $freshProfile->revisions()->pluck('revision_no')->all());
        $this->assertSame(
            [$profile->id],
            TopicProfile::query()
                ->publishedPublic()
                ->indexable()
                ->forLocale('en')
                ->forSlug('MBTI')
                ->forTopicCode('MBTI')
                ->pluck('id')
                ->all()
        );
        $this->assertSame(['chips' => ['MBTI']], $freshProfile->sections()->firstOrFail()->payload_json);
        $this->assertSame(['@type' => 'CollectionPage'], $freshProfile->seoMeta?->jsonld_overrides_json);
        $this->assertTrue($entryA->fresh()->isCustomLink());
        $this->assertSame('en', $entryB->fresh()->effectiveTargetLocale('en'));
        $this->assertTrue($entryA->fresh()->is_featured);
        $this->assertTrue($entryB->fresh()->is_enabled);
    }

    public function test_unique_org_topic_code_locale_constraint_is_enforced(): void
    {
        TopicProfile::query()->create($this->profilePayload([
            'topic_code' => 'mbti',
            'slug' => 'mbti',
        ]));

        $this->expectException(QueryException::class);

        TopicProfile::query()->create($this->profilePayload([
            'topic_code' => 'mbti',
            'slug' => 'mbti-second',
        ]));
    }

    public function test_unique_org_slug_locale_constraint_is_enforced(): void
    {
        TopicProfile::query()->create($this->profilePayload([
            'topic_code' => 'big-five',
            'slug' => 'personality-basics',
        ]));

        $this->expectException(QueryException::class);

        TopicProfile::query()->create($this->profilePayload([
            'topic_code' => 'self-awareness',
            'slug' => 'personality-basics',
        ]));
    }

    public function test_deleting_profile_cascades_sections_entries_seo_meta_and_revisions(): void
    {
        $profile = TopicProfile::query()->create($this->profilePayload([
            'topic_code' => 'self-awareness',
            'slug' => 'self-awareness',
        ]));

        $section = TopicProfileSection::query()->create([
            'profile_id' => $profile->id,
            'section_key' => 'overview',
            'render_variant' => 'rich_text',
        ]);

        $entry = TopicProfileEntry::query()->create([
            'profile_id' => $profile->id,
            'entry_type' => 'article',
            'group_key' => 'articles',
            'target_key' => 'how-to-find-right-career-direction',
        ]);

        $seoMeta = TopicProfileSeoMeta::query()->create([
            'profile_id' => $profile->id,
            'seo_title' => 'Self awareness topic',
        ]);

        $revision = TopicProfileRevision::query()->create([
            'profile_id' => $profile->id,
            'revision_no' => 1,
            'snapshot_json' => ['slug' => 'self-awareness'],
            'created_at' => now(),
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('topic_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('topic_profile_sections', ['id' => $section->id]);
        $this->assertDatabaseMissing('topic_profile_entries', ['id' => $entry->id]);
        $this->assertDatabaseMissing('topic_profile_seo_meta', ['id' => $seoMeta->id]);
        $this->assertDatabaseMissing('topic_profile_revisions', ['id' => $revision->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'org_id' => 0,
            'topic_code' => 'mbti',
            'slug' => 'mbti',
            'locale' => 'en',
            'title' => 'Topic profile',
            'status' => TopicProfile::STATUS_DRAFT,
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'sort_order' => 0,
        ], $overrides);
    }
}
