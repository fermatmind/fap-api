<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\PersonalityBigFiveCmsPublishGate;
use App\Models\PersonalityPublicContentAsset;
use App\Services\Cms\BigFiveCmsPublishGateWriter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityBigFiveCmsPublishGateCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(PersonalityBigFiveCmsPublishGate::class));
    }

    public function test_dry_run_requires_matching_package_sha(): void
    {
        [$packagePath] = $this->writePackage($this->validFortyTwoRowPackage());

        $exitCode = Artisan::call('personality:big-five-cms-publish-gate', [
            '--package' => $packagePath,
            '--confirm-package-sha256' => str_repeat('0', 64),
            '--authorized-slugs' => $this->authorizedSlugCsv(),
            '--target-env' => 'production',
            '--operator-approved' => PersonalityBigFiveCmsPublishGate::CONFIRMATION_PHRASE,
            '--dry-run' => true,
            '--allow-testing' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('SHA-256 mismatch', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_command_requires_exact_authorized_slug_list(): void
    {
        [$packagePath, $sha256] = $this->writePackage($this->validFortyTwoRowPackage());

        $exitCode = Artisan::call('personality:big-five-cms-publish-gate', [
            '--package' => $packagePath,
            '--confirm-package-sha256' => $sha256,
            '--authorized-slugs' => 'openness,conscientiousness',
            '--target-env' => 'production',
            '--operator-approved' => PersonalityBigFiveCmsPublishGate::CONFIRMATION_PHRASE,
            '--dry-run' => true,
            '--allow-testing' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('exact 20 zh-CN Big Five trait/range slugs', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_write_creates_exact_twenty_authorized_zh_cn_content_ready_noindex_rows(): void
    {
        [$packagePath, $sha256] = $this->writePackage($this->validFortyTwoRowPackage());

        $exitCode = Artisan::call('personality:big-five-cms-publish-gate', [
            '--package' => $packagePath,
            '--confirm-package-sha256' => $sha256,
            '--authorized-slugs' => $this->authorizedSlugCsv(),
            '--target-env' => 'production',
            '--operator-approved' => PersonalityBigFiveCmsPublishGate::CONFIRMATION_PHRASE,
            '--write' => true,
            '--allow-testing' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['dry_run']);
        $this->assertTrue($payload['write']);
        $this->assertTrue($payload['writes_committed']);
        $this->assertTrue($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertFalse($payload['jsonld_runtime_release_attempted']);
        $this->assertSame(20, $payload['row_count']);
        $this->assertSame(20, $payload['created_asset_count']);
        $this->assertSame(20, PersonalityPublicContentAsset::query()->count());

        $this->assertSame(20, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('sitemap_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('llms_eligible', true)->count());
        $this->assertSame(20, PersonalityPublicContentAsset::query()->where('robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW)->count());
        $this->assertSame(20, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_CONTENT_READY)->count());
        $this->assertSame(20, PersonalityPublicContentAsset::query()->where('review_state', BigFiveCmsPublishGateWriter::REVIEW_STATE)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('locale', 'en')->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('entity_type', PersonalityPublicContentAsset::ENTITY_HUB)->count());

        $rangeAsset = PersonalityPublicContentAsset::query()
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_POLARITY)
            ->where('entity_key', 'openness-high')
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertSame('/zh/personality/big-five/openness-high', $rangeAsset->canonical_json['path'] ?? null);
        $this->assertSame('big-five/openness-high', $rangeAsset->slug);
        $this->assertCount(2, $rangeAsset->content_sections_json);
        $this->assertNotContains('FAQ', array_column($rangeAsset->content_sections_json, 'title'));
        $this->assertCount(5, $rangeAsset->faq_json);
        $this->assertSame(false, $rangeAsset->schema_json['runtime_jsonld_enabled'] ?? null);
        $this->assertSame(false, $rangeAsset->schema_json['runtime_release'] ?? null);
        $this->assertSame($sha256, $rangeAsset->source_hash);
        $this->assertNull($rangeAsset->published_at);
    }

    public function test_write_rejects_old_nested_range_canonical_paths(): void
    {
        $package = $this->validFortyTwoRowPackage();
        foreach ($package as &$row) {
            if (($row['slug'] ?? '') === 'openness-high') {
                $row['canonical_path'] = '/zh/personality/big-five/openness/high';
                break;
            }
        }
        unset($row);

        [$packagePath, $sha256] = $this->writePackage($package);

        $exitCode = Artisan::call('personality:big-five-cms-publish-gate', [
            '--package' => $packagePath,
            '--confirm-package-sha256' => $sha256,
            '--authorized-slugs' => $this->authorizedSlugCsv(),
            '--target-env' => 'production',
            '--operator-approved' => PersonalityBigFiveCmsPublishGate::CONFIRMATION_PHRASE,
            '--write' => true,
            '--allow-testing' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('v2 trait-first slug format', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_write_rejects_existing_published_or_indexable_asset(): void
    {
        [$packagePath, $sha256] = $this->writePackage($this->validFortyTwoRowPackage());

        PersonalityPublicContentAsset::query()->create([
            'org_id' => 0,
            'framework' => PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE,
            'entity_type' => PersonalityPublicContentAsset::ENTITY_DOMAIN,
            'entity_key' => 'openness',
            'slug' => 'big-five/openness',
            'locale' => 'zh-CN',
            'title' => 'Live openness',
            'summary' => 'Live summary',
            'content_sections_json' => [['key' => 'overview', 'title' => 'Overview', 'body_md' => 'Live body']],
            'seo_json' => ['title' => 'Live', 'description' => 'Live'],
            'robots' => PersonalityPublicContentAsset::ROBOTS_INDEX_FOLLOW,
            'canonical_json' => ['path' => '/zh/personality/big-five/openness'],
            'hreflang_json' => [],
            'faq_json' => [['question' => 'Q', 'answer' => 'A']],
            'media_json' => [],
            'schema_json' => ['runtime_jsonld_enabled' => true],
            'method_boundary_json' => [],
            'evidence_notes_json' => [],
            'internal_links_json' => [],
            'is_public' => true,
            'index_eligible' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'launch_state' => PersonalityPublicContentAsset::LAUNCH_PUBLISHED,
            'review_state' => 'published',
            'contract_version' => PersonalityPublicContentAsset::CONTRACT_VERSION_V1,
            'source_package' => 'foreign-live-source',
            'source_hash' => 'foreign',
            'published_at' => now(),
        ]);

        $exitCode = Artisan::call('personality:big-five-cms-publish-gate', [
            '--package' => $packagePath,
            '--confirm-package-sha256' => $sha256,
            '--authorized-slugs' => $this->authorizedSlugCsv(),
            '--target-env' => 'production',
            '--operator-approved' => PersonalityBigFiveCmsPublishGate::CONFIRMATION_PHRASE,
            '--write' => true,
            '--allow-testing' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('existing_asset_blocks_content_ready_gate', $payload['errors'][0]['code'] ?? null);
        $this->assertSame(1, PersonalityPublicContentAsset::query()->count());
    }

    public function test_write_is_idempotent_for_same_source_content_ready_rows(): void
    {
        [$packagePath, $sha256] = $this->writePackage($this->validFortyTwoRowPackage());
        $options = [
            '--package' => $packagePath,
            '--confirm-package-sha256' => $sha256,
            '--authorized-slugs' => $this->authorizedSlugCsv(),
            '--target-env' => 'production',
            '--operator-approved' => PersonalityBigFiveCmsPublishGate::CONFIRMATION_PHRASE,
            '--write' => true,
            '--allow-testing' => true,
            '--json' => true,
        ];

        $this->assertSame(0, Artisan::call('personality:big-five-cms-publish-gate', $options));
        $this->assertSame(0, Artisan::call('personality:big-five-cms-publish-gate', $options));

        $payload = $this->jsonOutput();

        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_asset_count']);
        $this->assertSame(0, $payload['updated_asset_count']);
        $this->assertSame(20, $payload['skipped_existing_count']);
        $this->assertSame(20, PersonalityPublicContentAsset::query()->count());
    }

    /**
     * @param  list<array<string,mixed>>  $package
     * @return array{0:string,1:string}
     */
    private function writePackage(array $package): array
    {
        $packagePath = sys_get_temp_dir().'/big-five-cms-publish-gate-'.bin2hex(random_bytes(6)).'.json';
        $packageJson = (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        File::put($packagePath, $packageJson);

        return [$packagePath, hash('sha256', $packageJson)];
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
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
