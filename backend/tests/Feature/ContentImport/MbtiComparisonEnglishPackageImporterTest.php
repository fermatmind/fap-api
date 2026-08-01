<?php

declare(strict_types=1);

namespace Tests\Feature\ContentImport;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MbtiComparisonEnglishPackageImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_importer_is_bound_to_the_final_independently_passed_w9_correction_package(): void
    {
        self::assertSame(
            'deecc8175fb43ba3730d6513b496a0ab6834459108e3b24e25550bbf40e001a2',
            MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
        );
        self::assertSame(
            base_path('content_assets/en-content-parity/W1-mbti/comparisons/w9-correction-deecc817'),
            MbtiComparisonEnglishPackageImporter::defaultPackageDirectory(),
        );

        $manifestPath = MbtiComparisonEnglishPackageImporter::defaultPackageDirectory().'/package_manifest.json';
        $manifest = json_decode((string) File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(MbtiComparisonEnglishPackageImporter::PACKAGE_ID, $manifest['package_id']);
        self::assertSame(MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256, $manifest['package_sha256']);
        self::assertSame(MbtiComparisonEnglishPackageImporter::MANIFEST_SHA256, hash_file('sha256', $manifestPath));
    }

    public function test_exact_package_produces_seven_redacted_deterministic_locale_pair_plans_without_writes(): void
    {
        $exitCode = $this->runDryRun();
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertTrue($payload['ok']);
        self::assertSame('pass', $payload['status']);
        self::assertSame('dry_run', $payload['mode']);
        self::assertTrue($payload['dry_run_only']);
        self::assertFalse($payload['write_supported_in_this_pr']);
        self::assertFalse($payload['writes_committed']);
        self::assertFalse($payload['database_write_attempted']);
        self::assertFalse($payload['cms_write_attempted']);
        self::assertFalse($payload['publish_attempted']);
        self::assertFalse($payload['activation_attempted']);
        self::assertFalse($payload['indexability_attempted']);
        self::assertFalse($payload['search_submission_attempted']);
        self::assertSame(MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256, $payload['package']['package_sha256']);
        self::assertSame(7, $payload['row_count']);
        self::assertCount(7, $payload['rows']);
        self::assertFalse($payload['package']['reader_copy_in_receipt']);
        self::assertFalse($payload['package']['local_path_in_receipt']);

        $slugs = [];
        foreach ($payload['rows'] as $position => $row) {
            $slug = MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[$position];
            $slugs[] = $row['target']['lookup']['slug'];

            self::assertSame(['org_id' => 0, 'locale' => 'zh-CN', 'slug' => $slug], $row['source']['lookup']);
            self::assertTrue($row['source']['read_only']);
            self::assertFalse($row['source']['overwrite_allowed']);
            self::assertSame(['org_id' => 0, 'locale' => 'en', 'slug' => $slug], $row['target']['lookup']);
            self::assertSame('zh-CN', $row['locale_pairing']['source_locale']);
            self::assertSame('en', $row['locale_pairing']['target_locale']);
            self::assertSame($slug, $row['locale_pairing']['pairing_key']);
            self::assertTrue($row['locale_pairing']['deterministic']);
            self::assertSame('draft', $row['planned_state']['publish_status']);
            self::assertFalse($row['planned_state']['is_public']);
            self::assertFalse($row['planned_state']['is_indexable']);
            self::assertFalse($row['planned_state']['sitemap_eligible']);
            self::assertFalse($row['planned_state']['llms_eligible']);
            self::assertFalse($row['planned_state']['search_submission_eligible']);
            self::assertSame('would_upsert_inactive_draft_en_target', $row['action']);
            self::assertFalse($row['reader_copy_in_plan']);
            self::assertFalse($row['write_executed']);
        }

        self::assertSame(MbtiComparisonEnglishPackageImporter::EXACT_SLUGS, $slugs);
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_wrong_unknown_and_rebuilt_package_sha_are_rejected(): void
    {
        $exitCode = $this->runDryRun(str_repeat('0', 64));
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertFalse($payload['write_supported_in_this_pr']);
        self::assertSame('confirmed_package_sha256_mismatch', $payload['errors'][0]['code']);

        $rebuiltDirectory = $this->copyPackage();
        $assetsPath = $rebuiltDirectory.'/assets.json';
        $assets = json_decode((string) File::get($assetsPath), true, 512, JSON_THROW_ON_ERROR);
        $assets['assets'][0]['payload']['title'] .= ' rebuilt';
        File::put($assetsPath, json_encode($assets, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $exitCode = $this->runDryRun(MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256, $rebuiltDirectory);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertSame('package_file_size_mismatch', $payload['errors'][0]['code']);
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_rebuilt_manifest_is_rejected_before_its_file_chain_can_be_accepted(): void
    {
        $uppercaseDigestDirectory = $this->copyPackage();
        $manifestPath = $uppercaseDigestDirectory.'/package_manifest.json';
        $manifestBytes = (string) File::get($manifestPath);
        File::put($manifestPath, str_replace('fermatmind.en_parity.immutable_content_package_manifest.v1', 'fermatmind.en_parity.immutable_content_package_manifest.v2', $manifestBytes));

        $exitCode = $this->runDryRun(MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256, $uppercaseDigestDirectory);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertSame('manifest_sha256_mismatch', $payload['errors'][0]['code']);

        $contractDriftDirectory = $this->copyPackage();
        $manifestPath = $contractDriftDirectory.'/package_manifest.json';
        $manifestBytes = (string) File::get($manifestPath);
        File::put($manifestPath, str_replace('unpublished_candidate', 'unpublished_candidatf', $manifestBytes));

        $exitCode = $this->runDryRun(MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256, $contractDriftDirectory);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertSame('manifest_sha256_mismatch', $payload['errors'][0]['code']);
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_package_symlink_is_rejected_before_external_bytes_are_read(): void
    {
        $directory = $this->copyPackage();
        $assetsPath = $directory.'/assets.json';
        File::delete($assetsPath);
        symlink(MbtiComparisonEnglishPackageImporter::defaultPackageDirectory().'/assets.json', $assetsPath);

        $exitCode = $this->runDryRun(MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256, $directory);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertSame('package_file_symlink_rejected', $payload['errors'][0]['code']);
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_replay_is_byte_deterministic_and_preserves_existing_zh_cn_rows(): void
    {
        foreach (MbtiComparisonEnglishPackageImporter::EXACT_SLUGS as $slug) {
            [$leftType, $rightType] = explode('-vs-', $slug, 2);
            MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'locale' => 'zh-CN',
                'slug' => $slug,
                'left_type_code' => strtoupper($leftType),
                'right_type_code' => strtoupper($rightType),
                'title' => '受保护的中文权威 '.$slug,
                'seo_title' => '中文 SEO '.$slug,
                'seo_description' => '中文描述',
                'summary' => '中文摘要',
                'content_payload_json' => ['protected' => true],
                'review_status' => 'approved',
                'publish_status' => 'published',
                'indexability_status' => 'indexable',
                'is_public' => true,
                'is_indexable' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'search_submission_eligible' => true,
            ]);
        }

        self::assertSame(0, $this->runDryRun());
        $firstOutput = Artisan::output();
        self::assertSame(0, $this->runDryRun());
        $secondOutput = Artisan::output();

        self::assertSame($firstOutput, $secondOutput);
        self::assertSame(7, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->count());
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('locale', 'en')->count());
        foreach (MbtiComparisonEnglishPackageImporter::EXACT_SLUGS as $slug) {
            $row = MbtiCrossTypeComparisonAuthority::query()
                ->withoutGlobalScopes()
                ->where('locale', 'zh-CN')
                ->where('slug', $slug)
                ->firstOrFail();
            self::assertSame('受保护的中文权威 '.$slug, $row->title);
            self::assertSame(['protected' => true], $row->content_payload_json);
            self::assertTrue($row->is_public);
            self::assertTrue($row->is_indexable);
        }
    }

    public function test_exact_control_approval_imports_only_seven_english_inactive_drafts_and_replays_idempotently(): void
    {
        foreach (MbtiComparisonEnglishPackageImporter::EXACT_SLUGS as $slug) {
            [$leftType, $rightType] = explode('-vs-', $slug, 2);
            MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->create([
                'org_id' => 0,
                'locale' => 'zh-CN',
                'slug' => $slug,
                'left_type_code' => strtoupper($leftType),
                'right_type_code' => strtoupper($rightType),
                'title' => '受保护的中文权威 '.$slug,
                'seo_title' => '中文 SEO '.$slug,
                'seo_description' => '中文描述',
                'summary' => '中文摘要',
                'content_payload_json' => ['protected' => true],
                'review_status' => 'approved',
                'publish_status' => 'published',
                'indexability_status' => 'indexable',
                'is_public' => true,
                'is_indexable' => true,
                'sitemap_eligible' => true,
                'llms_eligible' => true,
                'search_submission_eligible' => true,
            ]);
        }

        self::assertSame(0, $this->runWrite());
        $first = $this->jsonOutput();
        self::assertTrue($first['ok']);
        self::assertSame('write_inactive_draft', $first['mode']);
        self::assertTrue($first['writes_committed']);
        self::assertSame(MbtiComparisonEnglishPackageImporter::APPROVAL_SHA256, $first['approval']['approval_sha256']);
        self::assertSame(MbtiComparisonEnglishPackageImporter::APPROVAL_REF, $first['approval']['approval_ref']);
        self::assertSame(7, $first['row_count']);
        self::assertSame(7, $first['created_count']);
        self::assertSame(0, $first['updated_count']);
        self::assertSame(0, $first['preserved_count']);
        self::assertFalse($first['publish_attempted']);
        self::assertFalse($first['activation_attempted']);
        self::assertFalse($first['indexability_attempted']);
        self::assertFalse($first['sitemap_attempted']);
        self::assertFalse($first['llms_attempted']);
        self::assertFalse($first['search_submission_attempted']);
        self::assertFalse($first['deploy_attempted']);

        $timestamps = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('locale', 'en')
            ->orderBy('slug')
            ->get()
            ->mapWithKeys(static fn (MbtiCrossTypeComparisonAuthority $row): array => [
                $row->slug => [$row->imported_at?->toJSON(), $row->updated_at?->toJSON()],
            ])
            ->all();

        self::assertSame(0, $this->runWrite());
        $second = $this->jsonOutput();
        self::assertSame(0, $second['created_count']);
        self::assertSame(0, $second['updated_count']);
        self::assertSame(7, $second['preserved_count']);
        self::assertFalse($second['writes_committed']);
        self::assertFalse($second['database_write_attempted']);
        self::assertFalse($second['cms_write_attempted']);
        self::assertSame(
            $timestamps,
            MbtiCrossTypeComparisonAuthority::query()
                ->withoutGlobalScopes()
                ->where('locale', 'en')
                ->orderBy('slug')
                ->get()
                ->mapWithKeys(static fn (MbtiCrossTypeComparisonAuthority $row): array => [
                    $row->slug => [$row->imported_at?->toJSON(), $row->updated_at?->toJSON()],
                ])
                ->all(),
        );

        self::assertSame(7, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('locale', 'en')->count());
        self::assertSame(7, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->count());
        foreach (MbtiComparisonEnglishPackageImporter::EXACT_SLUGS as $slug) {
            $english = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('locale', 'en')->where('slug', $slug)->firstOrFail();
            self::assertSame('draft', $english->publish_status);
            self::assertSame('w9_passed_pending_editorial', $english->review_status);
            self::assertSame('blocked', $english->indexability_status);
            self::assertFalse($english->is_public);
            self::assertFalse($english->is_indexable);
            self::assertFalse($english->sitemap_eligible);
            self::assertFalse($english->llms_eligible);
            self::assertFalse($english->search_submission_eligible);
            self::assertNull($english->published_at);
            self::assertSame(MbtiComparisonEnglishPackageImporter::PACKAGE_ID, $english->source_package_id);

            $chinese = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('locale', 'zh-CN')->where('slug', $slug)->firstOrFail();
            self::assertSame('受保护的中文权威 '.$slug, $chinese->title);
            self::assertTrue($chinese->is_public);
            self::assertTrue($chinese->is_indexable);
        }
    }

    public function test_write_fails_closed_on_missing_wrong_or_tampered_approval_without_mutation(): void
    {
        $exitCode = $this->runWrite(str_repeat('0', 64));
        $payload = $this->jsonOutput();
        self::assertSame(1, $exitCode);
        self::assertSame('confirmed_approval_sha256_mismatch', $payload['errors'][0]['code']);

        $approvalPath = sys_get_temp_dir().'/w1-mbti-comparison-approval-'.bin2hex(random_bytes(6)).'.json';
        $approvalBytes = (string) File::get(MbtiComparisonEnglishPackageImporter::defaultApprovalPath());
        File::put($approvalPath, str_replace('human_operator', 'human_operatoq', $approvalBytes));
        $exitCode = $this->runWrite(MbtiComparisonEnglishPackageImporter::APPROVAL_SHA256, $approvalPath);
        $payload = $this->jsonOutput();
        self::assertSame(1, $exitCode);
        self::assertSame('approval_sha256_mismatch', $payload['errors'][0]['code']);
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
    }

    public function test_semantically_identical_json_key_order_is_a_zero_write_replay(): void
    {
        self::assertSame(0, $this->runWrite());
        $authority = MbtiCrossTypeComparisonAuthority::query()
            ->withoutGlobalScopes()
            ->where('locale', 'en')
            ->where('slug', MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[0])
            ->firstOrFail();
        $authority->content_payload_json = $this->reverseObjectKeys($authority->content_payload_json);
        $authority->save();
        $timestamp = $authority->fresh()->updated_at?->toJSON();

        self::assertSame(0, $this->runWrite());
        $payload = $this->jsonOutput();
        self::assertSame(0, $payload['updated_count']);
        self::assertSame(7, $payload['preserved_count']);
        self::assertFalse($payload['writes_committed']);
        self::assertFalse($payload['database_write_attempted']);
        self::assertSame($timestamp, $authority->fresh()->updated_at?->toJSON());
    }

    public function test_existing_public_english_collision_rolls_back_the_whole_cohort(): void
    {
        MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'locale' => 'en',
            'slug' => MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[3],
            'left_type_code' => 'INFJ',
            'right_type_code' => 'INFP',
            'title' => 'Protected public English row',
            'seo_title' => 'Protected public English row',
            'seo_description' => 'Protected',
            'summary' => 'Protected',
            'content_payload_json' => ['protected' => true],
            'source_package_id' => 'unrelated-package',
            'review_status' => 'approved',
            'publish_status' => 'published',
            'indexability_status' => 'indexable',
            'is_public' => true,
            'is_indexable' => true,
            'sitemap_eligible' => true,
            'llms_eligible' => true,
            'search_submission_eligible' => true,
        ]);

        self::assertSame(1, $this->runWrite());
        $payload = $this->jsonOutput();
        self::assertSame('existing_target_collision', $payload['errors'][0]['code']);
        self::assertTrue($payload['database_write_attempted']);
        self::assertTrue($payload['cms_write_attempted']);
        self::assertFalse($payload['writes_committed']);
        self::assertSame(1, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('locale', 'en')->count());
        $protected = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('locale', 'en')->firstOrFail();
        self::assertSame('Protected public English row', $protected->title);
        self::assertTrue($protected->is_public);
    }

    public function test_advanced_editorial_draft_state_is_never_downgraded_by_replay(): void
    {
        $slug = MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[3];
        MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'locale' => 'en',
            'slug' => $slug,
            'left_type_code' => 'INFJ',
            'right_type_code' => 'INFP',
            'title' => 'Editorially approved English draft',
            'seo_title' => 'Editorially approved English draft',
            'seo_description' => 'Protected',
            'summary' => 'Protected',
            'content_payload_json' => ['protected' => true],
            'source_package_id' => MbtiComparisonEnglishPackageImporter::PACKAGE_ID,
            'review_status' => 'editorially_approved',
            'publish_status' => 'draft',
            'indexability_status' => 'blocked',
            'is_public' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'search_submission_eligible' => false,
        ]);

        self::assertSame(1, $this->runWrite());
        $payload = $this->jsonOutput();
        self::assertSame('existing_target_collision', $payload['errors'][0]['code']);
        self::assertTrue($payload['database_write_attempted']);
        $protected = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('locale', 'en')->firstOrFail();
        self::assertSame('editorially_approved', $protected->review_status);
        self::assertSame('Editorially approved English draft', $protected->title);
    }

    public function test_advanced_indexability_draft_state_is_never_downgraded_by_replay(): void
    {
        $slug = MbtiComparisonEnglishPackageImporter::EXACT_SLUGS[3];
        MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->create([
            'org_id' => 0,
            'locale' => 'en',
            'slug' => $slug,
            'left_type_code' => 'INFJ',
            'right_type_code' => 'INFP',
            'title' => 'Indexability-held English draft',
            'seo_title' => 'Indexability-held English draft',
            'seo_description' => 'Protected',
            'summary' => 'Protected',
            'content_payload_json' => ['protected' => true],
            'source_package_id' => MbtiComparisonEnglishPackageImporter::PACKAGE_ID,
            'review_status' => 'w9_passed_pending_editorial',
            'publish_status' => 'draft',
            'indexability_status' => 'held_for_mbti_cross_indexability_release',
            'is_public' => false,
            'is_indexable' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'search_submission_eligible' => false,
        ]);

        self::assertSame(1, $this->runWrite());
        $payload = $this->jsonOutput();
        self::assertSame('existing_target_collision', $payload['errors'][0]['code']);
        self::assertTrue($payload['database_write_attempted']);
        self::assertFalse($payload['writes_committed']);
        $protected = MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->where('locale', 'en')->firstOrFail();
        self::assertSame('held_for_mbti_cross_indexability_release', $protected->indexability_status);
        self::assertSame('Indexability-held English draft', $protected->title);
    }

    public function test_staging_and_production_environments_fail_before_any_write_attempt(): void
    {
        foreach (['staging', 'production'] as $environment) {
            $this->app->detectEnvironment(static fn (): string => $environment);
            self::assertSame(1, $this->runWrite());
            $payload = $this->jsonOutput();
            self::assertSame('environment_write_not_authorized', $payload['errors'][0]['code']);
            self::assertFalse($payload['database_write_attempted']);
            self::assertFalse($payload['cms_write_attempted']);
            self::assertFalse($payload['writes_committed']);
            self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
        }
    }

    public function test_database_exception_receipt_is_redacted(): void
    {
        Schema::drop('mbti_cross_type_comparison_authorities');

        self::assertSame(1, $this->runWrite());
        $output = Artisan::output();
        self::assertJson($output);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('unexpected_error', $payload['errors'][0]['code']);
        self::assertSame('Exact-package validation failed closed.', $payload['errors'][0]['message']);
        self::assertFalse($payload['database_write_attempted']);
        self::assertStringNotContainsString('SQLSTATE', $output);
        self::assertStringNotContainsString('mbti_cross_type_comparison_authorities', $output);
        self::assertStringNotContainsString('content_payload_json', $output);
    }

    private function runDryRun(
        string $packageSha = MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
        ?string $packageDirectory = null,
    ): int {
        $arguments = [
            '--package-sha' => $packageSha,
            '--dry-run' => true,
            '--json' => true,
        ];
        if ($packageDirectory !== null) {
            $arguments['--package'] = $packageDirectory;
        }

        return Artisan::call('content:import-mbti-comparison-english-package', $arguments);
    }

    private function runWrite(
        string $approvalSha = MbtiComparisonEnglishPackageImporter::APPROVAL_SHA256,
        ?string $approvalPath = null,
    ): int {
        $arguments = [
            '--package-sha' => MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
            '--write' => true,
            '--approval-sha' => $approvalSha,
            '--json' => true,
        ];
        if ($approvalPath !== null) {
            $arguments['--approval'] = $approvalPath;
        }

        return Artisan::call('content:import-mbti-comparison-english-package', $arguments);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function copyPackage(): string
    {
        $directory = sys_get_temp_dir().'/w1-mbti-comparison-package-'.bin2hex(random_bytes(6));
        File::copyDirectory(MbtiComparisonEnglishPackageImporter::defaultPackageDirectory(), $directory);

        return $directory;
    }

    private function reverseObjectKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->reverseObjectKeys($item), $value);
        }

        $reordered = [];
        foreach (array_reverse(array_keys($value)) as $key) {
            $reordered[$key] = $this->reverseObjectKeys($value[$key]);
        }

        return $reordered;
    }
}
