<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\PersonalityBigFiveSeoDiscoverabilityRelease;
use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\BigFiveCmsPublishGateWriter;
use App\Services\Cms\BigFiveSeoDiscoverabilityReleaseWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityBigFiveSeoDiscoverabilityReleaseCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(PersonalityBigFiveSeoDiscoverabilityRelease::class));
    }

    public function test_command_is_registered_for_production_artisan_path(): void
    {
        $this->assertArrayHasKey('personality:big-five-seo-discoverability-release', Artisan::all());
    }

    public function test_dry_run_plans_exact_twenty_authorized_rows_without_writes(): void
    {
        [$packagePath, $sha256] = $this->writePackage($this->validFortyTwoRowPackage());
        $this->seedContentReadyRows($sha256);

        $exitCode = Artisan::call('personality:big-five-seo-discoverability-release', $this->commandOptions($packagePath, $sha256, ['--dry-run' => true]));
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertTrue($payload['dry_run']);
        $this->assertFalse($payload['release']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(20, $payload['row_count']);
        $this->assertSame(20, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('sitemap_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('llms_eligible', true)->count());
    }

    public function test_release_publishes_only_authorized_zh_cn_trait_and_range_rows_to_discoverability_surfaces(): void
    {
        [$packagePath, $sha256] = $this->writePackage($this->validFortyTwoRowPackage());
        $this->seedContentReadyRows($sha256);
        $this->seedEnglishAndHubRows($sha256);

        $exitCode = Artisan::call('personality:big-five-seo-discoverability-release', $this->commandOptions($packagePath, $sha256, [
            '--release' => true,
            '--no-content-change' => true,
            '--no-english' => true,
            '--no-unreviewed' => true,
            '--no-search' => true,
            '--no-frontend-revalidation' => true,
        ]));
        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode, Artisan::output());
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['release']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertTrue($payload['cms_write_attempted']);
        $this->assertTrue($payload['publish_attempted']);
        $this->assertTrue($payload['index_attempted']);
        $this->assertTrue($payload['sitemap_llms_release_attempted']);
        $this->assertTrue($payload['jsonld_runtime_release_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['frontend_revalidation_attempted']);
        $this->assertSame(20, $payload['updated_asset_count']);
        $this->assertSame(0, $payload['skipped_existing_count']);
        $this->assertNotEmpty($payload['cache_keys_flushed']);

        $released = PersonalityPublicContentAsset::query()
            ->where('locale', 'zh-CN')
            ->whereIn('entity_key', BigFiveCmsPublishGateWriter::AUTHORIZED_ZH_CN_SLUGS)
            ->get();

        $this->assertCount(20, $released);
        foreach ($released as $asset) {
            $this->assertSame(PersonalityPublicContentAsset::LAUNCH_PUBLISHED, (string) $asset->launch_state);
            $this->assertSame(BigFiveSeoDiscoverabilityReleaseWriter::REVIEW_STATE, (string) $asset->review_state);
            $this->assertSame(PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW, (string) $asset->robots);
            $this->assertTrue((bool) $asset->index_eligible);
            $this->assertTrue((bool) $asset->sitemap_eligible);
            $this->assertTrue((bool) $asset->llms_eligible);
            $this->assertTrue((bool) ($asset->schema_json['runtime_jsonld_enabled'] ?? false));
            $this->assertTrue((bool) ($asset->schema_json['runtime_release'] ?? false));
            $this->assertNotNull($asset->published_at);
            $this->assertNotEmpty($asset->content_sections_json);
            $this->assertCount(5, $asset->faq_json);
        }

        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('locale', 'en')->where('index_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('entity_type', PersonalityPublicContentAsset::ENTITY_HUB)->where('index_eligible', true)->count());
    }

    public function test_release_requires_exact_confirmation_safety_flags_and_sha(): void
    {
        [$packagePath, $sha256] = $this->writePackage($this->validFortyTwoRowPackage());
        $this->seedContentReadyRows($sha256);

        $exitCode = Artisan::call('personality:big-five-seo-discoverability-release', [
            '--package' => $packagePath,
            '--confirm-package-sha256' => str_repeat('0', 64),
            '--authorized-slugs' => $this->authorizedSlugCsv(),
            '--target-env' => 'production',
            '--operator-approved' => 'wrong confirmation',
            '--release' => true,
            '--allow-testing' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('runtime_error', $payload['errors'][0]['code'] ?? null);
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
    }

    public function test_release_rejects_assets_that_are_not_content_ready_from_locked_source(): void
    {
        [$packagePath, $sha256] = $this->writePackage($this->validFortyTwoRowPackage());
        $this->seedContentReadyRows($sha256);

        PersonalityPublicContentAsset::query()
            ->where('entity_key', 'openness')
            ->where('locale', 'zh-CN')
            ->update(['source_hash' => 'foreign']);

        $exitCode = Artisan::call('personality:big-five-seo-discoverability-release', $this->commandOptions($packagePath, $sha256, [
            '--release' => true,
            '--no-content-change' => true,
            '--no-english' => true,
            '--no-unreviewed' => true,
            '--no-search' => true,
            '--no-frontend-revalidation' => true,
        ]));
        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('content_ready_asset_missing', $payload['errors'][0]['code'] ?? null);
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
    }

    public function test_release_is_idempotent_for_already_released_rows(): void
    {
        [$packagePath, $sha256] = $this->writePackage($this->validFortyTwoRowPackage());
        $this->seedContentReadyRows($sha256);
        $options = $this->commandOptions($packagePath, $sha256, [
            '--release' => true,
            '--no-content-change' => true,
            '--no-english' => true,
            '--no-unreviewed' => true,
            '--no-search' => true,
            '--no-frontend-revalidation' => true,
        ]);

        $firstExitCode = Artisan::call('personality:big-five-seo-discoverability-release', $options);
        $this->assertSame(0, $firstExitCode);
        $secondExitCode = Artisan::call('personality:big-five-seo-discoverability-release', $options);
        $this->assertSame(0, $secondExitCode);
        $payload = $this->jsonOutput();

        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['updated_asset_count']);
        $this->assertSame(20, $payload['skipped_existing_count']);
        $this->assertSame(20, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
    }

    /**
     * @param  list<array<string,mixed>>  $package
     * @return array{0:string,1:string}
     */
    private function writePackage(array $package): array
    {
        $packagePath = sys_get_temp_dir().'/big-five-seo-discoverability-release-'.bin2hex(random_bytes(6)).'.json';
        $packageJson = (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        File::put($packagePath, $packageJson);

        return [$packagePath, hash('sha256', $packageJson)];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function commandOptions(string $packagePath, string $sha256, array $extra): array
    {
        return array_merge([
            '--package' => $packagePath,
            '--confirm-package-sha256' => $sha256,
            '--authorized-slugs' => $this->authorizedSlugCsv(),
            '--target-env' => 'production',
            '--operator-approved' => PersonalityBigFiveSeoDiscoverabilityRelease::CONFIRMATION_PHRASE,
            '--allow-testing' => true,
            '--json' => true,
        ], $extra);
    }

    private function seedContentReadyRows(string $sourceHash): void
    {
        foreach (BigFiveCmsPublishGateWriter::AUTHORIZED_ZH_CN_SLUGS as $slug) {
            $entityType = in_array($slug, ['openness', 'conscientiousness', 'extraversion', 'agreeableness', 'neuroticism'], true)
                ? PersonalityPublicContentAsset::ENTITY_DOMAIN
                : PersonalityPublicContentAsset::ENTITY_POLARITY;

            $this->createAsset($sourceHash, [
                'entity_type' => $entityType,
                'entity_key' => $slug,
                'slug' => 'big-five/'.$slug,
                'locale' => 'zh-CN',
                'canonical_json' => ['path' => '/zh/personality/big-five/'.$slug],
            ]);
        }
    }

    private function seedEnglishAndHubRows(string $sourceHash): void
    {
        $this->createAsset($sourceHash, [
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'openness',
            'slug' => 'big-five/openness',
            'locale' => 'en',
            'canonical_json' => ['path' => '/en/personality/big-five/openness'],
        ]);
        $this->createAsset($sourceHash, [
            'entity_type' => PersonalityPublicContentAsset::ENTITY_HUB,
            'entity_key' => 'big-five',
            'slug' => 'big-five',
            'locale' => 'zh-CN',
            'canonical_json' => ['path' => '/zh/personality/big-five'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function createAsset(string $sourceHash, array $overrides): PersonalityPublicContentAsset
    {
        return PersonalityPublicContentAsset::query()->create(array_replace_recursive([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'openness',
            'slug' => 'big-five/openness',
            'locale' => 'zh-CN',
            'title' => '开放性',
            'summary' => '解释大五人格维度。',
            'content_sections_json' => [
                ['key' => 'quick_answer', 'title' => '快速回答', 'body_md' => '这是可见正文。'],
                ['key' => 'method_boundary', 'title' => '方法边界', 'body_md' => '本页不用于诊断、招聘筛选或高风险决策。'],
            ],
            'seo_json' => ['title' => '开放性是什么意思？', 'description' => '解释大五人格维度。'],
            'robots' => PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW,
            'canonical_json' => ['path' => '/zh/personality/big-five/openness'],
            'hreflang_json' => [],
            'faq_json' => [
                ['question' => '问题 1？', 'answer' => '回答 1。'],
                ['question' => '问题 2？', 'answer' => '回答 2。'],
                ['question' => '问题 3？', 'answer' => '回答 3。'],
                ['question' => '问题 4？', 'answer' => '回答 4。'],
                ['question' => '问题 5？', 'answer' => '回答 5。'],
            ],
            'media_json' => [],
            'schema_json' => [
                'recommendation' => 'FAQPage',
                'draft_only' => false,
                'runtime_jsonld_enabled' => false,
                'runtime_release' => false,
            ],
            'method_boundary_json' => [],
            'evidence_notes_json' => [],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_CONTENT_READY,
            'review_state' => BigFiveCmsPublishGateWriter::REVIEW_STATE,
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => BigFiveCmsPublishGateWriter::SOURCE_PACKAGE,
            'source_hash' => $sourceHash,
            'published_at' => null,
            'last_reviewed_at' => now(),
        ], $overrides));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function validFortyTwoRowPackage(): array
    {
        $rows = [
            $this->validRow([
                'slug' => 'big-five',
                'content_type' => 'hub_page',
                'title' => '大五人格',
                'canonical_path' => '/zh/personality/big-five',
                'schema_recommendation' => 'CollectionPage',
            ]),
            $this->validRow([
                'slug' => 'big-five',
                'locale' => 'en-US',
                'content_type' => 'hub_page',
                'title' => 'Big Five Personality',
                'canonical_path' => '/en/personality/big-five',
                'schema_recommendation' => 'CollectionPage',
            ]),
        ];

        foreach (['openness', 'conscientiousness', 'extraversion', 'agreeableness', 'neuroticism'] as $trait) {
            $rows[] = $this->validRow([
                'slug' => $trait,
                'content_type' => 'trait_page',
                'title' => $trait,
                'canonical_path' => '/zh/personality/big-five/'.$trait,
            ]);
            $rows[] = $this->validRow([
                'slug' => $trait,
                'locale' => 'en-US',
                'content_type' => 'trait_page',
                'title' => $trait,
                'canonical_path' => '/en/personality/big-five/'.$trait,
            ]);

            foreach (['high', 'mid', 'low'] as $range) {
                $slug = $trait.'-'.$range;
                $rows[] = $this->validRow([
                    'slug' => $slug,
                    'content_type' => 'trait_range_page',
                    'title' => $trait.' '.$range,
                    'canonical_path' => '/zh/personality/big-five/'.$slug,
                ]);
            }
        }

        foreach ([
            'high-openness-low-conscientiousness',
            'low-extraversion-high-conscientiousness',
            'high-agreeableness-high-neuroticism',
            'high-conscientiousness-low-agreeableness',
            'high-openness-high-extraversion',
            'low-neuroticism-high-conscientiousness',
        ] as $combination) {
            $rows[] = $this->validRow([
                'slug' => $combination,
                'content_type' => 'combination_page',
                'title' => $combination,
                'canonical_path' => '/zh/personality/big-five/combinations/'.$combination,
            ]);
        }

        foreach ([
            'big-five-vs-mbti',
            'big-five-and-career',
            'big-five-and-riasec',
            'big-five-and-stress',
            'big-five-and-relationships',
        ] as $crossReading) {
            $rows[] = $this->validRow([
                'slug' => $crossReading,
                'content_type' => 'cross_reading_page',
                'title' => $crossReading,
                'canonical_path' => '/zh/personality/big-five/cross-reading/'.$crossReading,
            ]);
        }

        foreach ([
            'how-to-read-big-five-results',
            'big-five-result-review-work',
            'big-five-result-review-learning',
            'big-five-result-review-relationships',
        ] as $resultReview) {
            $rows[] = $this->validRow([
                'slug' => $resultReview,
                'content_type' => 'result_review_page',
                'title' => $resultReview,
                'canonical_path' => '/zh/personality/big-five/result-review/'.$resultReview,
            ]);
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function validRow(array $overrides = []): array
    {
        return array_replace_recursive([
            'slug' => 'openness',
            'locale' => 'zh-CN',
            'content_type' => 'trait_page',
            'title' => '开放性',
            'status' => 'draft_review_required',
            'canonical_path' => '/zh/personality/big-five/openness',
            'seo' => [
                'title' => '开放性是什么意思？',
                'description' => '解释大五人格开放性维度。',
            ],
            'body_sections' => [
                ['heading' => '快速回答', 'body' => '开放性是大五人格的连续维度之一。'],
                ['heading' => '方法边界', 'body' => '本页不用于诊断或招聘筛选。'],
                ['heading' => 'FAQ', 'body' => 'FAQ 正文只用于源包兼容；结构化 FAQ 以 faq 字段为准。'],
            ],
            'faq' => [
                ['question' => '开放性是什么？', 'answer' => '它描述对新经验和抽象概念的偏好。'],
                ['question' => '高开放一定更好吗？', 'answer' => '不是，高低都有适用场景。'],
                ['question' => '低开放是缺点吗？', 'answer' => '不是，它可能代表稳定和务实。'],
                ['question' => '可以用于招聘吗？', 'answer' => '不可以。'],
                ['question' => '可以用于诊断吗？', 'answer' => '不可以。'],
            ],
            'internal_links' => ['/zh/personality/big-five'],
            'claim_boundaries' => [
                'non_diagnostic',
                'non_predictive',
                'no_hiring_screening',
                'no_specific_validation_metrics_without_public_evidence',
            ],
            'schema_recommendation' => 'FAQPage',
            'indexability_gate' => 'manual_review_required',
        ], $overrides);
    }

    private function authorizedSlugCsv(): string
    {
        return implode(',', BigFiveCmsPublishGateWriter::AUTHORIZED_ZH_CN_SLUGS);
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $output = trim(Artisan::output());
        $lastJsonStart = strrpos($output, "\n{\n");
        if ($lastJsonStart !== false) {
            $output = substr($output, $lastJsonStart + 1);
        }

        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
