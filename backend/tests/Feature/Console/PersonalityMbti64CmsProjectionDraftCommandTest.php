<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileSeoMeta;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbti64CmsProjectionDraftCommandTest extends TestCase
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

    private const VISIBLE_QUERY_BACKED_3_URLS = [
        'https://fermatmind.com/en/personality/enfj-a',
        'https://fermatmind.com/zh/personality/intp-a',
        'https://fermatmind.com/zh/personality/esfp-a',
    ];

    public function test_dry_run_plans_eighty_eight_projection_drafts_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(88, $payload['row_count']);
        $this->assertSame(58, $payload['variant_row_count']);
        $this->assertSame(30, $payload['comparison_row_count']);
        $this->assertSame(88, $payload['would_create_revision_count']);
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_visible_query_backed_three_dry_run_plans_only_approved_urls_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--visible-query-backed-3' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['write']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertSame(3, $payload['row_count']);
        $this->assertSame(3, $payload['variant_row_count']);
        $this->assertSame(0, $payload['comparison_row_count']);
        $this->assertSame(3, $payload['would_create_revision_count']);
        $this->assertSame('visible_query_backed_3', $payload['subset']['mode']);
        $this->assertTrue($payload['subset']['enabled']);
        $this->assertTrue($payload['subset']['dry_run_only']);

        $plannedUrls = array_map(
            static fn (array $row): string => (string) ($row['url'] ?? ''),
            $payload['rows'] ?? []
        );
        sort($plannedUrls);
        $expectedUrls = self::VISIBLE_QUERY_BACKED_3_URLS;
        sort($expectedUrls);
        $this->assertSame($expectedUrls, $plannedUrls);
        $this->assertSame($expectedUrls, array_values($this->sortedStrings((array) $payload['subset']['allowed_urls'])));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_visible_query_backed_three_is_dry_run_only_and_rejects_write_mode(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        $options['--visible-query-backed-3'] = true;

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertStringContainsString('dry-run only', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_visible_query_backed_three_fails_closed_when_allowlisted_url_is_missing(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        foreach ($package['recommendations'] as &$recommendation) {
            if (($recommendation['target_url'] ?? null) === 'https://fermatmind.com/en/personality/enfj-a') {
                $recommendation['target_url'] = 'https://fermatmind.com/en/personality/entj-a';
                break;
            }
        }
        unset($recommendation);
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', [
            '--package' => $packagePath,
            '--qa' => $qaPath,
            '--dry-run' => true,
            '--visible-query-backed-3' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('visible_query_backed_subset_required_urls_missing', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_write_creates_eighty_eight_draft_revisions_without_changing_live_records(): void
    {
        $targets = $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $profileBefore = $this->profileLiveState($targets['en|ENFJ']);
        $variantBefore = $this->variantLiveState($targets['en|ENFJ-A']);
        $surfaceCountsBefore = $this->liveSurfaceCounts();

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertSame(88, $payload['created_revision_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertSame(30, PersonalityProfileRevision::query()->count());
        $this->assertSame(58, PersonalityProfileVariantRevision::query()->count());
        $this->assertSame($profileBefore, $this->profileLiveState($targets['en|ENFJ']));
        $this->assertSame($variantBefore, $this->variantLiveState($targets['en|ENFJ-A']));
        $this->assertSame($surfaceCountsBefore, $this->liveSurfaceCounts());

        $comparison = PersonalityProfileRevision::query()
            ->where('profile_id', (int) $targets['en|ENFJ']->id)
            ->firstOrFail();
        $variant = PersonalityProfileVariantRevision::query()
            ->where('personality_profile_variant_id', (int) $targets['en|ENFJ-A']->id)
            ->firstOrFail();

        $this->assertProjectionSnapshot($comparison->snapshot_json);
        $this->assertProjectionSnapshot($variant->snapshot_json);
    }

    public function test_second_write_is_idempotent_for_same_source_package_hash(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $firstExit = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_revision_count']);
        $this->assertSame(88, $payload['skipped_existing_count']);
        $this->assertSame(30, PersonalityProfileRevision::query()->count());
        $this->assertSame(58, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_changed_source_hash_creates_next_projection_revision_version(): void
    {
        $this->seedAllTargets();
        $package = $this->validPackage();
        $mutatedPackage = $package;
        $mutatedPackage['generated_at'] = '2026-06-21T00:00:01Z';
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $this->validQa());
        [$mutatedPackagePath] = $this->writeArtifacts($mutatedPackage, $this->validQa());

        $firstExit = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));
        $this->assertSame(0, $firstExit);

        $secondExit = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($mutatedPackagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(0, $secondExit);
        $this->assertTrue($payload['ok']);
        $this->assertSame(88, $payload['created_revision_count']);
        $this->assertSame(60, PersonalityProfileRevision::query()->count());
        $this->assertSame(116, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_missing_required_write_flag_fails_closed_without_writes(): void
    {
        $this->seedAllTargets();
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());
        $options = $this->writeOptions($packagePath, $qaPath);
        unset($options['--no-search-release']);

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $options);

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('--no-search-release is required', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_missing_target_blocks_entire_write_batch(): void
    {
        $this->seedAllTargets(skip: ['en|ENFJ-A']);
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));

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
        $this->seedAllTargets();
        $package = $this->validPackage();
        $package['recommendations'][0]['recommendations']['internal_links'][] = [
            'href' => '/results/lookup',
            'anchor_text' => 'Forbidden',
            'role' => 'forbidden',
            'safe_public_route' => false,
        ];
        [$packagePath, $qaPath] = $this->writeArtifacts($package, $this->validQa());

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));

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

    public function test_qa_not_pass_blocks_writes(): void
    {
        $this->seedAllTargets();
        $qa = $this->validQa();
        $qa['final_decision'] = 'NO_GO_BLOCKED_BY_QA';
        $qa['summary']['blocked_count'] = 1;
        $qa['blockers'] = ['fixture blocker'];
        [$packagePath, $qaPath] = $this->writeArtifacts($this->validPackage(), $qa);

        $exitCode = Artisan::call('personality:mbti64-cms-projection-draft', $this->writeOptions($packagePath, $qaPath));

        $payload = $this->jsonOutput();
        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('qa_not_ready_for_cms_draft', array_map(
            static fn (array $error): string => (string) ($error['code'] ?? ''),
            $payload['errors'] ?? []
        ));
        $this->assertSame(0, PersonalityProfileRevision::query()->count());
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    /**
     * @return array<string,PersonalityProfile|PersonalityProfileVariant>
     */
    private function seedAllTargets(array $skip = []): array
    {
        $targets = [];
        foreach (PersonalityProfile::SUPPORTED_LOCALES as $locale) {
            foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
                $profile = $this->createProfile($locale, $typeCode);
                $targets[$locale.'|'.$typeCode] = $profile;

                foreach (['A', 'T'] as $variantCode) {
                    $runtimeTypeCode = $typeCode.'-'.$variantCode;
                    if (in_array($locale.'|'.$runtimeTypeCode, $skip, true)) {
                        continue;
                    }

                    $targets[$locale.'|'.$runtimeTypeCode] = $this->createVariant($profile, $runtimeTypeCode);
                }
            }
        }

        return $targets;
    }

    private function createProfile(string $locale, string $typeCode): PersonalityProfile
    {
        return PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => $typeCode,
            'canonical_type_code' => $typeCode,
            'slug' => strtolower($typeCode),
            'locale' => $locale,
            'title' => $typeCode.' fixture',
            'type_name' => $typeCode,
            'nickname' => $typeCode,
            'rarity_text' => null,
            'keywords_json' => [],
            'subtitle' => null,
            'excerpt' => $locale === 'zh-CN' ? $typeCode.' 类型摘要' : $typeCode.' type summary',
            'hero_kicker' => $typeCode,
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
     * @return array<string,int>
     */
    private function liveSurfaceCounts(): array
    {
        return [
            'profile_sections' => PersonalityProfileSection::query()->count(),
            'variant_sections' => PersonalityProfileVariantSection::query()->count(),
            'profile_seo_meta' => PersonalityProfileSeoMeta::query()->count(),
            'variant_seo_meta' => PersonalityProfileVariantSeoMeta::query()->count(),
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function assertProjectionSnapshot(array $snapshot): void
    {
        $this->assertArrayHasKey('mbti64_agent_projection_draft_v1', $snapshot);
        $payload = $snapshot['mbti64_agent_projection_draft_v1'];
        $this->assertSame('MBTI64-PUBLIC-PROFILE-AGENT-EXPANSION-88-01', $payload['source']['artifact']);
        $this->assertSame('PASS_READY_FOR_CMS_DRAFT', $payload['source']['qa_final_decision']);
        $this->assertNotSame('', $payload['first_class_draft_fields']['seo']['title']);
        $this->assertNotSame('', $payload['first_class_draft_fields']['seo']['description']);
        $this->assertNotSame('', $payload['first_class_draft_fields']['seo']['h1']);
        $this->assertNotSame('', $payload['first_class_draft_fields']['content']['quick_answer']);
        $this->assertCount(5, $payload['first_class_draft_fields']['faq']);
        $this->assertNotEmpty($payload['first_class_draft_fields']['internal_links']);
        $this->assertFalse((bool) $payload['safety_holds']['publish_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['index_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['sitemap_llms_release_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['search_release_attempted']);
        $this->assertFalse((bool) $payload['safety_holds']['runtime_content_updated']);
    }

    /**
     * @return array<string,mixed>
     */
    private function validPackage(): array
    {
        $recommendations = [];
        foreach ($this->targetPaths() as $path) {
            $recommendations[] = $this->recommendation($path);
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
                'gsc_evidence_state' => 'GSC_EVIDENCE_PENDING',
                'qa_gate_required_count' => 8,
            ],
            'recommendations' => $recommendations,
            'blockers' => [],
            'warnings' => ['GSC_EVIDENCE_PENDING'],
            'recommended_next_task' => 'PERSONALITY-AGENT-QA-GATES-01',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validQa(): array
    {
        $pageResults = [];
        foreach ($this->targetPaths() as $path) {
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
            'input_artifact' => 'docs/seo/personality/mbti64-agent-expansion-88-recommendations-2026-06-21.json',
            'summary' => [
                'checked_recommendation_count' => 88,
                'pass_ready_for_cms_draft_count' => 88,
                'blocked_count' => 0,
                'warning_count' => 1,
            ],
            'page_results' => $pageResults,
            'blockers' => [],
            'warnings' => ['GSC_EVIDENCE_PENDING'],
            'final_decision' => 'PASS_READY_FOR_CMS_DRAFT',
            'recommended_next_task' => 'MBTI64-CMS-PROJECTION-DRAFT-88-01',
        ];
    }

    /**
     * @return list<string>
     */
    private function targetPaths(): array
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
    private function recommendation(string $path): array
    {
        $locale = str_starts_with($path, '/zh/') ? 'zh-CN' : 'en';
        $slug = basename($path);
        $pageType = str_contains($path, '-a-vs-') ? 'comparison' : 'variant';
        $title = $locale === 'zh-CN'
            ? strtoupper(substr($slug, 0, 4)).' 页面含义 | FermatMind'
            : strtoupper(substr($slug, 0, 4)).' Meaning Guide | FermatMind';

        return [
            'recommendation_id' => 'fixture:'.$path,
            'target_url' => 'https://fermatmind.com'.$path,
            'framework' => 'mbti64',
            'locale' => $locale,
            'source_inputs' => [
                'cms_or_api_snapshot' => 'fixture',
                'reference_pack' => 'fixture',
                'seo_signal' => 'GSC_EVIDENCE_PENDING',
            ],
            'current_surface' => [
                'title' => 'Current '.$slug,
                'description' => 'Current description',
                'h1' => 'Current H1',
                'quick_answer' => '',
                'faq_count' => 0,
                'internal_link_count' => 0,
            ],
            'observed_signal' => [
                'evidence_state' => 'gsc_pending',
                'impressions' => null,
                'clicks' => null,
            ],
            'reference_patterns_used' => [
                ['pattern_id' => 'fixture_reference', 'source_url' => 'https://fermatmind.com/en/personality/intj-a'],
            ],
            'recommendations' => [
                'title' => ['current' => 'Current '.$slug, 'recommended' => $title, 'reason' => 'Fixture reason.'],
                'description' => [
                    'current' => 'Current description',
                    'recommended' => $locale === 'zh-CN'
                        ? '理解这个公开人格页面的行为倾向、压力线索、沟通方式和自我核对，不作为诊断或职业决定。'
                        : 'Understand this public personality page through behavior patterns, stress cues, communication style and self-check prompts.',
                    'reason' => 'Fixture reason.',
                ],
                'h1' => ['current' => 'Current H1', 'recommended' => strtoupper($slug).' Meaning', 'reason' => 'Fixture reason.'],
                'quick_answer' => [
                    'current' => '',
                    'recommended' => $locale === 'zh-CN'
                        ? '这是一个公开人格解释页面，用于自我理解、沟通和学习反思，不是诊断、招聘筛选或确定性结论。'
                        : 'This is a public personality explanation page for self-understanding, communication and learning reflection, not a diagnosis or deterministic verdict.',
                    'reason' => 'Fixture reason.',
                ],
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
                'differentiation_notes' => [
                    'Fixture differentiation note one.',
                    'Fixture differentiation note two.',
                ],
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
    private function writeArtifacts(array $package, array $qa): array
    {
        $packagePath = sys_get_temp_dir().'/mbti64-cms-projection-package-'.bin2hex(random_bytes(6)).'.json';
        $qaPath = sys_get_temp_dir().'/mbti64-cms-projection-qa-'.bin2hex(random_bytes(6)).'.json';
        File::put($packagePath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::put($qaPath, json_encode($qa, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$packagePath, $qaPath];
    }

    /**
     * @return array<string,mixed>
     */
    private function writeOptions(string $packagePath, string $qaPath): array
    {
        return [
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

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private function sortedStrings(array $values): array
    {
        $values = array_values(array_map(static fn (string $value): string => $value, $values));
        sort($values);

        return $values;
    }
}
