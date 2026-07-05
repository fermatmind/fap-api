<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\PersonalityBigFiveCmsImportDraftDryRun;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityBigFiveCmsImportDraftDryRunCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(PersonalityBigFiveCmsImportDraftDryRun::class));
    }

    public function test_dry_run_reads_forty_two_row_cms_import_draft_without_writes(): void
    {
        $packagePath = $this->writePackage($this->validFortyTwoRowPackage());

        $exitCode = Artisan::call('personality:big-five-cms-import-draft-dry-run', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['dry_run_only']);
        $this->assertFalse($payload['write_supported_in_this_pr']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertSame(42, $payload['row_count']);
        $this->assertTrue($payload['row_count_matches_expected']);
        $this->assertSame(0, $payload['old_short_big_five_route_residue_count']);
        $this->assertSame('App\\Models\\PersonalityPublicContentAsset', $payload['field_mapping_contract']['target_model']);
        $this->assertContains('personality_public_content_assets', $payload['field_mapping_contract']['target_tables']);
        $this->assertSame(15, $payload['content_type_counts']['trait_range_page'] ?? null);
        $this->assertSame(10, $payload['content_type_counts']['trait_page'] ?? null);
        $this->assertSame(6, $payload['content_type_counts']['combination_page'] ?? null);
        $this->assertSame(5, $payload['content_type_counts']['cross_reading_page'] ?? null);
        $this->assertSame(4, $payload['content_type_counts']['result_review_page'] ?? null);
        $this->assertSame(2, $payload['content_type_counts']['hub_page'] ?? null);
    }

    public function test_command_requires_explicit_dry_run(): void
    {
        $packagePath = $this->writePackage([$this->validRow()]);

        $exitCode = Artisan::call('personality:big-five-cms-import-draft-dry-run', [
            '--package' => $packagePath,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertSame('runtime_error', $payload['errors'][0]['code'] ?? null);
        $this->assertStringContainsString('--dry-run is required', (string) ($payload['errors'][0]['message'] ?? ''));
    }

    public function test_command_refuses_write_mode(): void
    {
        $packagePath = $this->writePackage([$this->validRow()]);

        $exitCode = Artisan::call('personality:big-five-cms-import-draft-dry-run', [
            '--package' => $packagePath,
            '--write' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['write_supported_in_this_pr']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertStringContainsString('--write is intentionally unsupported', (string) ($payload['errors'][0]['message'] ?? ''));
    }

    public function test_missing_shape_and_short_routes_fail_closed(): void
    {
        $row = $this->validRow();
        $row['canonical_path'] = '/zh/big-five/openness';
        unset($row['body_sections'], $row['faq']);
        $packagePath = $this->writePackage([$row]);

        $exitCode = Artisan::call('personality:big-five-cms-import-draft-dry-run', [
            '--package' => $packagePath,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $codes = array_column($payload['errors'], 'code');

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('old_short_big_five_route_present', $codes);
        $this->assertContains('required_field_missing', $codes);
        $this->assertContains('body_sections_missing_or_empty', $codes);
        $this->assertContains('faq_missing_or_empty', $codes);
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
                $rows[] = $this->validRow([
                    'slug' => $trait.'-'.$range,
                    'content_type' => 'trait_range_page',
                    'title' => $trait.' '.$range,
                    'canonical_path' => '/zh/personality/big-five/'.$trait.'/'.$range,
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

    /**
     * @param  list<array<string,mixed>>  $package
     */
    private function writePackage(array $package): string
    {
        $path = sys_get_temp_dir().'/big-five-cms-import-draft-'.bin2hex(random_bytes(6)).'.json';
        File::put($path, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
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
