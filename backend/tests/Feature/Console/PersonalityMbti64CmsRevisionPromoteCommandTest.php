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

    private const NEXT_BATCH_6_URLS = [
        'https://fermatmind.com/zh/personality/intp-a',
        'https://fermatmind.com/en/personality/intp-a',
        'https://fermatmind.com/zh/personality/esfp-a',
        'https://fermatmind.com/en/personality/esfp-a',
        'https://fermatmind.com/en/personality/enfj-a',
        'https://fermatmind.com/zh/personality/enfj-a',
    ];

    private const REMAINING_58_EXCLUDED_URLS = self::NEXT_BATCH_6_URLS;

    private const V8_5_V5_BILINGUAL_64_PACKAGE_PATH = 'docs/seo/personality/mbti64-zh32-en32-v8-5-v5-bilingual-package-2026-07-01.json';

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

    public function test_dry_run_supports_fixed_fresh_query_backed_five_subset_without_live_writes(): void
    {
        $this->seedProjectionTargets();
        [$packagePath, $qaPath] = $this->writeProjectionArtifacts($this->validProjectionPackage(), $this->validProjectionQa());
        $this->createProjectionDraftRevisions($packagePath, $qaPath);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--fresh-query-backed-5' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertSame('fresh_query_backed_5', $payload['contract']['subset']['mode']);
        $this->assertSame(5, $payload['row_count']);
        $this->assertSame(5, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(5, $payload['would_promote_count']);
        $this->assertSame([
            'https://fermatmind.com/en/personality/enfp-a',
            'https://fermatmind.com/zh/personality/istp-a',
            'https://fermatmind.com/en/personality/esfj-a',
            'https://fermatmind.com/zh/personality/esfj-a',
            'https://fermatmind.com/en/personality/intp-a',
        ], array_column($payload['rows'], 'url'));
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_dry_run_supports_fixed_next_batch_six_handoff_subset_without_live_writes(): void
    {
        $this->seedProjectionTargets();
        $packagePath = $this->writePackage($this->nextBatchSixPackage());
        $this->createNextBatchSixDraftRevisions($packagePath);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--next-batch-6' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertSame('next_batch_6', $payload['contract']['subset']['mode']);
        $this->assertFalse($payload['contract']['subset']['arbitrary_url_subset_allowed']);
        $this->assertSame(self::NEXT_BATCH_6_URLS, $payload['contract']['subset']['allowed_urls']);
        $this->assertSame(6, $payload['row_count']);
        $this->assertSame(6, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(6, $payload['would_promote_count']);
        $this->assertSame(self::NEXT_BATCH_6_URLS, array_column($payload['rows'], 'url'));
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_dry_run_supports_fixed_next_batch_six_v2_expansion_subset_without_live_writes(): void
    {
        $this->seedProjectionTargets();
        $packagePath = $this->writePackage($this->nextBatchSixV2Package());
        $this->createNextBatchSixDraftRevisions($packagePath);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--next-batch-6' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertSame('next_batch_6', $payload['contract']['subset']['mode']);
        $this->assertFalse($payload['contract']['subset']['arbitrary_url_subset_allowed']);
        $this->assertSame(self::NEXT_BATCH_6_URLS, $payload['contract']['subset']['allowed_urls']);
        $this->assertSame(6, $payload['row_count']);
        $this->assertSame(6, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(6, $payload['would_promote_count']);
        $this->assertSame(self::NEXT_BATCH_6_URLS, array_column($payload['rows'], 'url'));
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_dry_run_supports_fixed_remaining_fifty_eight_v2_expansion_subset_without_live_writes(): void
    {
        $this->seedProjectionTargets();
        $packagePath = $this->writePackage($this->remainingFiftyEightV2Package());
        $this->createNextBatchSixDraftRevisions($packagePath);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--remaining-58' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertSame('remaining_58', $payload['contract']['subset']['mode']);
        $this->assertFalse($payload['contract']['subset']['arbitrary_url_subset_allowed']);
        $this->assertSame($this->remainingFiftyEightUrls(), $payload['contract']['subset']['allowed_urls']);
        $this->assertSame(58, $payload['row_count']);
        $this->assertSame(58, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(58, $payload['would_promote_count']);
        $this->assertSame($this->remainingFiftyEightUrls(), array_column($payload['rows'], 'url'));
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_remaining_fifty_eight_v2_subset_derives_page_type_counts_when_summary_is_absent(): void
    {
        $this->seedProjectionTargets();
        $package = $this->remainingFiftyEightV2Package();
        unset($package['summary']);
        $packagePath = $this->writePackage($package);
        $this->createNextBatchSixDraftRevisions($packagePath);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--remaining-58' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertSame('remaining_58', $payload['contract']['subset']['mode']);
        $this->assertSame(58, $payload['contract']['row_count']);
        $this->assertSame(58, $payload['contract']['variant_row_count']);
        $this->assertSame(0, $payload['contract']['comparison_row_count']);
        $this->assertSame(58, $payload['row_count']);
        $this->assertSame(58, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(58, $payload['would_promote_count']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame([], $payload['errors']);
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_dry_run_supports_fixed_v8_5_v5_bilingual_sixty_four_subset_without_live_writes(): void
    {
        $this->seedProjectionTargets();
        $packagePath = base_path(self::V8_5_V5_BILINGUAL_64_PACKAGE_PATH);
        $this->createNextBatchSixDraftRevisions($packagePath);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => self::V8_5_V5_BILINGUAL_64_PACKAGE_PATH,
            '--dry-run' => true,
            '--v8-5-v5-bilingual-64' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertSame('v8_5_v5_bilingual_64', $payload['contract']['subset']['mode']);
        $this->assertFalse($payload['contract']['subset']['arbitrary_url_subset_allowed']);
        $this->assertSame($this->v85V5Bilingual64Urls(), $payload['contract']['subset']['allowed_urls']);
        $this->assertSame(64, $payload['row_count']);
        $this->assertSame(64, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(64, $payload['would_promote_count']);
        $this->assertSame($this->v85V5Bilingual64Urls(), array_column($payload['rows'], 'url'));
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

    public function test_write_promotes_only_fixed_fresh_query_backed_five_subset(): void
    {
        $targets = $this->seedProjectionTargets();
        [$packagePath, $qaPath] = $this->writeProjectionArtifacts($this->validProjectionPackage(), $this->validProjectionQa());
        $this->createProjectionDraftRevisions($packagePath, $qaPath);
        $options = $this->promoteWriteOptions($packagePath);
        $options['--fresh-query-backed-5'] = true;

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['write']);
        $this->assertSame('fresh_query_backed_5', $payload['contract']['subset']['mode']);
        $this->assertSame(5, $payload['row_count']);
        $this->assertSame(5, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(5, $payload['promoted_count']);
        $this->assertSame(5, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->where('section_key', 'mbti64_comparison_a_vs_t')->count());

        foreach (['en|ENFP-A', 'zh-CN|ISTP-A', 'en|ESFJ-A', 'zh-CN|ESFJ-A', 'en|INTP-A'] as $targetKey) {
            PersonalityProfileVariantSeoMeta::query()
                ->where('personality_profile_variant_id', (int) $targets[$targetKey]->id)
                ->firstOrFail();
        }

        $this->assertNull(PersonalityProfileVariantSeoMeta::query()
            ->where('personality_profile_variant_id', (int) $targets['en|ENTJ-A']->id)
            ->first());
        $this->assertNull(PersonalityProfileVariantSeoMeta::query()
            ->where('personality_profile_variant_id', (int) $targets['zh-CN|ENFP-A']->id)
            ->first());
    }

    public function test_write_promotes_only_fixed_next_batch_six_handoff_subset(): void
    {
        $targets = $this->seedProjectionTargets();
        $packagePath = $this->writePackage($this->nextBatchSixPackage());
        $this->createNextBatchSixDraftRevisions($packagePath);
        $options = $this->promoteWriteOptions($packagePath);
        $options['--next-batch-6'] = true;

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['write']);
        $this->assertSame('next_batch_6', $payload['contract']['subset']['mode']);
        $this->assertSame(6, $payload['row_count']);
        $this->assertSame(6, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(6, $payload['promoted_count']);
        $this->assertSame(6, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->where('section_key', 'mbti64_comparison_a_vs_t')->count());

        foreach (['zh-CN|INTP-A', 'en|INTP-A', 'zh-CN|ESFP-A', 'en|ESFP-A', 'en|ENFJ-A', 'zh-CN|ENFJ-A'] as $targetKey) {
            PersonalityProfileVariantSeoMeta::query()
                ->where('personality_profile_variant_id', (int) $targets[$targetKey]->id)
                ->firstOrFail();
        }

        $this->assertNull(PersonalityProfileVariantSeoMeta::query()
            ->where('personality_profile_variant_id', (int) $targets['en|ENTJ-A']->id)
            ->first());
        $this->assertNull(PersonalityProfileVariantSeoMeta::query()
            ->where('personality_profile_variant_id', (int) $targets['zh-CN|ENFP-A']->id)
            ->first());
    }

    public function test_write_promotes_only_fixed_next_batch_six_v2_expansion_subset(): void
    {
        $targets = $this->seedProjectionTargets();
        $packagePath = $this->writePackage($this->nextBatchSixV2Package());
        $this->createNextBatchSixDraftRevisions($packagePath);
        $options = $this->promoteWriteOptions($packagePath);
        $options['--next-batch-6'] = true;

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['write']);
        $this->assertSame('next_batch_6', $payload['contract']['subset']['mode']);
        $this->assertSame(6, $payload['row_count']);
        $this->assertSame(6, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(6, $payload['promoted_count']);
        $this->assertSame(6, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->where('section_key', 'mbti64_comparison_a_vs_t')->count());
        foreach ($this->expectedV2FirstClassSectionKeys() as $sectionKey) {
            $this->assertSame(6, PersonalityProfileVariantSection::query()->where('section_key', $sectionKey)->count());
        }
        $atSection = PersonalityProfileVariantSection::query()
            ->where('section_key', 'a_t_difference')
            ->firstOrFail();
        $this->assertStringContainsString('| Dimension | Assertive side | Turbulent side |', (string) $atSection->body_md);

        foreach (['zh-CN|INTP-A', 'en|INTP-A', 'zh-CN|ESFP-A', 'en|ESFP-A', 'en|ENFJ-A', 'zh-CN|ENFJ-A'] as $targetKey) {
            PersonalityProfileVariantSeoMeta::query()
                ->where('personality_profile_variant_id', (int) $targets[$targetKey]->id)
                ->firstOrFail();
        }

        $this->assertNull(PersonalityProfileVariantSeoMeta::query()
            ->where('personality_profile_variant_id', (int) $targets['en|ENTJ-A']->id)
            ->first());
        $this->assertNull(PersonalityProfileVariantSeoMeta::query()
            ->where('personality_profile_variant_id', (int) $targets['zh-CN|ENFP-A']->id)
            ->first());
    }

    public function test_write_promotes_only_fixed_remaining_fifty_eight_v2_expansion_subset(): void
    {
        $targets = $this->seedProjectionTargets();
        $packagePath = $this->writePackage($this->remainingFiftyEightV2Package());
        $this->createNextBatchSixDraftRevisions($packagePath);
        $options = $this->promoteWriteOptions($packagePath);
        $options['--remaining-58'] = true;

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['write']);
        $this->assertSame('remaining_58', $payload['contract']['subset']['mode']);
        $this->assertSame(58, $payload['row_count']);
        $this->assertSame(58, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(58, $payload['promoted_count']);
        $this->assertSame(58, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->where('section_key', 'mbti64_comparison_a_vs_t')->count());
        foreach ($this->expectedV2FirstClassSectionKeys() as $sectionKey) {
            $this->assertSame(58, PersonalityProfileVariantSection::query()->where('section_key', $sectionKey)->count());
        }
        $atSection = PersonalityProfileVariantSection::query()
            ->where('section_key', 'a_t_difference')
            ->firstOrFail();
        $this->assertStringContainsString('| Dimension | Assertive side | Turbulent side |', (string) $atSection->body_md);
        $this->assertSame('mbti64_competitor_gap_v2_first_class_section', $atSection->payload_json['source'] ?? null);

        foreach ($this->remainingFiftyEightUrls() as $url) {
            $key = $this->targetKeyForUrl($url);
            PersonalityProfileVariantSeoMeta::query()
                ->where('personality_profile_variant_id', (int) $targets[$key]->id)
                ->firstOrFail();
        }

        foreach (self::REMAINING_58_EXCLUDED_URLS as $url) {
            $key = $this->targetKeyForUrl($url);
            $this->assertNull(PersonalityProfileVariantSeoMeta::query()
                ->where('personality_profile_variant_id', (int) $targets[$key]->id)
                ->first());
        }
    }

    public function test_write_promotes_only_fixed_v8_5_v5_bilingual_sixty_four_subset(): void
    {
        $targets = $this->seedProjectionTargets();
        $packagePath = base_path(self::V8_5_V5_BILINGUAL_64_PACKAGE_PATH);
        $this->createNextBatchSixDraftRevisions($packagePath);
        $options = $this->promoteWriteOptions(self::V8_5_V5_BILINGUAL_64_PACKAGE_PATH);
        $options['--v8-5-v5-bilingual-64'] = true;
        $profileBefore = $this->profilePublishState($targets['zh-CN|INTJ']);
        $variantBefore = $this->variantPublishState($targets['zh-CN|INTJ-A']);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame('v8_5_v5_bilingual_64', $payload['contract']['subset']['mode']);
        $this->assertSame(64, $payload['row_count']);
        $this->assertSame(64, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(64, $payload['promoted_count']);
        $this->assertSame(64, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->where('section_key', 'mbti64_comparison_a_vs_t')->count());
        $this->assertSame($profileBefore, $this->profilePublishState($targets['zh-CN|INTJ']));
        $this->assertSame($variantBefore, $this->variantPublishState($targets['zh-CN|INTJ-A']));

        foreach ($this->expectedV85V5FirstClassSectionKeys() as $sectionKey) {
            $this->assertSame(64, PersonalityProfileVariantSection::query()->where('section_key', $sectionKey)->count(), $sectionKey);
        }
        foreach ($this->expectedV85V5RenderSectionKeys() as $sectionKey) {
            $this->assertSame(64, PersonalityProfileVariantSection::query()->where('section_key', $sectionKey)->count(), $sectionKey);
        }

        $seo = PersonalityProfileVariantSeoMeta::query()
            ->where('personality_profile_variant_id', (int) $targets['zh-CN|INTJ-A']->id)
            ->firstOrFail();
        $this->assertSame('INTJ-A 人格：战略思维、独立判断与长期执行', $seo->seo_title);
        $this->assertSame('/zh/personality/intj-a', $seo->canonical_url);
        $this->assertSame('index,follow', $seo->robots);

        $section = PersonalityProfileVariantSection::query()
            ->where('personality_profile_variant_id', (int) $targets['zh-CN|INTJ-A']->id)
            ->where('section_key', 'meaning')
            ->firstOrFail();
        $this->assertStringContainsString('INTJ-A', (string) $section->body_md);
        $this->assertSame('mbti64_v8_5_v5_first_class_section', $section->payload_json['source'] ?? null);
        $this->assertSame('core-reading', $section->payload_json['raw']['raw']['id'] ?? null);

        $overviewSection = PersonalityProfileVariantSection::query()
            ->where('personality_profile_variant_id', (int) $targets['zh-CN|INTJ-A']->id)
            ->where('section_key', 'v8_5_thirty_second_overview')
            ->firstOrFail();
        $this->assertSame('list', $overviewSection->render_variant);
        $this->assertSame('mbti64_v8_5_v5_first_class_render_section', $overviewSection->payload_json['source'] ?? null);
        $this->assertNotEmpty($overviewSection->payload_json['items'] ?? []);
        $this->assertStringContainsString('INTJ-A', (string) $overviewSection->body_md);

        $moduleSection = PersonalityProfileVariantSection::query()
            ->where('personality_profile_variant_id', (int) $targets['zh-CN|INTJ-A']->id)
            ->where('section_key', 'v8_5_module_01_core_reading')
            ->firstOrFail();
        $this->assertSame('rich_text', $moduleSection->render_variant);
        $this->assertSame('mbti64_v8_5_v5_first_class_render_section', $moduleSection->payload_json['source'] ?? null);
        $this->assertSame('core-reading', $moduleSection->payload_json['id'] ?? null);
        $this->assertStringContainsString('INTJ-A', (string) $moduleSection->body_md);
        $this->assertStringNotContainsString('Evidence boundary:', (string) $moduleSection->body_md);
        $this->assertArrayHasKey('evidence', $moduleSection->payload_json);
        $this->assertArrayHasKey('raw', $moduleSection->payload_json);

        $workDecisionSection = PersonalityProfileVariantSection::query()
            ->where('personality_profile_variant_id', (int) $targets['zh-CN|INTJ-A']->id)
            ->where('section_key', 'v8_5_work_decision')
            ->firstOrFail();
        $this->assertSame('cards', $workDecisionSection->render_variant);
        $this->assertSame('mbti64_v8_5_v5_first_class_render_section', $workDecisionSection->payload_json['source'] ?? null);
        $this->assertArrayHasKey('card', $workDecisionSection->payload_json);

        $detail = $this->getJson('/api/v0.5/personality/intj-a?locale=zh-CN')
            ->assertOk()
            ->json();
        $sectionKeys = array_values(array_filter(array_map(
            static fn (array $section): string => (string) ($section['section_key'] ?? ''),
            (array) ($detail['sections'] ?? [])
        )));
        $this->assertContains('v8_5_module_01_core_reading', $sectionKeys);
        foreach ($this->expectedV85V5FirstClassSectionKeys() as $legacySectionKey) {
            $this->assertNotContains($legacySectionKey, $sectionKeys, $legacySectionKey);
        }
        foreach ($this->expectedV85V5SuppressedPublicSectionKeys() as $legacySectionKey) {
            $this->assertNotContains($legacySectionKey, $sectionKeys, $legacySectionKey);
        }
        foreach ($sectionKeys as $sectionKey) {
            $this->assertFalse(str_starts_with($sectionKey, 'career.'), $sectionKey);
            $this->assertFalse(str_starts_with($sectionKey, 'growth.'), $sectionKey);
            $this->assertFalse(str_starts_with($sectionKey, 'relationships.'), $sectionKey);
        }

        $this->assertContains('related_content', $sectionKeys);
        $this->assertContains('mbti64_promotion_metadata', $sectionKeys);
        $this->assertNoExactDuplicateVisibleApiParagraphs((array) ($detail['sections'] ?? []));

        $apiModule = collect((array) ($detail['sections'] ?? []))
            ->firstWhere('section_key', 'v8_5_module_01_core_reading');
        $this->assertIsArray($apiModule);
        $this->assertStringNotContainsString('Evidence boundary:', (string) ($apiModule['body_md'] ?? ''));
        $this->assertStringNotContainsString('Evidence boundary:', (string) data_get($apiModule, 'payload_json.body', ''));
        $this->assertArrayHasKey('evidence', (array) data_get($apiModule, 'payload_json', []));
        $this->assertArrayHasKey('raw', (array) data_get($apiModule, 'payload_json', []));
    }

    public function test_public_detail_keeps_legacy_sections_when_v8_5_sections_are_absent(): void
    {
        $targets = $this->seedProjectionTargets();
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $targets['zh-CN|INTJ-A']->id,
            'section_key' => 'meaning',
            'render_variant' => 'rich_text',
            'body_md' => 'Legacy meaning remains visible without V8.5 sections.',
            'body_html' => null,
            'payload_json' => ['title' => 'Legacy meaning'],
            'sort_order' => 100,
            'is_enabled' => true,
        ]);

        $detail = $this->getJson('/api/v0.5/personality/intj-a?locale=zh-CN')
            ->assertOk()
            ->json();
        $sectionKeys = array_values(array_filter(array_map(
            static fn (array $section): string => (string) ($section['section_key'] ?? ''),
            (array) ($detail['sections'] ?? [])
        )));

        $this->assertContains('meaning', $sectionKeys);
    }

    public function test_visible_query_backed_three_subset_is_idempotent_after_write(): void
    {
        $this->seedProjectionTargets();
        [$packagePath, $qaPath] = $this->writeProjectionArtifacts($this->validProjectionPackage(), $this->validProjectionQa());
        $this->createProjectionDraftRevisions($packagePath, $qaPath);
        $options = $this->promoteWriteOptions($packagePath);
        $options['--visible-query-backed-3'] = true;

        $this->assertSame(0, Artisan::call('personality:mbti64-cms-revision-promote', $options), Artisan::output());
        $this->reorderOnePromotedVisibleThreeJsonPayload();

        $dryRunExit = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--visible-query-backed-3' => true,
            '--json' => true,
        ]);

        $dryRunPayload = $this->jsonOutput();
        $this->assertSame(0, $dryRunExit, Artisan::output());
        $this->assertTrue($dryRunPayload['ok']);
        $this->assertSame(3, $dryRunPayload['row_count']);
        $this->assertSame([], array_column(array_filter(
            $dryRunPayload['rows'],
            static fn (array $row): bool => ($row['live_matches_revision'] ?? false) !== true
        ), 'url'));
        $this->assertSame(0, $dryRunPayload['would_promote_count']);
        $this->assertSame(3, $dryRunPayload['skipped_existing_count']);
        $this->assertSame(array_fill(0, 3, 'would_skip_existing'), array_column($dryRunPayload['rows'], 'action'));
        $this->assertSame(array_fill(0, 3, true), array_column($dryRunPayload['rows'], 'live_matches_revision'));

        $secondWriteExit = Artisan::call('personality:mbti64-cms-revision-promote', $options);

        $secondWritePayload = $this->jsonOutput();
        $this->assertSame(0, $secondWriteExit, Artisan::output());
        $this->assertTrue($secondWritePayload['ok']);
        $this->assertFalse($secondWritePayload['writes_committed']);
        $this->assertSame(0, $secondWritePayload['promoted_count']);
        $this->assertSame(3, $secondWritePayload['skipped_existing_count']);
        $this->assertSame(array_fill(0, 3, 'skipped_existing'), array_column($secondWritePayload['rows'], 'action'));
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

    public function test_fresh_query_backed_five_subset_fails_closed_when_fixed_url_is_missing(): void
    {
        $this->seedProjectionTargets();
        $package = $this->validProjectionPackage();
        foreach ($package['recommendations'] as &$recommendation) {
            if (($recommendation['target_url'] ?? null) === 'https://fermatmind.com/en/personality/enfp-a') {
                $recommendation['target_url'] = 'https://fermatmind.com/en/personality/enfp-x';
            }
        }
        unset($recommendation);
        [$packagePath] = $this->writeProjectionArtifacts($package, $this->validProjectionQa());

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--fresh-query-backed-5' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertFalse($payload['ok']);
        $this->assertContains('fresh_query_backed_5_subset_incomplete', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_next_batch_six_subset_fails_closed_when_fixed_url_is_missing_or_extra_url_is_present(): void
    {
        $this->seedProjectionTargets();
        $package = $this->nextBatchSixPackage();
        $package['recommendations'][0]['target_url'] = 'https://fermatmind.com/en/personality/entj-a';
        $package['summary']['recommendation_count'] = 6;

        $packagePath = $this->writePackage($package);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--next-batch-6' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertFalse($payload['ok']);
        $this->assertContains('next_batch_6_url_set_mismatch', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertContains('next_batch_6_subset_incomplete', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_next_batch_six_v2_subset_fails_closed_when_fixed_url_is_missing_or_extra_url_is_present(): void
    {
        $this->seedProjectionTargets();
        $package = $this->nextBatchSixV2Package();
        $package['recommendations'][0]['target_url'] = 'https://fermatmind.com/en/personality/entj-a';
        $package['target_count'] = 6;

        $packagePath = $this->writePackage($package);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--next-batch-6' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertFalse($payload['ok']);
        $this->assertContains('next_batch_6_url_set_mismatch', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertContains('next_batch_6_subset_incomplete', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_remaining_fifty_eight_subset_fails_closed_when_fixed_url_is_missing_or_extra_url_is_present(): void
    {
        $this->seedProjectionTargets();
        $package = $this->remainingFiftyEightV2Package();
        $package['recommendations'][0]['target_url'] = 'https://fermatmind.com/en/personality/enfj-a';
        $package['target_count'] = 58;

        $packagePath = $this->writePackage($package);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--remaining-58' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertFalse($payload['ok']);
        $this->assertContains('remaining_58_url_set_mismatch', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertContains('remaining_58_subset_incomplete', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_v8_5_v5_bilingual_sixty_four_subset_fails_closed_when_fixed_url_is_missing_or_extra_url_is_present(): void
    {
        $this->seedProjectionTargets();
        $package = $this->v85V5Bilingual64Package();
        $package['recommendations'][0]['target_url'] = 'https://fermatmind.com/en/personality/intj-a-vs-intj-t';
        $package['target_count'] = 64;
        $packagePath = $this->writePackage($package);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--v8-5-v5-bilingual-64' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertFalse($payload['ok']);
        $this->assertContains('unsupported_v8_5_v5_bilingual_64_package_sha256', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertContains('v8_5_v5_bilingual_64_url_set_mismatch', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertContains('v8_5_v5_bilingual_64_subset_incomplete', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileVariantSeoMeta::query()->count());
        $this->assertSame(0, PersonalityProfileVariantSection::query()->count());
        $this->assertSame(0, PersonalityProfileSection::query()->count());
    }

    public function test_agent_projection_subset_modes_are_mutually_exclusive(): void
    {
        $this->seedProjectionTargets();
        [$packagePath, $qaPath] = $this->writeProjectionArtifacts($this->validProjectionPackage(), $this->validProjectionQa());
        $this->createProjectionDraftRevisions($packagePath, $qaPath);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-promote', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--visible-query-backed-3' => true,
            '--fresh-query-backed-5' => true,
            '--next-batch-6' => true,
            '--remaining-58' => true,
            '--v8-5-v5-bilingual-64' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode, Artisan::output());
        $this->assertFalse($payload['ok']);
        $this->assertContains('multiple_agent_projection_subset_modes', array_map(
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

    private function createNextBatchSixDraftRevisions(string $packagePath): void
    {
        $sourceSha256 = hash_file('sha256', $packagePath);
        $package = json_decode((string) File::get($packagePath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($package);

        foreach ((array) ($package['recommendations'] ?? []) as $recommendation) {
            $this->assertIsArray($recommendation);
            $url = (string) ($recommendation['target_url'] ?? '');
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            $this->assertMatchesRegularExpression('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-(?<variant>a|t)$#i', $path);
            preg_match('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-(?<variant>a|t)$#i', $path, $matches);
            $locale = $matches['prefix'] === 'zh' ? 'zh-CN' : 'en';
            $canonicalTypeCode = strtoupper((string) $matches['type']);
            $variantCode = strtoupper((string) $matches['variant']);
            $runtimeTypeCode = $canonicalTypeCode.'-'.$variantCode;

            $profile = PersonalityProfile::query()
                ->where('locale', $locale)
                ->where('canonical_type_code', $canonicalTypeCode)
                ->firstOrFail();
            $variant = PersonalityProfileVariant::query()
                ->where('personality_profile_id', (int) $profile->id)
                ->where('runtime_type_code', $runtimeTypeCode)
                ->firstOrFail();
            $fields = $this->firstClassFieldsForRecommendation($recommendation, $locale);
            $latestRevisionNo = (int) PersonalityProfileVariantRevision::query()
                ->where('personality_profile_variant_id', (int) $variant->id)
                ->max('revision_no');

            PersonalityProfileVariantRevision::query()->create([
                'personality_profile_variant_id' => (int) $variant->id,
                'revision_no' => $latestRevisionNo + 1,
                'snapshot_json' => [
                    'mbti64_agent_projection_draft_v1' => [
                        'source' => [
                            'artifact' => (string) ($package['artifact'] ?? 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-6-HANDOFF-01'),
                            'status' => (string) ($package['status'] ?? 'pass'),
                            'source_sha256' => $sourceSha256,
                            'qa_artifact' => 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-6-HANDOFF-QA-01',
                            'qa_source_sha256' => str_repeat('a', 64),
                            'qa_final_decision' => 'PASS_READY_FOR_APPROVAL_REVIEW',
                        ],
                        'identity' => [
                            'url' => $url,
                            'path' => $path,
                            'locale' => $locale,
                            'page_type' => 'variant',
                            'canonical_type_code' => $canonicalTypeCode,
                            'variant_code' => $variantCode,
                            'runtime_type_code' => $runtimeTypeCode,
                        ],
                        'first_class_draft_fields' => $fields,
                        'structured_metadata' => [
                            'qa_result' => [
                                'decision' => 'PASS_READY_FOR_APPROVAL_REVIEW',
                                'blockers' => [],
                            ],
                        ],
                        'safety_holds' => [
                            'draft_only' => true,
                            'publish_attempted' => false,
                            'index_attempted' => false,
                            'sitemap_llms_release_attempted' => false,
                            'search_release_attempted' => false,
                            'runtime_content_updated' => false,
                        ],
                        'raw_recommendation' => $recommendation,
                    ],
                ],
                'note' => 'next-batch-6 fixture draft: '.$path,
                'created_by_admin_user_id' => null,
                'created_at' => now(),
            ]);
        }
    }

    private function reorderOnePromotedVisibleThreeJsonPayload(): void
    {
        $seo = PersonalityProfileVariantSeoMeta::query()->firstOrFail();
        $seo->jsonld_overrides_json = $this->reverseAssociativeKeys((array) $seo->jsonld_overrides_json);
        $seo->save();

        $section = PersonalityProfileVariantSection::query()
            ->where('section_key', 'mbti64_promotion_metadata')
            ->firstOrFail();
        $section->payload_json = $this->reverseAssociativeKeys((array) $section->payload_json);
        $section->save();
    }

    /**
     * @param  array<string|int,mixed>  $value
     * @return array<string|int,mixed>
     */
    private function reverseAssociativeKeys(array $value): array
    {
        foreach ($value as $key => $nested) {
            $value[$key] = is_array($nested) ? $this->reverseAssociativeKeys($nested) : $nested;
        }

        return array_is_list($value) ? $value : array_reverse($value, true);
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
    private function nextBatchSixPackage(): array
    {
        $recommendations = [];
        foreach (self::NEXT_BATCH_6_URLS as $url) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            $recommendations[] = $this->projectionRecommendation($path);
        }

        return [
            'artifact' => 'PERSONALITY-AGENT-OPERATIONS-NEXT-BATCH-6-HANDOFF-01',
            'generated_at' => '2026-06-25T00:00:00Z',
            'status' => 'pass',
            'summary' => [
                'recommendation_count' => 6,
                'query_backed_count' => 3,
                'bilingual_paired_counterpart_count' => 3,
                'variant_pages' => 6,
                'comparison_pages' => 0,
            ],
            'recommendations' => $recommendations,
            'blockers' => [],
            'warnings' => [],
            'recommended_next_task' => 'PERSONALITY-AGENT-CMS-DRAFT-NEXT-BATCH-6-WRITE-01',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function nextBatchSixV2Package(): array
    {
        $recommendations = [];
        foreach (self::NEXT_BATCH_6_URLS as $url) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            $recommendation = $this->projectionRecommendation($path);
            $nested = is_array($recommendation['recommendations'] ?? null) ? $recommendation['recommendations'] : [];
            $recommendation['recommendations'] = [
                'title' => (string) ($nested['title']['recommended'] ?? ''),
                'description' => (string) ($nested['description']['recommended'] ?? ''),
                'h1' => (string) ($nested['h1']['recommended'] ?? ''),
                'quick_answer' => (string) ($nested['quick_answer']['recommended'] ?? ''),
                'sections' => $this->v2SectionsForFixture($path, 'V2'),
                'faq' => array_map(
                    static fn (int $index): array => [
                        'question' => 'V2 question '.$index.' for '.basename($path).'?',
                        'answer' => 'V2 safe answer '.$index.' for '.$path.'.',
                    ],
                    range(1, 9)
                ),
                'internal_links' => $nested['internal_links'] ?? [],
                'differentiation_notes' => ['V2 competitor-gap differentiation note for '.$path.'.'],
                'a_t_difference_module' => [
                    'summary' => 'A/T difference module for '.$path.'.',
                    'rows' => [
                        ['dimension' => 'stress response', 'a_side' => 'steadier recovery', 't_side' => 'more self-monitoring'],
                    ],
                ],
                'cognitive_function_mechanism' => 'Mechanism copy for '.$path.'.',
                'common_misreads' => ['Do not read this profile as a deterministic career or IQ label.'],
            ];
            $recommendation['qa_status'] = 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW';
            $recommendations[] = $recommendation;
        }

        return [
            'artifact' => 'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-V2-01',
            'generated_at' => '2026-06-27T00:00:00Z',
            'status' => 'pass_ready_for_editorial_review_and_approval_queue_repair',
            'target_count' => 6,
            'summary' => [
                'query_backed_count' => 3,
                'bilingual_paired_counterpart_count' => 3,
                'minimum_sections_per_page' => 8,
                'minimum_faq_per_page' => 9,
            ],
            'recommendations' => $recommendations,
            'blockers' => [],
            'warnings' => [],
            'recommended_next_task' => 'MBTI64-NEXT-BATCH-6-COMPETITOR-GAP-CONTENT-EXPANSION-V2-CMS-DRAFT-WRITE-01',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function remainingFiftyEightV2Package(): array
    {
        $recommendations = [];
        foreach ($this->remainingFiftyEightUrls() as $url) {
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            $recommendation = $this->projectionRecommendation($path);
            $nested = is_array($recommendation['recommendations'] ?? null) ? $recommendation['recommendations'] : [];
            $recommendation['recommendations'] = [
                'title' => (string) ($nested['title']['recommended'] ?? ''),
                'description' => (string) ($nested['description']['recommended'] ?? ''),
                'h1' => (string) ($nested['h1']['recommended'] ?? ''),
                'quick_answer' => (string) ($nested['quick_answer']['recommended'] ?? ''),
                'sections' => $this->v2SectionsForFixture($path, 'Remaining 58'),
                'faq' => array_map(
                    static fn (int $index): array => [
                        'question' => 'Remaining 58 question '.$index.' for '.basename($path).'?',
                        'answer' => 'Remaining 58 safe answer '.$index.' for '.$path.'.',
                    ],
                    range(1, 9)
                ),
                'internal_links' => $nested['internal_links'] ?? [],
                'differentiation_notes' => ['Remaining-58 competitor-gap differentiation note for '.$path.'.'],
                'a_t_difference_module' => [
                    'summary' => 'A/T difference module for '.$path.'.',
                    'rows' => [
                        ['dimension' => 'stress response', 'a_side' => 'steadier recovery', 't_side' => 'more self-monitoring'],
                    ],
                ],
                'cognitive_function_mechanism' => 'Mechanism copy for '.$path.'.',
                'common_misreads' => ['Do not read this profile as a deterministic career or IQ label.'],
            ];
            $recommendation['qa_status'] = 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW';
            $recommendations[] = $recommendation;
        }

        return [
            'artifact' => 'MBTI64-REMAINING-58-COMPETITOR-GAP-CONTENT-EXPANSION-V2-01',
            'generated_at' => '2026-06-28T00:00:00Z',
            'status' => 'pass',
            'final_decision' => 'PASS_READY_FOR_CONTENT_EXPANSION_REVIEW',
            'target_count' => 58,
            'summary' => [
                'target_count' => 58,
                'variant_pages' => 58,
                'comparison_pages' => 0,
                'completed_v2_exclusion_count' => 0,
                'section_count_min' => 8,
                'section_count_max' => 8,
                'faq_count_min' => 9,
                'faq_count_max' => 9,
            ],
            'recommendations' => $recommendations,
            'blockers' => [],
            'warnings' => [],
            'recommended_next_task' => 'MBTI64-REMAINING-58-COMPETITOR-GAP-CMS-DRAFT-DRY-RUN-01',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function v85V5Bilingual64Package(): array
    {
        $package = json_decode(
            (string) File::get(base_path(self::V8_5_V5_BILINGUAL_64_PACKAGE_PATH)),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $this->assertIsArray($package);

        return $package;
    }

    /**
     * @return list<string>
     */
    private function remainingFiftyEightUrls(): array
    {
        $urls = [];
        foreach (['en', 'zh'] as $prefix) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                foreach (['a', 't'] as $variant) {
                    $urls[] = 'https://fermatmind.com/'.$prefix.'/personality/'.strtolower($typeCode).'-'.$variant;
                }
            }
        }

        $remaining = array_values(array_filter(
            $urls,
            static fn (string $url): bool => ! in_array($url, self::REMAINING_58_EXCLUDED_URLS, true)
        ));
        sort($remaining);

        return $remaining;
    }

    /**
     * @return list<string>
     */
    private function v85V5Bilingual64Urls(): array
    {
        $urls = [];
        foreach (['en', 'zh'] as $prefix) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                foreach (['a', 't'] as $variant) {
                    $urls[] = 'https://fermatmind.com/'.$prefix.'/personality/'.strtolower($typeCode).'-'.$variant;
                }
            }
        }
        sort($urls);

        return $urls;
    }

    private function targetKeyForUrl(string $url): string
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $this->assertMatchesRegularExpression('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-(?<variant>a|t)$#i', $path);
        preg_match('#^/(?<prefix>en|zh)/personality/(?<type>[a-z]{4})-(?<variant>a|t)$#i', $path, $matches);
        $locale = $matches['prefix'] === 'zh' ? 'zh-CN' : 'en';
        $runtimeTypeCode = strtoupper((string) $matches['type']).'-'.strtoupper((string) $matches['variant']);

        return $locale.'|'.$runtimeTypeCode;
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
     * @param  array<string,mixed>  $recommendation
     * @return array<string,mixed>
     */
    private function firstClassFieldsForRecommendation(array $recommendation, string $locale): array
    {
        $recommendations = is_array($recommendation['recommendations'] ?? null)
            ? $recommendation['recommendations']
            : [];
        if ($recommendations === [] && (string) ($recommendation['package_version'] ?? '') === '') {
            return $this->v85V5FirstClassFieldsForRecommendation($recommendation, $locale);
        }

        return [
            'url' => (string) ($recommendation['target_url'] ?? ''),
            'locale' => $locale,
            'page_type' => 'variant',
            'seo' => [
                'title' => $this->recommendationText($recommendations['title'] ?? null),
                'description' => $this->recommendationText($recommendations['description'] ?? null),
                'h1' => $this->recommendationText($recommendations['h1'] ?? null),
            ],
            'content' => [
                'quick_answer' => $this->recommendationText($recommendations['quick_answer'] ?? null),
            ] + $this->sectionFieldsForRecommendation($recommendations),
            'faq' => array_values((array) ($recommendations['faq'] ?? [])),
            'internal_links' => array_values((array) ($recommendations['internal_links'] ?? [])),
            'differentiation_notes' => array_values((array) ($recommendations['differentiation_notes'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @return array<string,mixed>
     */
    private function v85V5FirstClassFieldsForRecommendation(array $recommendation, string $locale): array
    {
        $seo = is_array($recommendation['seo'] ?? null) ? $recommendation['seo'] : [];
        $geoSummary = is_array($recommendation['geo_summary'] ?? null) ? $recommendation['geo_summary'] : [];
        $aiBlock = is_array($geoSummary['ai_search_answer_block'] ?? null) ? $geoSummary['ai_search_answer_block'] : [];

        return [
            'url' => (string) ($recommendation['target_url'] ?? ''),
            'locale' => $locale,
            'page_type' => 'variant',
            'seo' => [
                'title' => (string) ($seo['title'] ?? ''),
                'description' => (string) ($seo['description'] ?? ''),
                'h1' => (string) ($recommendation['h1'] ?? ''),
            ],
            'content' => [
                'quick_answer' => (string) ($geoSummary['direct_answer'] ?? ($aiBlock['what_is'] ?? '')),
            ] + $this->v85V5SectionFieldsForRecommendation($recommendation),
            'faq' => array_values((array) ($recommendation['faq'] ?? [])),
            'internal_links' => array_values((array) ($recommendation['internal_links'] ?? [])),
            'differentiation_notes' => array_filter([
                (string) ($recommendation['core_tension'] ?? ''),
                (string) ((is_array($recommendation['reader_experience'] ?? null) ? $recommendation['reader_experience'] : [])['structure_model'] ?? ''),
            ]),
        ];
    }

    private function recommendationText(mixed $value): string
    {
        if (is_array($value)) {
            return (string) ($value['recommended'] ?? '');
        }

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string,mixed>  $recommendations
     * @return array<string,mixed>
     */
    private function sectionFieldsForRecommendation(array $recommendations): array
    {
        $sections = [];
        foreach (array_values((array) ($recommendations['sections'] ?? [])) as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $sectionKey = $this->firstClassSectionKey((string) ($section['key'] ?? ''), $index);
            if ($sectionKey === '') {
                continue;
            }

            $sections[$sectionKey] = $section;
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    private function expectedV2FirstClassSectionKeys(): array
    {
        return [
            'meaning',
            'a_t_difference',
            'core_traits',
            'careers_work_style',
            'relationships_communication',
            'strengths_blind_spots',
            'common_misreads',
            'similar_types',
        ];
    }

    /**
     * @return list<string>
     */
    private function expectedV85V5FirstClassSectionKeys(): array
    {
        return [
            'meaning',
            'a_t_difference',
            'core_traits',
            'careers_work_style',
            'relationships_communication',
            'strengths_blind_spots',
            'similar_types',
        ];
    }

    /**
     * @return list<string>
     */
    private function expectedV85V5SuppressedPublicSectionKeys(): array
    {
        return [
            'quick_answer',
            'common_misreads',
            'faq',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sections
     */
    private function assertNoExactDuplicateVisibleApiParagraphs(array $sections): void
    {
        $seen = [];
        foreach ($sections as $section) {
            $sectionKey = (string) ($section['section_key'] ?? '');
            $body = (string) ($section['body_md'] ?? '');
            foreach (preg_split('/\R{2,}/u', $body) ?: [] as $index => $paragraph) {
                $normalized = trim((string) preg_replace('/\s+/u', ' ', $paragraph));
                if (mb_strlen($normalized) < 18) {
                    continue;
                }

                $this->assertArrayNotHasKey(
                    $normalized,
                    $seen,
                    sprintf(
                        'Duplicate visible API paragraph in %s[%d]; first seen at %s.',
                        $sectionKey,
                        $index,
                        $seen[$normalized] ?? 'unknown'
                    )
                );
                $seen[$normalized] = sprintf('%s[%d]', $sectionKey, $index);
            }
        }
    }

    /**
     * @return list<string>
     */
    private function expectedV85V5RenderSectionKeys(): array
    {
        return [
            'v8_5_thirty_second_overview',
            'v8_5_ai_search_answer',
            'v8_5_strengths_watchouts',
            'v8_5_at_difference_scenarios',
            'v8_5_module_01_core_reading',
            'v8_5_module_02_judgment_style',
            'v8_5_module_03_agency_boundary',
            'v8_5_module_04_standards_drive',
            'v8_5_module_05_learning_revision',
            'v8_5_module_06_stress_blindspot',
            'v8_5_module_07_social_feedback',
            'v8_5_module_08_career_workflow',
            'v8_5_module_09_relationships',
            'v8_5_module_10_faq_boundary',
            'v8_5_work_decision',
            'v8_5_relationship_communication',
            'v8_5_pressure_growth',
            'v8_5_search_user_paths',
        ];
    }

    /**
     * @param  array<string,mixed>  $recommendation
     * @return array<string,mixed>
     */
    private function v85V5SectionFieldsForRecommendation(array $recommendation): array
    {
        $sections = [];
        foreach (array_values((array) ($recommendation['modules'] ?? [])) as $index => $section) {
            if (! is_array($section)) {
                continue;
            }

            $sectionKey = $this->v85V5FirstClassSectionKey((string) ($section['id'] ?? ($section['key'] ?? '')), $index);
            if ($sectionKey === '') {
                continue;
            }

            $body = $this->v85V5ModuleBody($section);
            if ($body === '') {
                continue;
            }

            $sections[$sectionKey] = [
                'title' => (string) ($section['title'] ?? ''),
                'body' => $body,
                'source_key' => (string) ($section['id'] ?? ($section['key'] ?? '')),
                'source' => 'mbti64_v8_5_v5_first_class_section',
                'raw' => $section,
            ];
        }

        return $sections;
    }

    /**
     * @param  array<string,mixed>  $section
     */
    private function v85V5ModuleBody(array $section): string
    {
        $parts = [];
        if (isset($section['insight']) && is_string($section['insight']) && trim($section['insight']) !== '') {
            $parts[] = trim($section['insight']);
        }

        $paragraphs = is_array($section['paragraphs'] ?? null) ? array_values((array) $section['paragraphs']) : [];
        $paragraphLines = [];
        foreach ($paragraphs as $paragraph) {
            if (is_string($paragraph) && trim($paragraph) !== '') {
                $paragraphLines[] = trim($paragraph);
            }
        }
        if ($paragraphLines !== []) {
            $parts[] = implode("\n\n", $paragraphLines);
        }

        return trim(implode("\n\n", $parts));
    }

    private function v85V5FirstClassSectionKey(string $sourceKey, int $position): string
    {
        $map = [
            'core_reading' => 'meaning',
            'logic_evidence_judgment' => 'core_traits',
            'independence_decision_control' => 'a_t_difference',
            'will_standards_long_termism' => 'strengths_blind_spots',
            'curiosity_revision' => 'core_traits',
            'emotional_blind_spots_pressure_feedback' => 'strengths_blind_spots',
            'social_friction_feedback_relationships' => 'relationships_communication',
            'work_career_scenarios' => 'careers_work_style',
            'relationships_intimacy' => 'relationships_communication',
            'faq_usage_boundary' => 'similar_types',
            'core_reading' => 'meaning',
            'rational_standard' => 'core_traits',
            'independence_control' => 'a_t_difference',
            'willpower_ambition' => 'strengths_blind_spots',
            'curiosity_revision' => 'core_traits',
            'emotional_blindspot' => 'strengths_blind_spots',
            'social_friction' => 'relationships_communication',
            'career_workflow' => 'careers_work_style',
            'relationships' => 'relationships_communication',
            'faq_boundary' => 'similar_types',
        ];
        $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]+/', '_', $sourceKey) ?? '', '_'));
        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        return $this->expectedV85V5FirstClassSectionKeys()[$position] ?? '';
    }

    private function firstClassSectionKey(string $sourceKey, int $position): string
    {
        $map = [
            'how_to_read' => 'meaning',
            'at_difference_table' => 'a_t_difference',
            'cognitive_function_mechanism' => 'core_traits',
            'work_scenario' => 'careers_work_style',
            'relationship_communication' => 'relationships_communication',
            'stress_growth' => 'strengths_blind_spots',
            'common_misreads' => 'common_misreads',
            'how_to_use_not_use' => 'similar_types',
        ];
        $normalized = strtolower(trim(preg_replace('/[^a-zA-Z0-9_]+/', '_', $sourceKey) ?? '', '_'));
        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        return $this->expectedV2FirstClassSectionKeys()[$position] ?? '';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function v2SectionsForFixture(string $path, string $prefix): array
    {
        return [
            [
                'key' => 'how_to_read',
                'h2' => $prefix.' how to read '.basename($path),
                'body' => $prefix.' how-to-read body for '.$path.'.',
                'source' => 'mbti64_competitor_gap_v2_first_class_section',
            ],
            [
                'key' => 'at_difference_table',
                'h2' => $prefix.' A/T difference table for '.basename($path),
                'body' => $prefix.' A/T difference body for '.$path.'.',
                'source' => 'mbti64_competitor_gap_v2_first_class_section',
                'comparison_rows' => [
                    [
                        'dimension' => 'Feedback rhythm',
                        'assertive' => 'Uses feedback as a calibration input',
                        'turbulent' => 'Reviews feedback with more self-monitoring',
                    ],
                ],
            ],
            [
                'key' => 'cognitive_function_mechanism',
                'h2' => $prefix.' cognitive mechanism for '.basename($path),
                'body' => $prefix.' cognitive-function body for '.$path.'.',
                'source' => 'mbti64_competitor_gap_v2_first_class_section',
            ],
            [
                'key' => 'work_scenario',
                'h2' => $prefix.' work scenario for '.basename($path),
                'body' => $prefix.' work scenario body for '.$path.'.',
                'source' => 'mbti64_competitor_gap_v2_first_class_section',
            ],
            [
                'key' => 'relationship_communication',
                'h2' => $prefix.' relationship communication for '.basename($path),
                'body' => $prefix.' communication body for '.$path.'.',
                'source' => 'mbti64_competitor_gap_v2_first_class_section',
            ],
            [
                'key' => 'stress_growth',
                'h2' => $prefix.' stress and growth for '.basename($path),
                'body' => $prefix.' stress-growth body for '.$path.'.',
                'source' => 'mbti64_competitor_gap_v2_first_class_section',
            ],
            [
                'key' => 'common_misreads',
                'h2' => $prefix.' common misreads for '.basename($path),
                'body' => $prefix.' common misreads body for '.$path.'.',
                'source' => 'mbti64_competitor_gap_v2_first_class_section',
            ],
            [
                'key' => 'how_to_use_not_use',
                'h2' => $prefix.' how to use this page for '.basename($path),
                'body' => $prefix.' safe-use body for '.$path.'.',
                'source' => 'mbti64_competitor_gap_v2_first_class_section',
            ],
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
