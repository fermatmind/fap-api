<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityBaselineImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_database(): void
    {
        $this->artisan('personality:import-local-baseline', [
            '--dry-run' => true,
            '--source-dir' => 'tests/Fixtures/personality_baseline',
        ])
            ->expectsOutputToContain('profiles_found=4')
            ->expectsOutputToContain('variants_found=8')
            ->expectsOutputToContain('will_create=4')
            ->expectsOutputToContain('revisions_to_create=4')
            ->expectsOutputToContain('variant_will_create=8')
            ->expectsOutputToContain('variant_revisions_to_create=8')
            ->assertExitCode(0);

        $this->assertSame(0, PersonalityProfile::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
        $this->assertSame(0, PersonalityProfileSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariant::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_default_mode_creates_missing_profiles_sections_seo_meta_and_revision(): void
    {
        $this->artisan('personality:import-local-baseline', [
            '--locale' => ['en'],
            '--status' => 'draft',
            '--source-dir' => 'tests/Fixtures/personality_baseline',
        ])
            ->expectsOutputToContain('profiles_found=2')
            ->expectsOutputToContain('variants_found=4')
            ->expectsOutputToContain('will_create=2')
            ->expectsOutputToContain('variant_will_create=4')
            ->expectsOutputToContain('variant_revisions_to_create=4')
            ->assertExitCode(0);

        $this->assertSame(2, PersonalityProfile::query()->count());
        $this->assertSame(5, PersonalityProfileSection::query()->count());
        $this->assertSame(2, PersonalityProfileSeoMeta::query()->count());
        $this->assertSame(2, PersonalityProfileRevision::query()->count());
        $this->assertSame(4, PersonalityProfileVariant::query()->count());
        $this->assertSame(4, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(4, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(4, PersonalityProfileVariantRevision::query()->count());

        $profile = PersonalityProfile::query()
            ->withoutGlobalScopes()
            ->where('type_code', 'INTJ')
            ->where('locale', 'en')
            ->firstOrFail();

        $this->assertSame(0, (int) $profile->org_id);
        $this->assertSame('MBTI', $profile->scale_code);
        $this->assertSame('INTJ', $profile->canonical_type_code);
        $this->assertSame('draft', $profile->status);
        $this->assertNull($profile->published_at);
        $this->assertSame('Architect', $profile->type_name);
        $this->assertSame('Systems builder', $profile->nickname);

        $revision = PersonalityProfileRevision::query()
            ->where('profile_id', (int) $profile->id)
            ->orderBy('revision_no')
            ->firstOrFail();

        $this->assertSame(1, (int) $revision->revision_no);
        $this->assertSame('baseline import', $revision->note);

        $variant = PersonalityProfileVariant::query()
            ->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', 'INTJ-A')
            ->firstOrFail();

        $this->assertSame('INTJ', $variant->canonical_type_code);
        $this->assertSame('A', $variant->variant_code);
        $this->assertSame('Architect Assertive', $variant->type_name);
        $this->assertFalse((bool) $variant->is_published);
        $this->assertNull($variant->published_at);
    }

    public function test_default_mode_skips_existing_profiles_without_overwriting(): void
    {
        $profile = PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'Do Not Overwrite',
            'status' => 'draft',
            'is_public' => true,
            'is_indexable' => true,
            'schema_version' => 'v1',
        ]);

        PersonalityProfileRevision::query()->create([
            'profile_id' => (int) $profile->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'Do Not Overwrite'],
            'note' => 'seed',
            'created_at' => now(),
        ]);

        $this->artisan('personality:import-local-baseline', [
            '--locale' => ['en'],
            '--type' => ['INTJ'],
            '--source-dir' => 'tests/Fixtures/personality_baseline',
        ])
            ->expectsOutputToContain('profiles_found=1')
            ->expectsOutputToContain('variants_found=2')
            ->expectsOutputToContain('will_skip=1')
            ->expectsOutputToContain('variant_will_create=2')
            ->assertExitCode(0);

        $this->assertSame('Do Not Overwrite', (string) $profile->fresh()->title);
        $this->assertSame(1, PersonalityProfileRevision::query()->where('profile_id', (int) $profile->id)->count());
        $this->assertSame(0, PersonalityProfileVariant::query()->count());
    }

    public function test_upsert_updates_existing_profiles_and_only_creates_revision_when_changed(): void
    {
        $profile = PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => 'MBTI',
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'Legacy INTJ',
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => false,
            'published_at' => now()->subDay(),
            'schema_version' => 'v1',
        ]);

        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'core_snapshot',
            'title' => 'Legacy snapshot',
            'render_variant' => 'rich_text',
            'body_md' => 'Legacy body',
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        PersonalityProfileSection::query()->create([
            'profile_id' => (int) $profile->id,
            'section_key' => 'growth_edges',
            'title' => 'Growth edges',
            'render_variant' => 'bullets',
            'payload_json' => ['items' => [['title' => 'Old edge']]],
            'sort_order' => 30,
            'is_enabled' => true,
        ]);
        PersonalityProfileSeoMeta::query()->create([
            'profile_id' => (int) $profile->id,
            'seo_title' => 'Legacy title',
            'seo_description' => 'Legacy description',
        ]);
        PersonalityProfileRevision::query()->create([
            'profile_id' => (int) $profile->id,
            'revision_no' => 1,
            'snapshot_json' => ['title' => 'Legacy INTJ'],
            'note' => 'seed',
            'created_at' => now()->subDay(),
        ]);
        $variant = PersonalityProfileVariant::query()->create([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => 'INTJ',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'type_name' => 'Legacy Assertive',
            'nickname' => 'Legacy strategist',
            'rarity_text' => 'About 4%',
            'keywords_json' => ['legacy'],
            'hero_summary_md' => 'Legacy variant summary',
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => false,
        ]);
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'section_key' => 'overview',
            'render_variant' => 'rich_text',
            'body_md' => 'Legacy variant body',
            'sort_order' => 20,
            'is_enabled' => true,
        ]);
        PersonalityProfileVariantSeoMeta::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'seo_title' => 'Legacy variant seo',
        ]);
        PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'revision_no' => 1,
            'snapshot_json' => ['type_name' => 'Legacy Assertive'],
            'note' => 'seed',
            'created_at' => now()->subDay(),
        ]);

        $this->artisan('personality:import-local-baseline', [
            '--locale' => ['en'],
            '--type' => ['INTJ'],
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => 'tests/Fixtures/personality_baseline',
        ])
            ->expectsOutputToContain('profiles_found=1')
            ->expectsOutputToContain('variants_found=2')
            ->expectsOutputToContain('will_update=1')
            ->expectsOutputToContain('revisions_to_create=1')
            ->expectsOutputToContain('variant_will_create=1')
            ->expectsOutputToContain('variant_revisions_to_create=2')
            ->assertExitCode(0);

        $profile->refresh();

        $this->assertSame('INTJ - Architect', $profile->title);
        $this->assertSame('Architect', $profile->type_name);
        $this->assertTrue((bool) $profile->is_indexable);
        $this->assertNotNull($profile->published_at);
        $this->assertSame(
            ['career.summary', 'growth.strengths', 'overview'],
            PersonalityProfileSection::query()
                ->where('profile_id', (int) $profile->id)
                ->orderBy('section_key')
                ->pluck('section_key')
                ->all(),
        );
        $this->assertSame(
            'INTJ Personality Guide',
            PersonalityProfileSeoMeta::query()->where('profile_id', (int) $profile->id)->firstOrFail()->seo_title,
        );
        $this->assertSame(
            2,
            PersonalityProfileRevision::query()->where('profile_id', (int) $profile->id)->max('revision_no'),
        );

        $assertive = PersonalityProfileVariant::query()
            ->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', 'INTJ-A')
            ->firstOrFail();
        $turbulent = PersonalityProfileVariant::query()
            ->where('personality_profile_id', (int) $profile->id)
            ->where('runtime_type_code', 'INTJ-T')
            ->firstOrFail();

        $this->assertTrue((bool) $assertive->is_published);
        $this->assertSame('Architect Assertive', $assertive->type_name);
        $this->assertSame('Architect Turbulent', $turbulent->type_name);
        $this->assertFalse((bool) $turbulent->is_published);
        $this->assertSame(
            'INTJ-A Personality Guide',
            PersonalityProfileVariantSeoMeta::query()
                ->where('personality_profile_variant_id', (int) $assertive->id)
                ->firstOrFail()
                ->seo_title,
        );
        $this->assertSame(
            2,
            PersonalityProfileVariantRevision::query()
                ->where('personality_profile_variant_id', (int) $assertive->id)
                ->max('revision_no'),
        );

        $this->artisan('personality:import-local-baseline', [
            '--locale' => ['en'],
            '--type' => ['INTJ'],
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => 'tests/Fixtures/personality_baseline',
        ])
            ->expectsOutputToContain('will_skip=1')
            ->expectsOutputToContain('variant_will_skip=2')
            ->assertExitCode(0);

        $this->assertSame(
            2,
            PersonalityProfileRevision::query()->where('profile_id', (int) $profile->id)->count(),
        );
        $this->assertSame(3, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_locale_and_type_filters_limit_import_scope(): void
    {
        $this->artisan('personality:import-local-baseline', [
            '--locale' => ['zh-CN'],
            '--type' => ['INTJ'],
            '--status' => 'draft',
            '--source-dir' => 'tests/Fixtures/personality_baseline',
        ])
            ->expectsOutputToContain('profiles_found=1')
            ->expectsOutputToContain('variants_found=2')
            ->expectsOutputToContain('will_create=1')
            ->expectsOutputToContain('variant_will_create=2')
            ->assertExitCode(0);

        $this->assertSame(1, PersonalityProfile::query()->count());
        $this->assertSame(2, PersonalityProfileVariant::query()->count());

        $profile = PersonalityProfile::query()->firstOrFail();
        $this->assertSame('INTJ', $profile->type_code);
        $this->assertSame('zh-CN', $profile->locale);
    }

    public function test_invalid_baseline_fails_with_non_zero_exit_code(): void
    {
        $sourceDir = base_path('tests/Fixtures/personality_baseline_invalid');

        File::deleteDirectory($sourceDir);
        File::ensureDirectoryExists($sourceDir);
        File::put($sourceDir.'/mbti.en.json', json_encode([
            'meta' => [
                'schema_version' => 'v1',
                'scale_code' => 'MBTI',
                'locale' => 'en',
                'source' => 'test fixture',
                'generated_at' => '2026-03-08T00:00:00Z',
            ],
            'profiles' => [
                [
                    'type_code' => 'BADTYPE',
                    'slug' => 'badtype',
                    'title' => 'Bad',
                    'status' => 'published',
                    'is_public' => true,
                    'is_indexable' => true,
                    'sections' => [
                        [
                            'section_key' => 'core_snapshot',
                            'render_variant' => 'rich_text',
                            'body_md' => 'bad',
                            'payload_json' => null,
                            'sort_order' => 10,
                            'is_enabled' => true,
                        ],
                    ],
                    'seo_meta' => [],
                ],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        File::put($sourceDir.'/mbti.zh-CN.json', json_encode([
            'meta' => [
                'schema_version' => 'v2',
                'scale_code' => 'MBTI',
                'locale' => 'zh-CN',
                'source' => 'test fixture',
                'generated_at' => '2026-03-08T00:00:00Z',
            ],
            'profiles' => [],
            'variants' => [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        try {
            $this->artisan('personality:import-local-baseline', [
                '--source-dir' => 'tests/Fixtures/personality_baseline_invalid',
            ])
                ->expectsOutputToContain('invalid type_code')
                ->assertExitCode(1);
        } finally {
            File::deleteDirectory($sourceDir);
        }
    }
}
