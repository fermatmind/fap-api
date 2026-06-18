<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbti64CmsRevisionPromoteCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_eight_latest_revisions_without_live_writes(): void
    {
        $this->seedTargets();
        $packagePath = $this->writePackage($this->validPackage());
        $this->createDraftRevisions($packagePath);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertSame(8, $payload['row_count']);
        $this->assertSame(6, $payload['variant_row_count']);
        $this->assertSame(2, $payload['comparison_row_count']);
        $this->assertSame(8, $payload['would_promote_count']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_write_promotes_eight_revisions_to_live_cms_fields_without_index_search_side_effects(): void
    {
        $targets = $this->seedTargets();
        $packagePath = $this->writePackage($this->validPackage());
        $this->createDraftRevisions($packagePath);
        $profileBefore = $this->profilePublishState($targets['en|INTJ']);
        $variantBefore = $this->variantPublishState($targets['en|INTJ-A']);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $this->promoteWriteOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertTrue($payload['content_promotion_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['queue_enqueue_attempted']);
        $this->assertSame(8, $payload['promoted_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertSame($profileBefore, $this->profilePublishState($targets['en|INTJ']));
        $this->assertSame($variantBefore, $this->variantPublishState($targets['en|INTJ-A']));

        $seo = PersonalityProfileVariantSeoMeta::query()
            ->where('personality_profile_variant_id', (int) $targets['en|INTJ-A']->id)
            ->firstOrFail();
        $this->assertSame('SEO title for /en/personality/intj-a', $seo->seo_title);
        $this->assertSame('/en/personality/intj-a', $seo->canonical_url);
        $this->assertSame('index,follow', $seo->robots);

        $section = PersonalityProfileVariantSection::query()
            ->where('personality_profile_variant_id', (int) $targets['en|INTJ-A']->id)
            ->where('section_key', 'quick_answer')
            ->firstOrFail();
        $this->assertSame('Quick answer for /en/personality/intj-a.', $section->body_md);

        $comparison = PersonalityProfileSection::query()
            ->where('profile_id', (int) $targets['en|INTJ']->id)
            ->where('section_key', 'mbti64_comparison_a_vs_t')
            ->firstOrFail();
        $this->assertSame('H1 for /en/personality/intj-a-vs-intj-t', $comparison->title);
        $this->assertSame('/en/personality/intj-a-vs-intj-t', $comparison->payload_json['url'] ?? null);
    }

    public function test_second_write_is_idempotent(): void
    {
        $this->seedTargets();
        $packagePath = $this->writePackage($this->validPackage());
        $this->createDraftRevisions($packagePath);

        $this->assertSame(0, Artisan::call('personality:mbti64-cms-revision-promote', $this->promoteWriteOptions($packagePath)));
        $secondExit = Artisan::call('personality:mbti64-cms-revision-promote', $this->promoteWriteOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['promoted_count']);
        $this->assertSame(8, $payload['skipped_existing_count']);
    }

    public function test_write_fails_closed_when_required_safety_flag_is_missing(): void
    {
        $this->seedTargets();
        $packagePath = $this->writePackage($this->validPackage());
        $this->createDraftRevisions($packagePath);
        $options = $this->promoteWriteOptions($packagePath);
        unset($options['--no-search-release']);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('--no-search-release is required', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_wrong_source_hash_fails_closed(): void
    {
        $this->seedTargets();
        $package = $this->validPackage();
        $packagePath = $this->writePackage($package);
        $this->createDraftRevisions($packagePath);
        $package['rows'][4]['seo']['seo_title'] = 'Different package hash';
        $changedPackagePath = $this->writePackage($package);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $this->promoteWriteOptions($changedPackagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('revision_not_found_for_source_sha256', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_missing_target_rolls_back_entire_batch(): void
    {
        $targets = $this->seedTargets();
        $packagePath = $this->writePackage($this->validPackage());
        $this->createDraftRevisions($packagePath);
        $targets['zh-CN|INTJ-T']->delete();

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $this->promoteWriteOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('target_not_found', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_non_latest_revision_fails_closed(): void
    {
        $targets = $this->seedTargets();
        $packagePath = $this->writePackage($this->validPackage());
        $this->createDraftRevisions($packagePath);

        PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => (int) $targets['en|INTJ-A']->id,
            'revision_no' => 2,
            'snapshot_json' => ['manual_revision' => true],
            'note' => 'newer manual draft',
            'created_by_admin_user_id' => null,
            'created_at' => now(),
        ]);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $this->promoteWriteOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('revision_not_latest_for_target', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_forbidden_private_route_pattern_is_rejected_before_writes(): void
    {
        $this->seedTargets();
        $package = $this->validPackage();
        $package['rows'][0]['internal_links'][] = [
            'href' => '/results/lookup',
            'anchor_text' => 'Forbidden',
            'role' => 'forbidden',
            'safe_public_route' => false,
        ];
        $packagePath = $this->writePackage($package);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $this->promoteWriteOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('forbidden_public_route_pattern_present', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    /**
     * @return array<string,PersonalityProfile|PersonalityProfileVariant>
     */
    private function seedTargets(): array
    {
        $targets = [];
        $profiles = [
            ['en', 'INTJ', 'Architect'],
            ['en', 'INTP', 'Logician'],
            ['zh-CN', 'ISTJ', '物流师'],
            ['zh-CN', 'INFP', '调停者'],
            ['zh-CN', 'INTJ', '建筑师'],
        ];

        foreach ($profiles as [$locale, $typeCode, $typeName]) {
            $profile = $this->createProfile($locale, $typeCode, $typeName);
            $targets[$locale.'|'.$typeCode] = $profile;
        }

        foreach ([
            ['zh-CN', 'ISTJ-A'],
            ['zh-CN', 'INFP-T'],
            ['en', 'INTJ-A'],
            ['en', 'INTJ-T'],
            ['zh-CN', 'INTJ-A'],
            ['zh-CN', 'INTJ-T'],
        ] as [$locale, $runtimeTypeCode]) {
            [$typeCode] = explode('-', $runtimeTypeCode);
            $targets[$locale.'|'.$runtimeTypeCode] = $this->createVariant($targets[$locale.'|'.$typeCode], $runtimeTypeCode);
        }

        return $targets;
    }

    private function createProfile(string $locale, string $typeCode, string $typeName): PersonalityProfile
    {
        return PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => $typeCode,
            'canonical_type_code' => $typeCode,
            'slug' => strtolower($typeCode),
            'locale' => $locale,
            'title' => $typeCode.' - '.$typeName,
            'type_name' => $typeName,
            'nickname' => $typeName,
            'rarity_text' => null,
            'keywords_json' => [],
            'subtitle' => null,
            'excerpt' => $locale === 'zh-CN' ? $typeName.' 类型摘要' : $typeName.' type summary',
            'hero_kicker' => $typeName,
            'hero_quote' => null,
            'hero_summary_md' => null,
            'hero_summary_html' => null,
            'hero_image_url' => null,
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'scheduled_at' => null,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
    }

    private function createVariant(PersonalityProfile $profile, string $runtimeTypeCode): PersonalityProfileVariant
    {
        [$typeCode, $variantCode] = explode('-', $runtimeTypeCode);

        return PersonalityProfileVariant::query()->create([
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => $typeCode,
            'variant_code' => $variantCode,
            'runtime_type_code' => $runtimeTypeCode,
            'type_name' => null,
            'nickname' => null,
            'rarity_text' => null,
            'keywords_json' => [],
            'hero_summary_md' => null,
            'hero_summary_html' => null,
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function profilePublishState(PersonalityProfile $profile): array
    {
        $fresh = $profile->fresh();
        $this->assertInstanceOf(PersonalityProfile::class, $fresh);

        return [
            'status' => (string) $fresh->status,
            'is_public' => (bool) $fresh->is_public,
            'is_indexable' => (bool) $fresh->is_indexable,
            'published_at' => $fresh->published_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function variantPublishState(PersonalityProfileVariant $variant): array
    {
        $fresh = $variant->fresh();
        $this->assertInstanceOf(PersonalityProfileVariant::class, $fresh);

        return [
            'is_published' => (bool) $fresh->is_published,
            'published_at' => $fresh->published_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validPackage(): array
    {
        $rows = [
            ['/en/personality/intj-a-vs-intj-t', 'en', 'comparison'],
            ['/zh/personality/istj-a', 'zh-CN', 'variant'],
            ['/en/personality/intp-a-vs-intp-t', 'en', 'comparison'],
            ['/zh/personality/infp-t', 'zh-CN', 'variant'],
            ['/en/personality/intj-a', 'en', 'variant'],
            ['/en/personality/intj-t', 'en', 'variant'],
            ['/zh/personality/intj-a', 'zh-CN', 'variant'],
            ['/zh/personality/intj-t', 'zh-CN', 'variant'],
        ];

        return [
            'artifact' => 'mbti64-content-package-pilot-v2.1',
            'version' => 'pilot-v2.1',
            'status' => 'draft_for_codex_qa',
            'rows' => array_map(fn (array $row): array => $this->row($row[0], $row[1], $row[2]), $rows),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function row(string $url, string $locale, string $pageType): array
    {
        return [
            'url' => $url,
            'locale' => $locale,
            'page_type' => $pageType,
            'primary_query' => 'fixture query',
            'secondary_queries' => ['fixture secondary'],
            'excluded_queries' => ['fixture excluded'],
            'target_intent' => 'fixture intent',
            'target_test_route' => str_starts_with($url, '/zh/')
                ? '/zh/tests/mbti-personality-test-16-personality-types'
                : '/en/tests/mbti-personality-test-16-personality-types',
            'canonical_target' => $url,
            'seo' => [
                'seo_title' => 'SEO title for '.$url,
                'seo_description' => 'SEO description for '.$url.'.',
                'breadcrumb_title' => 'Breadcrumb '.$url,
                'h1' => 'H1 for '.$url,
                'quick_answer_summary' => 'Quick answer summary for '.$url.'.',
            ],
            'content' => [
                'quick_answer' => 'Quick answer for '.$url.'.',
                'method_boundary' => ['h2' => 'Method boundary', 'body' => 'Boundary copy for '.$url.'.'],
            ],
            'faq' => [
                ['question' => 'Question for '.$url.'?', 'answer' => 'Answer for '.$url.'.'],
            ],
            'internal_links' => [
                [
                    'href' => str_starts_with($url, '/zh/') ? '/zh/personality' : '/en/personality',
                    'anchor_text' => 'Personality hub',
                    'role' => 'hub',
                    'safe_public_route' => true,
                ],
            ],
            'method_boundary' => 'Fixture method boundary.',
            'trademark_boundary' => 'Fixture trademark boundary.',
            'information_gain' => ['unique_user_value' => 'fixture'],
            'claim_risk_notes' => ['No deterministic claims.'],
            'qa_flags_for_codex' => ['Fixture QA.'],
            'route_safety' => ['forbidden_route_patterns_absent_from_internal_links' => true],
            'v2_optimization' => ['primary_improvement' => 'fixture'],
            'above_the_fold_module' => ['answer_card_title' => 'Fixture'],
            'status' => 'draft_for_codex_qa',
            'serp_ctr_package_v2' => ['seo_title' => 'SEO title for '.$url],
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     */
    private function writePackage(array $package): string
    {
        $path = sys_get_temp_dir().'/mbti64-cms-promote-'.bin2hex(random_bytes(6)).'.json';
        File::put($path, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function createDraftRevisions(string $packagePath): void
    {
        $exitCode = Artisan::call('personality:mbti64-cms-revision-draft', [
            '--package' => $packagePath,
            '--write' => true,
            '--json' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-index' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--operator-approved' => 'MBTI64-CMS-REVISION-DRAFT-01',
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    /**
     * @return array<string,mixed>
     */
    private function promoteWriteOptions(string $packagePath): array
    {
        return [
            '--package' => $packagePath,
            '--write' => true,
            '--json' => true,
            '--promote-live-content' => true,
            '--no-index' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--operator-approved' => 'MBTI64-BACKEND-PROMOTION-CONTRACT-01',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
