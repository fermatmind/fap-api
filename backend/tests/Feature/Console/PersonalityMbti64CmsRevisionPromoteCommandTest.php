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

    private const PILOT_PATHS = [
        '/en/personality/intj-a-vs-intj-t',
        '/zh/personality/istj-a',
        '/en/personality/intp-a-vs-intp-t',
        '/zh/personality/infp-t',
        '/en/personality/intj-a',
        '/en/personality/intj-t',
        '/zh/personality/intj-a',
        '/zh/personality/intj-t',
    ];

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

    public function test_dry_run_supports_eighty_eight_agent_projection_revisions_without_live_writes(): void
    {
        $this->seedProjectionTargets();
        [$packagePath, $qaPath] = $this->writeProjectionArtifacts($this->validProjectionPackage(), $this->validProjectionQa());
        $this->createProjectionDraftRevisions($packagePath, $qaPath);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertSame(88, $payload['row_count']);
        $this->assertSame(58, $payload['variant_row_count']);
        $this->assertSame(30, $payload['comparison_row_count']);
        $this->assertSame(88, $payload['would_promote_count']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame('mbti64_agent_projection_draft_v1', $payload['rows'][0]['snapshot_key']);
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_dry_run_supports_fixed_visible_query_backed_three_subset_without_live_writes(): void
    {
        $this->seedProjectionTargets();
        [$packagePath, $qaPath] = $this->writeProjectionArtifacts($this->validProjectionPackage(), $this->validProjectionQa());
        $this->createProjectionDraftRevisions($packagePath, $qaPath);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--visible-query-backed-3' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertSame('visible_query_backed_3', $payload['contract']['subset']['mode']);
        $this->assertSame(3, $payload['row_count']);
        $this->assertSame(3, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(3, $payload['would_promote_count']);
        $this->assertSame([
            'https://fermatmind.com/en/personality/enfj-a',
            'https://fermatmind.com/zh/personality/intp-a',
            'https://fermatmind.com/zh/personality/esfp-a',
        ], array_column($payload['rows'], 'url'));
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_write_promotes_eighty_eight_agent_projection_revisions_without_index_search_side_effects(): void
    {
        $targets = $this->seedProjectionTargets();
        [$packagePath, $qaPath] = $this->writeProjectionArtifacts($this->validProjectionPackage(), $this->validProjectionQa());
        $this->createProjectionDraftRevisions($packagePath, $qaPath);
        $profileBefore = $this->profilePublishState($targets['en|ENFJ']);
        $variantBefore = $this->variantPublishState($targets['en|ENFJ-A']);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $this->promoteWriteOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertTrue($payload['content_promotion_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['queue_enqueue_attempted']);
        $this->assertSame(88, $payload['promoted_count']);
        $this->assertSame(58, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(30, PersonalityProfileSection::query()->where('section_key', 'mbti64_comparison_a_vs_t')->count());
        $this->assertSame($profileBefore, $this->profilePublishState($targets['en|ENFJ']));
        $this->assertSame($variantBefore, $this->variantPublishState($targets['en|ENFJ-A']));

        $seo = PersonalityProfileVariantSeoMeta::query()
            ->where('personality_profile_variant_id', (int) $targets['en|ENFJ-A']->id)
            ->firstOrFail();
        $this->assertSame('ENFJ-A Meaning Guide | FermatMind', $seo->seo_title);
        $this->assertSame('/en/personality/enfj-a', $seo->canonical_url);

        $comparison = PersonalityProfileSection::query()
            ->where('profile_id', (int) $targets['en|ENFJ']->id)
            ->where('section_key', 'mbti64_comparison_a_vs_t')
            ->firstOrFail();
        $this->assertSame('ENFJ-A-VS-ENFJ-T Meaning', $comparison->title);
        $this->assertSame('/en/personality/enfj-a-vs-enfj-t', $comparison->payload_json['url'] ?? null);
    }

    public function test_write_promotes_only_fixed_visible_query_backed_three_subset(): void
    {
        $targets = $this->seedProjectionTargets();
        [$packagePath, $qaPath] = $this->writeProjectionArtifacts($this->validProjectionPackage(), $this->validProjectionQa());
        $this->createProjectionDraftRevisions($packagePath, $qaPath);
        $options = $this->promoteWriteOptions($packagePath);
        $options['--visible-query-backed-3'] = true;

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['write']);
        $this->assertSame('visible_query_backed_3', $payload['contract']['subset']['mode']);
        $this->assertSame(3, $payload['row_count']);
        $this->assertSame(3, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(3, $payload['promoted_count']);
        $this->assertSame(3, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->where('section_key', 'mbti64_comparison_a_vs_t')->count());

        foreach (['en|ENFJ-A', 'zh-CN|INTP-A', 'zh-CN|ESFP-A'] as $targetKey) {
            PersonalityProfileVariantSeoMeta::query()
                ->where('personality_profile_variant_id', (int) $targets[$targetKey]->id)
                ->firstOrFail();
        }

        $this->assertNull(PersonalityProfileVariantSeoMeta::query()
            ->where('personality_profile_variant_id', (int) $targets['en|ENTJ-A']->id)
            ->first());
    }

    public function test_visible_query_backed_three_subset_fails_closed_when_fixed_url_is_missing(): void
    {
        $this->seedProjectionTargets();
        $package = $this->validProjectionPackage();
        foreach ($package['recommendations'] as &$recommendation) {
            if (($recommendation['target_url'] ?? null) === 'https://fermatmind.com/en/personality/enfj-a') {
                $recommendation['target_url'] = 'https://fermatmind.com/en/personality/enfj-x';
            }
        }
        unset($recommendation);
        [$packagePath] = $this->writeProjectionArtifacts($package, $this->validProjectionQa());

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--visible-query-backed-3' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertFalse($payload['ok']);
        $this->assertContains('visible_query_backed_3_subset_incomplete', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_second_agent_projection_write_is_idempotent(): void
    {
        $this->seedProjectionTargets();
        [$packagePath, $qaPath] = $this->writeProjectionArtifacts($this->validProjectionPackage(), $this->validProjectionQa());
        $this->createProjectionDraftRevisions($packagePath, $qaPath);

        $this->assertSame(0, Artisan::call('personality:mbti64-cms-revision-promote', $this->promoteWriteOptions($packagePath)));
        $secondExit = Artisan::call('personality:mbti64-cms-revision-promote', $this->promoteWriteOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['promoted_count']);
        $this->assertSame(88, $payload['skipped_existing_count']);
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
     * @return array<string,PersonalityProfile|PersonalityProfileVariant>
     */
    private function seedProjectionTargets(): array
    {
        $targets = [];
        foreach (['en', 'zh-CN'] as $locale) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                $profile = $this->createProfile($locale, $typeCode, $typeCode.' fixture');
                $targets[$locale.'|'.$typeCode] = $profile;
                foreach (['A', 'T'] as $variantCode) {
                    $runtimeTypeCode = $typeCode.'-'.$variantCode;
                    $targets[$locale.'|'.$runtimeTypeCode] = $this->createVariant($profile, $runtimeTypeCode);
                }
            }
        }

        return $targets;
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

    private function createProjectionDraftRevisions(string $packagePath, string $qaPath): void
    {
        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--write' => true,
            '--json' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-index' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--operator-approved' => 'MBTI64-CMS-PROJECTION-DRAFT-88-01',
        ]);

        $this->assertSame(0, $exitCode, Artisan::output());
    }

    /**
     * @return array<string,mixed>
     */
    private function validProjectionPackage(): array
    {
        $recommendations = [];
        foreach ($this->projectionTargetPaths() as $path) {
            $recommendations[] = $this->projectionRecommendation($path);
        }

        return [
            'artifact' => 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-01',
            'version' => 'mbti64.agent_expansion_88_recommendations.v1',
            'generated_at' => '2026-06-21T00:00:00Z',
            'status' => 'pass_ready_for_qa_gates',
            'summary' => [
                'recommendation_count' => 88,
                'variant_pages' => 58,
                'comparison_pages' => 30,
                'pilot_urls_excluded' => 8,
            ],
            'recommendations' => $recommendations,
            'blockers' => [],
            'warnings' => ['GSC_EVIDENCE_PENDING'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validProjectionQa(): array
    {
        $pageResults = [];
        foreach ($this->projectionTargetPaths() as $path) {
            $pageResults[] = [
                'target_url' => 'https://fermatmind.com'.$path,
                'locale' => str_starts_with($path, '/zh/') ? 'zh-CN' : 'en',
                'page_type' => str_contains($path, '-a-vs-') ? 'comparison' : 'variant',
                'gates' => [
                    'schema_validation' => 'pass',
                    'trademark_claim_gate' => 'pass',
                    'claim_risk_gate' => 'pass',
                    'duplicate_template_gate' => 'pass',
                    'private_route_gate' => 'pass',
                    'result_page_leakage_gate' => 'pass',
                    'seo_projection_gate' => 'pass',
                    'bilingual_consistency_gate' => 'pass',
                ],
                'blockers' => [],
                'warnings' => ['GSC_EVIDENCE_PENDING'],
                'decision' => 'PASS_READY_FOR_CMS_DRAFT',
            ];
        }

        return [
            'artifact' => 'MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-QA-01',
            'generated_at' => '2026-06-21T00:00:00Z',
            'status' => 'pass_ready_for_cms_draft',
            'summary' => [
                'checked_recommendation_count' => 88,
                'pass_ready_for_cms_draft_count' => 88,
                'blocked_count' => 0,
            ],
            'page_results' => $pageResults,
            'blockers' => [],
            'warnings' => ['GSC_EVIDENCE_PENDING'],
            'final_decision' => 'PASS_READY_FOR_CMS_DRAFT',
        ];
    }

    /**
     * @return list<string>
     */
    private function projectionTargetPaths(): array
    {
        $paths = [];
        foreach (['en', 'zh'] as $prefix) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                $lower = strtolower($typeCode);
                $comparison = '/'.$prefix.'/personality/'.$lower.'-a-vs-'.$lower.'-t';
                if (! in_array($comparison, self::PILOT_PATHS, true)) {
                    $paths[] = $comparison;
                }

                foreach (['a', 't'] as $variant) {
                    $path = '/'.$prefix.'/personality/'.$lower.'-'.$variant;
                    if (! in_array($path, self::PILOT_PATHS, true)) {
                        $paths[] = $path;
                    }
                }
            }
        }

        return $paths;
    }

    /**
     * @return array<string,mixed>
     */
    private function projectionRecommendation(string $path): array
    {
        $locale = str_starts_with($path, '/zh/') ? 'zh-CN' : 'en';
        $slug = basename($path);
        $titlePrefix = strtoupper($slug);

        return [
            'recommendation_id' => 'fixture:'.$path,
            'target_url' => 'https://fermatmind.com'.$path,
            'framework' => 'mbti64',
            'locale' => $locale,
            'current_surface' => [
                'title' => 'Current '.$slug,
                'description' => 'Current description',
                'h1' => 'Current H1',
            ],
            'observed_signal' => ['evidence_state' => 'gsc_pending'],
            'reference_patterns_used' => [
                ['pattern_id' => 'fixture_reference', 'source_url' => 'https://fermatmind.com/en/personality/intj-a'],
            ],
            'source_inputs' => [
                'cms_or_api_snapshot' => 'fixture',
                'reference_pack' => 'fixture',
                'seo_signal' => 'GSC_EVIDENCE_PENDING',
            ],
            'recommendations' => [
                'title' => ['recommended' => $titlePrefix.' Meaning Guide | FermatMind'],
                'description' => ['recommended' => 'Safe public personality explanation for '.$slug.'.'],
                'h1' => ['recommended' => $titlePrefix.' Meaning'],
                'quick_answer' => ['recommended' => 'This public profile explains '.$slug.' for reflection, not diagnosis or deterministic decisions.'],
                'faq' => array_map(
                    static fn (int $index): array => [
                        'question' => 'Fixture question '.$index.'?',
                        'answer' => 'Fixture answer '.$index.' for safe public profile reading.',
                        'reason' => 'Fixture QA.',
                    ],
                    [1, 2, 3, 4, 5]
                ),
                'internal_links' => [
                    [
                        'href' => $locale === 'zh-CN' ? '/zh/personality' : '/en/personality',
                        'anchor_text' => $locale === 'zh-CN' ? '人格首页' : 'Personality hub',
                        'role' => 'hub',
                        'safe_public_route' => true,
                    ],
                    [
                        'href' => $locale === 'zh-CN'
                            ? '/zh/tests/mbti-personality-test-16-personality-types'
                            : '/en/tests/mbti-personality-test-16-personality-types',
                        'anchor_text' => $locale === 'zh-CN' ? 'MBTI 测试' : 'MBTI test',
                        'role' => 'related_test',
                        'safe_public_route' => true,
                    ],
                ],
                'differentiation_notes' => ['Fixture differentiation note.'],
            ],
            'qa_required' => [
                'schema_validation',
                'trademark_claim_gate',
                'claim_risk_gate',
                'duplicate_template_gate',
                'private_route_gate',
                'result_page_leakage_gate',
                'seo_projection_gate',
                'bilingual_consistency_gate',
            ],
            'blocked_reason' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $qa
     * @return array{0:string,1:string}
     */
    private function writeProjectionArtifacts(array $package, array $qa): array
    {
        $packagePath = sys_get_temp_dir().'/mbti64-cms-projection-promote-package-'.bin2hex(random_bytes(6)).'.json';
        $qaPath = sys_get_temp_dir().'/mbti64-cms-projection-promote-qa-'.bin2hex(random_bytes(6)).'.json';
        File::put($packagePath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::put($qaPath, json_encode($qa, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$packagePath, $qaPath];
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
