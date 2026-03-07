<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PersonalitySchemaSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_relations_and_scopes_work(): void
    {
        $profile = PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ personality profile',
            'subtitle' => 'Strategic and independent',
            'excerpt' => 'A compact personality summary.',
            'hero_kicker' => 'Architect',
            'hero_quote' => 'Systems first.',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
            'published_at' => now(),
        ]);

        PersonalityProfileSection::query()->create([
            'profile_id' => $profile->id,
            'section_key' => 'strengths',
            'title' => 'Strengths',
            'render_variant' => 'bullets',
            'body_md' => '- Strategic',
            'payload_json' => ['items' => ['Strategic']],
            'sort_order' => 20,
            'is_enabled' => true,
        ]);

        PersonalityProfileSection::query()->create([
            'profile_id' => $profile->id,
            'section_key' => 'hero',
            'title' => 'Hero',
            'render_variant' => 'callout',
            'body_md' => 'Architect archetype',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);

        PersonalityProfileSeoMeta::query()->create([
            'profile_id' => $profile->id,
            'seo_title' => 'INTJ personality',
            'seo_description' => 'INTJ summary',
            'jsonld_overrides_json' => ['@type' => 'WebPage'],
        ]);

        PersonalityProfileRevision::query()->create([
            'profile_id' => $profile->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'INTJ personality profile'],
            'note' => 'Initial draft',
            'created_at' => now()->subMinute(),
        ]);

        PersonalityProfileRevision::query()->create([
            'profile_id' => $profile->id,
            'revision_no' => 2,
            'snapshot_json' => ['title' => 'INTJ personality profile v2'],
            'note' => 'Publish update',
            'created_at' => now(),
        ]);

        $freshProfile = PersonalityProfile::query()->findOrFail($profile->id);

        $this->assertSame(
            ['hero', 'strengths'],
            $freshProfile->sections()->pluck('section_key')->all()
        );
        $this->assertSame('INTJ personality', $freshProfile->seoMeta?->seo_title);
        $this->assertSame(
            [2, 1],
            $freshProfile->revisions()->pluck('revision_no')->all()
        );
        $this->assertSame(
            [$profile->id],
            PersonalityProfile::query()->publishedPublic()->forLocale('en')->forType('intj')->pluck('id')->all()
        );
    }

    public function test_unique_org_scale_type_locale_constraint_is_enforced(): void
    {
        PersonalityProfile::query()->create($this->profilePayload([
            'type_code' => 'ENFP',
            'slug' => 'enfp',
        ]));

        $this->expectException(QueryException::class);

        PersonalityProfile::query()->create($this->profilePayload([
            'type_code' => 'ENFP',
            'slug' => 'enfp-second',
        ]));
    }

    public function test_unique_org_scale_slug_locale_constraint_is_enforced(): void
    {
        PersonalityProfile::query()->create($this->profilePayload([
            'type_code' => 'INFJ',
            'slug' => 'advocate',
        ]));

        $this->expectException(QueryException::class);

        PersonalityProfile::query()->create($this->profilePayload([
            'type_code' => 'INFP',
            'slug' => 'advocate',
        ]));
    }

    public function test_deleting_profile_cascades_sections_seo_meta_and_revisions(): void
    {
        $profile = PersonalityProfile::query()->create($this->profilePayload([
            'type_code' => 'ENTJ',
            'slug' => 'entj',
        ]));

        $section = PersonalityProfileSection::query()->create([
            'profile_id' => $profile->id,
            'section_key' => 'hero',
            'render_variant' => 'rich_text',
        ]);

        $seoMeta = PersonalityProfileSeoMeta::query()->create([
            'profile_id' => $profile->id,
            'seo_title' => 'ENTJ',
        ]);

        $revision = PersonalityProfileRevision::query()->create([
            'profile_id' => $profile->id,
            'revision_no' => 1,
            'snapshot_json' => ['slug' => 'entj'],
            'created_at' => now(),
        ]);

        $profile->delete();

        $this->assertDatabaseMissing('personality_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('personality_profile_sections', ['id' => $section->id]);
        $this->assertDatabaseMissing('personality_profile_seo_meta', ['id' => $seoMeta->id]);
        $this->assertDatabaseMissing('personality_profile_revisions', ['id' => $revision->id]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function profilePayload(array $overrides = []): array
    {
        return array_merge([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'Personality profile',
            'status' => 'draft',
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
        ], $overrides);
    }
}
