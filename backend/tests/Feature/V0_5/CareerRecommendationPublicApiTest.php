<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use App\Models\CareerGuide;
use App\Models\CareerJob;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CareerRecommendationPublicApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_published_public_32_type_items_only(): void
    {
        $profile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'type_name' => 'Architect',
            'nickname' => 'Strategic Planner',
            'hero_summary_md' => 'Architect hero summary.',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);

        $this->createVariant($profile, [
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'type_name' => 'Architect',
            'nickname' => 'Strategic Planner',
            'hero_summary_md' => 'Assertive architect summary.',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariant($profile, [
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'type_name' => 'Architect',
            'nickname' => 'Reflective Architect',
            'hero_summary_md' => 'Turbulent architect summary.',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $hiddenProfile = $this->createProfile([
            'type_code' => 'ENTP',
            'slug' => 'entp',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($hiddenProfile, [
            'variant_code' => 'A',
            'runtime_type_code' => 'ENTP-A',
            'is_published' => false,
            'published_at' => null,
        ]);

        $response = $this->getJson('/api/v0.5/career-recommendations/mbti?locale=en');

        $response->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.runtime_type_code', 'INTJ-A')
            ->assertJsonPath('items.0.public_route_slug', 'intj-a')
            ->assertJsonPath('items.1.runtime_type_code', 'INTJ-T')
            ->assertJsonPath('items.1.public_route_slug', 'intj-t');
    }

    public function test_detail_returns_variant_career_authority_jobs_guides_and_seo_for_en_and_zh_cn(): void
    {
        $enProfile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Personality Type',
            'type_name' => 'Architect',
            'nickname' => 'Strategic Planner',
            'hero_summary_md' => 'Architects map complexity into long-term plans.',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $enVariant = $this->createVariant($enProfile, [
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'type_name' => 'Architect',
            'nickname' => 'Strategic Planner',
            'keywords_json' => ['strategy', 'systems'],
            'hero_summary_md' => 'Assertive INTJs move fast once the model is clear.',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSection($enVariant, 'career.summary', 'Career Summary', 'rich_text', [
            'body_md' => "Architects thrive in systems work.\n\nThey like long-range leverage.",
        ]);
        $this->createVariantSection($enVariant, 'career.advantages', 'Career Advantages', 'bullets', [
            'payload_json' => [
                'items' => [
                    ['title' => 'Systems thinking', 'body' => 'They connect moving parts quickly.'],
                ],
            ],
        ]);
        $this->createVariantSection($enVariant, 'career.weaknesses', 'Career Weaknesses', 'bullets', [
            'payload_json' => [
                'items' => [
                    ['title' => 'Patience', 'body' => 'They can move faster than group consensus.'],
                ],
            ],
        ]);
        $this->createVariantSection($enVariant, 'career.preferred_roles', 'Preferred Roles', 'preferred_role_list', [
            'payload_json' => [
                'intro' => 'Architects like strategic ownership.',
                'groups' => [
                    [
                        'group_title' => 'Strategy',
                        'description' => 'Roles with leverage and direction.',
                        'examples' => ['Product Strategy', 'Research Lead'],
                    ],
                ],
                'outro' => 'They usually want room to design systems.',
            ],
        ]);
        $this->createVariantSection($enVariant, 'career.upgrade_suggestions', 'Upgrade Suggestions', 'bullets', [
            'body_md' => "Work on translating complex reasoning.\n\nPractice stakeholder pacing.",
            'payload_json' => [
                'items' => [
                    ['title' => 'Communication', 'body' => 'Explain the why before the conclusion.'],
                ],
            ],
        ]);
        $this->createVariant($enProfile, [
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'type_name' => 'Architect',
            'is_published' => false,
            'published_at' => null,
        ]);

        $this->createJob([
            'job_code' => 'product-strategist',
            'slug' => 'product-strategist',
            'locale' => 'en',
            'title' => 'Product Strategist',
            'excerpt' => 'Shape product direction and operating decisions.',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'fit_personality_codes_json' => ['INTJ'],
            'mbti_primary_codes_json' => ['INTJ'],
            'mbti_secondary_codes_json' => [],
            'sort_order' => 10,
        ]);
        $this->createJob([
            'job_code' => 'business-analyst',
            'slug' => 'business-analyst',
            'locale' => 'en',
            'title' => 'Business Analyst',
            'excerpt' => 'Translate systems into decisions.',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'fit_personality_codes_json' => ['INTJ'],
            'mbti_primary_codes_json' => ['ENTJ'],
            'mbti_secondary_codes_json' => ['INTJ'],
            'sort_order' => 20,
        ]);
        $this->createJob([
            'job_code' => 'community-manager',
            'slug' => 'community-manager',
            'locale' => 'en',
            'title' => 'Community Manager',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'fit_personality_codes_json' => ['ENFP'],
            'mbti_primary_codes_json' => ['ENFP'],
            'mbti_secondary_codes_json' => [],
            'sort_order' => 30,
        ]);

        $guide = $this->createGuide([
            'guide_code' => 'systems-career-playbook',
            'slug' => 'systems-career-playbook',
            'locale' => 'en',
            'title' => 'Systems Career Playbook',
            'excerpt' => 'How to choose roles with leverage and clarity.',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $guide->relatedPersonalityProfiles()->attach($enProfile->id, ['sort_order' => 10]);
        $hiddenGuide = $this->createGuide([
            'guide_code' => 'hidden-guide',
            'slug' => 'hidden-guide',
            'locale' => 'en',
            'title' => 'Hidden Guide',
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => true,
        ]);
        $hiddenGuide->relatedPersonalityProfiles()->attach($enProfile->id, ['sort_order' => 10]);

        $zhProfile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'zh-CN',
            'title' => 'INTJ 人格类型',
            'type_name' => '建筑师',
            'nickname' => '战略规划者',
            'hero_summary_md' => 'INTJ 擅长把复杂问题拆成长期结构。',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $zhVariant = $this->createVariant($zhProfile, [
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'type_name' => '建筑师',
            'nickname' => '战略规划者',
            'keywords_json' => ['策略', '系统'],
            'hero_summary_md' => 'INTJ-A 更愿意直接把判断推进成行动。',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariantSection($zhVariant, 'career.summary', '职业总结', 'rich_text', [
            'body_md' => "他们适合系统型工作。\n\n也适合需要长期判断的岗位。",
        ]);
        $this->createVariantSection($zhVariant, 'career.advantages', '职业优势', 'bullets', [
            'payload_json' => [
                'items' => [
                    ['title' => '结构化', 'body' => '能快速找到关键约束。'],
                ],
            ],
        ]);
        $this->createVariantSection($zhVariant, 'career.weaknesses', '职业短板', 'bullets', [
            'payload_json' => [
                'items' => [
                    ['title' => '节奏', 'body' => '可能低估对齐成本。'],
                ],
            ],
        ]);
        $this->createVariantSection($zhVariant, 'career.preferred_roles', '偏好角色', 'preferred_role_list', [
            'payload_json' => [
                'intro' => '更适合有结构杠杆的角色。',
                'groups' => [
                    [
                        'group_title' => '战略类',
                        'description' => '强调推理和方向。',
                        'examples' => ['战略分析', '产品策略'],
                    ],
                ],
                'outro' => '最好有明确的目标与授权。',
            ],
        ]);
        $this->createVariantSection($zhVariant, 'career.upgrade_suggestions', '升级建议', 'bullets', [
            'body_md' => "训练对外解释能力。\n\n提前设计协作节奏。",
            'payload_json' => [
                'items' => [
                    ['title' => '表达', 'body' => '先说问题框架，再说答案。'],
                ],
            ],
        ]);
        $this->createJob([
            'job_code' => 'strategy-ops',
            'slug' => 'strategy-ops',
            'locale' => 'zh-CN',
            'title' => '战略运营',
            'excerpt' => '推动复杂项目落地。',
            'status' => CareerJob::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'fit_personality_codes_json' => ['INTJ'],
            'mbti_primary_codes_json' => ['INTJ'],
            'mbti_secondary_codes_json' => [],
            'sort_order' => 10,
        ]);
        $zhGuide = $this->createGuide([
            'guide_code' => 'career-structure-guide',
            'slug' => 'career-structure-guide',
            'locale' => 'zh-CN',
            'title' => '职业结构指南',
            'excerpt' => '如何把人格倾向转成岗位策略。',
            'status' => CareerGuide::STATUS_PUBLISHED,
            'is_public' => true,
            'published_at' => now()->subMinute(),
        ]);
        $zhGuide->relatedPersonalityProfiles()->attach($zhProfile->id, ['sort_order' => 10]);

        $enResponse = $this->getJson('/api/v0.5/career-recommendations/mbti/intj-a?locale=en');

        $enResponse->assertOk()
            ->assertJsonPath('runtime_type_code', 'INTJ-A')
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('seo_surface_v1.surface_type', 'career_recommendation_public_detail')
            ->assertJsonPath('seo_surface_v1.indexability_state', 'indexable')
            ->assertJsonPath('landing_surface_v1.landing_contract_version', 'landing.surface.v1')
            ->assertJsonPath('landing_surface_v1.entry_surface', 'career_recommendation_detail')
            ->assertJsonPath('landing_surface_v1.entry_type', 'career_recommendation')
            ->assertJsonPath('answer_surface_v1.answer_contract_version', 'answer.surface.v1')
            ->assertJsonPath('answer_surface_v1.answer_scope', 'public_indexable_detail')
            ->assertJsonPath('answer_surface_v1.surface_type', 'career_recommendation_public_detail')
            ->assertJsonPath('answer_surface_v1.summary_blocks.0.key', 'answer_first')
            ->assertJsonPath('answer_surface_v1.compare_blocks.0.key', 'authority_route')
            ->assertJsonPath('answer_surface_v1.scene_summary_blocks.0.key', 'career_direction')
            ->assertJsonPath('answer_surface_v1.scene_summary_blocks.0.href', '/en/career/recommendations/mbti/intj-a')
            ->assertJsonPath('answer_surface_v1.next_step_blocks.0.key', 'start_test')
            ->assertJsonPath('canonical_type_code', 'INTJ')
            ->assertJsonPath('public_route_slug', 'intj-a')
            ->assertJsonPath('graph_type_code', 'INTJ')
            ->assertJsonPath('career.summary.title', 'Career summary')
            ->assertJsonPath('career.summary.paragraphs.0', 'Architects thrive in systems work.')
            ->assertJsonPath('career.advantages.items.0.title', 'Systems thinking')
            ->assertJsonPath('career.advantages.items.0.description', 'They connect moving parts quickly.')
            ->assertJsonPath('career.preferred_roles.groups.0.group_title', 'Strategy')
            ->assertJsonPath('career.upgrade_suggestions.bullets.0.label', 'Communication')
            ->assertJsonPath('matched_jobs.0.slug', 'product-strategist')
            ->assertJsonPath('matched_jobs.0.fit_bucket', 'primary')
            ->assertJsonPath('matched_jobs.1.slug', 'business-analyst')
            ->assertJsonPath('matched_jobs.1.fit_bucket', 'secondary')
            ->assertJsonPath('matched_guides.0.slug', 'systems-career-playbook')
            ->assertJsonCount(1, 'matched_guides')
            ->assertJsonPath('seo.canonical', '/en/career/recommendations/mbti/intj-a')
            ->assertJsonPath('seo.alternates.en', '/en/career/recommendations/mbti/intj-a')
            ->assertJsonPath('seo.alternates.zh-CN', '/zh/career/recommendations/mbti/intj-a')
            ->assertJsonPath('_meta.public_route_type', '32-type')
            ->assertJsonPath('_meta.route_mode', 'public_variant')
            ->assertJsonPath('_meta.authority_source', 'career_recommendation_service.v1');

        $zhResponse = $this->getJson('/api/v0.5/career-recommendations/mbti/intj-a?locale=zh-CN');

        $zhResponse->assertOk()
            ->assertJsonPath('runtime_type_code', 'INTJ-A')
            ->assertJsonPath('seo_surface_v1.metadata_contract_version', 'seo.surface.v1')
            ->assertJsonPath('answer_surface_v1.answer_contract_version', 'answer.surface.v1')
            ->assertJsonPath('canonical_type_code', 'INTJ')
            ->assertJsonPath('public_route_slug', 'intj-a')
            ->assertJsonPath('graph_type_code', 'INTJ')
            ->assertJsonPath('matched_jobs.0.slug', 'strategy-ops')
            ->assertJsonPath('matched_jobs.0.fit_bucket', 'primary')
            ->assertJsonPath('matched_guides.0.slug', 'career-structure-guide')
            ->assertJsonPath('seo.canonical', '/zh/career/recommendations/mbti/intj-a')
            ->assertJsonPath('seo.alternates.en', '/en/career/recommendations/mbti/intj-a')
            ->assertJsonPath('seo.alternates.zh-CN', '/zh/career/recommendations/mbti/intj-a');
    }

    public function test_imported_local_baselines_make_representative_32_type_routes_non_empty(): void
    {
        $intjProfile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ Personality Type',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($intjProfile, [
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $enfpProfile = $this->createProfile([
            'type_code' => 'ENFP',
            'slug' => 'enfp',
            'locale' => 'en',
            'title' => 'ENFP Personality Type',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($enfpProfile, [
            'variant_code' => 'T',
            'runtime_type_code' => 'ENFP-T',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $istjProfile = $this->createProfile([
            'type_code' => 'ISTJ',
            'slug' => 'istj',
            'locale' => 'zh-CN',
            'title' => 'ISTJ 人格类型',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($istjProfile, [
            'variant_code' => 'A',
            'runtime_type_code' => 'ISTJ-A',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $esfpProfile = $this->createProfile([
            'type_code' => 'ESFP',
            'slug' => 'esfp',
            'locale' => 'zh-CN',
            'title' => 'ESFP 人格类型',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($esfpProfile, [
            'variant_code' => 'T',
            'runtime_type_code' => 'ESFP-T',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->artisan('career-jobs:import-local-baseline', [
            '--locale' => ['en', 'zh-CN'],
            '--upsert' => true,
            '--status' => 'published',
        ])->assertExitCode(0);

        $this->artisan('articles:import-local-baseline', [
            '--locale' => ['en', 'zh-CN'],
            '--upsert' => true,
            '--status' => 'published',
            '--source-dir' => '../content_baselines/articles',
        ])->assertExitCode(0);

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['en'],
            '--guide' => [
                'intj-career-playbook',
                'enfp-career-playbook',
            ],
            '--upsert' => true,
            '--status' => 'published',
        ])->assertExitCode(0);

        $this->artisan('career-guides:import-local-baseline', [
            '--locale' => ['zh-CN'],
            '--guide' => [
                'istj-career-playbook',
                'esfp-career-playbook',
            ],
            '--upsert' => true,
            '--status' => 'published',
        ])->assertExitCode(0);

        $intjA = $this->getJson('/api/v0.5/career-recommendations/mbti/intj-a?locale=en');
        $intjA->assertOk()
            ->assertJsonPath('public_route_slug', 'intj-a')
            ->assertJsonPath('graph_type_code', 'INTJ');
        $this->assertGreaterThan(0, count((array) $intjA->json('matched_jobs')));
        $this->assertGreaterThan(0, count((array) $intjA->json('matched_guides')));

        $enfpT = $this->getJson('/api/v0.5/career-recommendations/mbti/enfp-t?locale=en');
        $enfpT->assertOk()
            ->assertJsonPath('public_route_slug', 'enfp-t')
            ->assertJsonPath('graph_type_code', 'ENFP');
        $this->assertGreaterThan(0, count((array) $enfpT->json('matched_jobs')));
        $this->assertGreaterThan(0, count((array) $enfpT->json('matched_guides')));

        $istjA = $this->getJson('/api/v0.5/career-recommendations/mbti/istj-a?locale=zh-CN');
        $istjA->assertOk()
            ->assertJsonPath('public_route_slug', 'istj-a')
            ->assertJsonPath('graph_type_code', 'ISTJ');
        $this->assertGreaterThan(0, count((array) $istjA->json('matched_jobs')));
        $this->assertGreaterThan(0, count((array) $istjA->json('matched_guides')));

        $esfpT = $this->getJson('/api/v0.5/career-recommendations/mbti/esfp-t?locale=zh-CN');
        $esfpT->assertOk()
            ->assertJsonPath('public_route_slug', 'esfp-t')
            ->assertJsonPath('graph_type_code', 'ESFP');
        $this->assertGreaterThan(0, count((array) $esfpT->json('matched_jobs')));
        $this->assertGreaterThan(0, count((array) $esfpT->json('matched_guides')));

        $legacy = $this->getJson('/api/v0.5/career-recommendations/mbti/intj?locale=en');
        $legacy->assertOk()
            ->assertJsonPath('public_route_slug', 'intj-a')
            ->assertJsonPath('graph_type_code', 'INTJ');
        $this->assertGreaterThan(0, count((array) $legacy->json('matched_jobs')));
        $this->assertGreaterThan(0, count((array) $legacy->json('matched_guides')));
    }

    public function test_legacy_4_letter_route_resolves_to_default_published_variant_and_unpublished_alias_returns_not_found(): void
    {
        $intjProfile = $this->createProfile([
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($intjProfile, [
            'variant_code' => 'A',
            'runtime_type_code' => 'INTJ-A',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);
        $this->createVariant($intjProfile, [
            'variant_code' => 'T',
            'runtime_type_code' => 'INTJ-T',
            'is_published' => false,
            'published_at' => null,
        ]);

        $entpProfile = $this->createProfile([
            'type_code' => 'ENTP',
            'slug' => 'entp',
            'status' => 'published',
            'is_public' => true,
            'published_at' => now()->subMinute(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $this->createVariant($entpProfile, [
            'variant_code' => 'T',
            'runtime_type_code' => 'ENTP-T',
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v0.5/career-recommendations/mbti/intj?locale=en')
            ->assertOk()
            ->assertJsonPath('public_route_slug', 'intj-a')
            ->assertJsonPath('runtime_type_code', 'INTJ-A')
            ->assertJsonPath('graph_type_code', 'INTJ');

        $this->getJson('/api/v0.5/career-recommendations/mbti/entp?locale=en')
            ->assertOk()
            ->assertJsonPath('public_route_slug', 'entp-t')
            ->assertJsonPath('runtime_type_code', 'ENTP-T')
            ->assertJsonPath('graph_type_code', 'ENTP');

        $this->getJson('/api/v0.5/career-recommendations/mbti/intj-t?locale=en')
            ->assertStatus(404)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error_code', 'NOT_FOUND');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createProfile(array $overrides = []): PersonalityProfile
    {
        /** @var PersonalityProfile */
        return PersonalityProfile::query()->create(array_merge([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTJ',
            'slug' => 'intj',
            'locale' => 'en',
            'title' => 'INTJ',
            'type_name' => 'Architect',
            'nickname' => 'Strategic Planner',
            'subtitle' => 'Strategic and future-oriented',
            'excerpt' => 'INTJs tend to value competence, systems, and long-range thinking.',
            'hero_summary_md' => 'INTJ summary',
            'status' => 'draft',
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVariant(PersonalityProfile $profile, array $overrides = []): PersonalityProfileVariant
    {
        /** @var PersonalityProfileVariant */
        return PersonalityProfileVariant::query()->create(array_merge([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => (string) $profile->type_code,
            'variant_code' => 'A',
            'runtime_type_code' => ((string) $profile->type_code).'-A',
            'type_name' => 'Variant type',
            'nickname' => 'Variant nickname',
            'rarity_text' => 'About 3%',
            'keywords_json' => ['variant'],
            'hero_summary_md' => 'Variant summary',
            'hero_summary_html' => null,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now()->subMinute(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createVariantSection(
        PersonalityProfileVariant $variant,
        string $sectionKey,
        string $title,
        string $renderVariant,
        array $overrides = [],
    ): PersonalityProfileVariantSection {
        /** @var PersonalityProfileVariantSection */
        return PersonalityProfileVariantSection::query()->create(array_merge([
            'personality_profile_variant_id' => (int) $variant->id,
            'section_key' => $sectionKey,
            'title' => $title,
            'render_variant' => $renderVariant,
            'body_md' => null,
            'body_html' => null,
            'payload_json' => null,
            'sort_order' => 10,
            'is_enabled' => true,
        ], $overrides));
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
            'excerpt' => 'Career job excerpt.',
            'industry_slug' => 'technology',
            'industry_label' => 'Technology',
            'status' => CareerJob::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => 'v1',
            'sort_order' => 0,
            'fit_personality_codes_json' => ['INTJ'],
            'mbti_primary_codes_json' => ['INTJ'],
            'mbti_secondary_codes_json' => [],
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createGuide(array $overrides = []): CareerGuide
    {
        /** @var CareerGuide */
        return CareerGuide::query()->create(array_merge([
            'org_id' => 0,
            'guide_code' => 'career-guide',
            'slug' => 'career-guide',
            'locale' => 'en',
            'title' => 'Career guide',
            'excerpt' => 'Career guide excerpt.',
            'category_slug' => 'career-planning',
            'body_md' => '# Career guide',
            'body_html' => '<h1>Career guide</h1>',
            'related_industry_slugs_json' => ['technology'],
            'status' => CareerGuide::STATUS_DRAFT,
            'is_public' => false,
            'is_indexable' => true,
            'sort_order' => 0,
            'published_at' => null,
            'scheduled_at' => null,
            'schema_version' => 'v1',
        ], $overrides));
    }
}
