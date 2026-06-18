<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbti64CmsRevisionDraftCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_plans_revision_drafts_without_writes(): void
    {
        $this->seedTargets();
        $packagePath = $this->writePackage($this->validPackage());

        $exitCode = Artisan::call('personality:mbti64-cms-revision-draft', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(8, $payload['row_count']);
        $this->assertSame(6, $payload['variant_row_count']);
        $this->assertSame(2, $payload['comparison_row_count']);
        $this->assertSame(8, $payload['would_create_revision_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_write_creates_exact_eight_draft_revisions_without_changing_live_records(): void
    {
        $targets = $this->seedTargets();
        $packagePath = $this->writePackage($this->validPackage());
        $profileBefore = $this->profileLiveState($targets['en|INTJ']);
        $variantBefore = $this->variantLiveState($targets['en|INTJ-A']);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-draft', $this->writeOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(8, $payload['created_revision_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertSame(2, PersonalityProfileRevision::query()->count());
        $this->assertSame(6, PersonalityProfileVariantRevision::query()->count());
        $this->assertSame($profileBefore, $this->profileLiveState($targets['en|INTJ']));
        $this->assertSame($variantBefore, $this->variantLiveState($targets['en|INTJ-A']));

        $comparison = PersonalityProfileRevision::query()
            ->where('profile_id', (int) $targets['en|INTJ']->id)
            ->firstOrFail();
        $variant = PersonalityProfileVariantRevision::query()
            ->where('personality_profile_variant_id', (int) $targets['en|INTJ-A']->id)
            ->firstOrFail();

        $this->assertArrayHasKey('mbti64_comparison_draft_v2_1', $comparison->snapshot_json);
        $this->assertArrayHasKey('mbti64_variant_content_package_v2_1', $variant->snapshot_json);
        $this->assertFalse((bool) $comparison->snapshot_json['mbti64_comparison_draft_v2_1']['safety_holds']['publish_attempted']);
        $this->assertFalse((bool) $variant->snapshot_json['mbti64_variant_content_package_v2_1']['safety_holds']['index_attempted']);
    }

    public function test_write_is_idempotent_for_same_source_hash(): void
    {
        $this->seedTargets();
        $packagePath = $this->writePackage($this->validPackage());

        $firstExit = Artisan::call('personality:mbti64-cms-revision-draft', $this->writeOptions($packagePath));
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('personality:mbti64-cms-revision-draft', $this->writeOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(8, $payload['skipped_existing_count']);
        $this->assertSame(2, PersonalityProfileRevision::query()->count());
        $this->assertSame(6, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_write_fails_closed_when_any_required_safety_flag_is_missing(): void
    {
        $this->seedTargets();
        $packagePath = $this->writePackage($this->validPackage());
        $options = $this->writeOptions($packagePath);
        unset($options['--no-llms']);

        $exitCode = Artisan::call('personality:mbti64-cms-revision-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('--no-llms is required', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_missing_target_blocks_entire_write_batch(): void
    {
        $this->seedTargets(skip: ['zh-CN|INTJ-T']);
        $packagePath = $this->writePackage($this->validPackage());

        $exitCode = Artisan::call('personality:mbti64-cms-revision-draft', $this->writeOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('target_not_found', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
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

        $exitCode = Artisan::call('personality:mbti64-cms-revision-draft', $this->writeOptions($packagePath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('forbidden_public_route_pattern_present', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    /**
     * @return array<string,PersonalityProfile|PersonalityProfileVariant>
     */
    private function seedTargets(array $skip = []): array
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
            if (in_array($locale.'|'.$runtimeTypeCode, $skip, true)) {
                continue;
            }

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
    private function profileLiveState(PersonalityProfile $profile): array
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
    private function variantLiveState(PersonalityProfileVariant $variant): array
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
                'seo_title' => 'Fixture title',
                'seo_description' => 'Fixture description.',
                'breadcrumb_title' => 'Fixture',
                'h1' => 'Fixture H1',
                'quick_answer_summary' => 'Fixture summary.',
            ],
            'content' => [
                'quick_answer' => 'Fixture answer.',
                'method_boundary' => ['h2' => 'Method boundary', 'body' => 'Fixture boundary.'],
            ],
            'faq' => [
                ['question' => 'Fixture question?', 'answer' => 'Fixture answer.'],
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
            'serp_ctr_package_v2' => ['seo_title' => 'Fixture title'],
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     */
    private function writePackage(array $package): string
    {
        $path = sys_get_temp_dir().'/mbti64-cms-draft-'.bin2hex(random_bytes(6)).'.json';
        File::put($path, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /**
     * @return array<string,mixed>
     */
    private function writeOptions(string $packagePath): array
    {
        return [
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
