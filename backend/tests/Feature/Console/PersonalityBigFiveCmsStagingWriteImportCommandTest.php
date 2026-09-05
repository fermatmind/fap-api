<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Console\Commands\PersonalityBigFiveCmsStagingWriteImport;
use App\Models\PersonalityPublicContentAsset;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Concerns\UsesIsolatedSqliteDatabase;
use Tests\TestCase;

final class PersonalityBigFiveCmsStagingWriteImportCommandTest extends TestCase
{
    use UsesIsolatedSqliteDatabase;

    private const CONFIRMATION_PHRASE = 'I authorize Big Five CMS staging/dev draft import only. No production import, publish, indexability, sitemap, llms, JSON-LD runtime, deploy, or search release is authorized.';

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(Kernel::class)->registerCommand($this->app->make(PersonalityBigFiveCmsStagingWriteImport::class));
    }

    public function test_dry_run_requires_matching_package_sha(): void
    {
        [$packagePath, $packetPath] = $this->writeAuthorizedArtifacts($this->validFortyTwoRowPackage());

        $exitCode = Artisan::call('personality:big-five-cms-staging-write-import', [
            '--package' => $packagePath,
            '--authorization-packet' => $packetPath,
            '--confirm-package-sha256' => str_repeat('0', 64),
            '--target-env' => 'staging',
            '--operator-approved' => self::CONFIRMATION_PHRASE,
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

    public function test_command_rejects_production_runtime(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        [$packagePath, $packetPath, $sha256] = $this->writeAuthorizedArtifacts($this->validFortyTwoRowPackage());

        $exitCode = Artisan::call('personality:big-five-cms-staging-write-import', [
            '--package' => $packagePath,
            '--authorization-packet' => $packetPath,
            '--confirm-package-sha256' => $sha256,
            '--target-env' => 'staging',
            '--operator-approved' => self::CONFIRMATION_PHRASE,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertStringContainsString('Production environment is not authorized', (string) ($payload['errors'][0]['message'] ?? ''));
        $this->assertSame(0, PersonalityPublicContentAsset::query()->count());
    }

    public function test_write_creates_forty_two_non_public_noindex_drafts_and_filters_faq_body_sections(): void
    {
        [$packagePath, $packetPath, $sha256] = $this->writeAuthorizedArtifacts($this->validFortyTwoRowPackage());

        $exitCode = Artisan::call('personality:big-five-cms-staging-write-import', [
            '--package' => $packagePath,
            '--authorization-packet' => $packetPath,
            '--confirm-package-sha256' => $sha256,
            '--target-env' => 'staging',
            '--operator-approved' => self::CONFIRMATION_PHRASE,
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
        $this->assertSame(42, $payload['row_count']);
        $this->assertSame(42, $payload['created_asset_count']);
        $this->assertIsArray($payload['rollback_handle']);
        $this->assertSame(42, PersonalityPublicContentAsset::query()->count());

        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('is_public', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('index_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('sitemap_eligible', true)->count());
        $this->assertSame(0, PersonalityPublicContentAsset::query()->where('llms_eligible', true)->count());
        $this->assertSame(42, PersonalityPublicContentAsset::query()->where('robots', PersonalityPublicContentAsset::ROBOTS_NOINDEX_FOLLOW)->count());
        $this->assertSame(42, PersonalityPublicContentAsset::query()->where('launch_state', PersonalityPublicContentAsset::LAUNCH_REVIEW)->count());

        $asset = PersonalityPublicContentAsset::query()
            ->where('framework', PersonalityPublicContentAsset::FRAMEWORK_BIG_FIVE)
            ->where('entity_type', PersonalityPublicContentAsset::ENTITY_HUB)
            ->where('locale', 'zh-CN')
            ->firstOrFail();

        $this->assertSame('/zh/personality/big-five', $asset->canonical_json['path'] ?? null);
        $this->assertCount(2, $asset->content_sections_json);
        $this->assertNotContains('FAQ', array_column($asset->content_sections_json, 'title'));
        $this->assertCount(5, $asset->faq_json);
        $this->assertSame(false, $asset->schema_json['runtime_jsonld_enabled'] ?? null);
        $this->assertSame($sha256, $asset->source_hash);
    }

    public function test_write_is_idempotent_for_same_source_drafts(): void
    {
        [$packagePath, $packetPath, $sha256] = $this->writeAuthorizedArtifacts($this->validFortyTwoRowPackage());
        $options = [
            '--package' => $packagePath,
            '--authorization-packet' => $packetPath,
            '--confirm-package-sha256' => $sha256,
            '--target-env' => 'staging',
            '--operator-approved' => self::CONFIRMATION_PHRASE,
            '--write' => true,
            '--allow-testing' => true,
            '--json' => true,
        ];

        $this->assertSame(0, Artisan::call('personality:big-five-cms-staging-write-import', $options));
        $this->assertSame(0, Artisan::call('personality:big-five-cms-staging-write-import', $options));

        $payload = $this->jsonOutput();

        $this->assertTrue($payload['ok']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertSame(0, $payload['created_asset_count']);
        $this->assertSame(0, $payload['updated_asset_count']);
        $this->assertSame(42, $payload['skipped_existing_count']);
        $this->assertSame(42, PersonalityPublicContentAsset::query()->count());
    }

    /**
     * @param  list<array<string,mixed>>  $package
     * @return array{0:string,1:string,2:string}
     */
    private function writeAuthorizedArtifacts(array $package): array
    {
        $packagePath = sys_get_temp_dir().'/big-five-cms-import-draft-'.bin2hex(random_bytes(6)).'.json';
        $packageJson = (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        File::put($packagePath, $packageJson);
        $sha256 = hash('sha256', $packageJson);

        $packetPath = sys_get_temp_dir().'/big-five-cms-auth-packet-'.bin2hex(random_bytes(6)).'.json';
        File::put($packetPath, json_encode([
            'contains_secrets' => false,
            'target' => [
                'environment' => 'staging',
                'backend_connection_alias' => 'staging-cms-nonsecret-alias',
                'database_connection_name' => 'staging',
            ],
            'source' => [
                'package_path' => $packagePath,
                'package_sha256' => $sha256,
                'expected_row_count' => 42,
            ],
            'authorized_actions' => [
                'staging_or_dev_cms_draft_write' => true,
                'production_import' => false,
                'publish' => false,
                'indexability_release' => false,
                'sitemap_release' => false,
                'llms_release' => false,
                'jsonld_runtime_release' => false,
                'search_submission' => false,
                'manual_deploy' => false,
                'staging_deploy_wait' => false,
                'production_deploy' => false,
            ],
            'operator_authorization' => [
                'confirmation_phrase' => self::CONFIRMATION_PHRASE,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return [$packagePath, $packetPath, $sha256];
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
