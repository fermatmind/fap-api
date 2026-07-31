<?php

declare(strict_types=1);

namespace Tests\Feature\ContentImport;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Services\ContentImport\MbtiComparisonEnglishPackageImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class MbtiComparisonEnglishPackageImporterTest extends TestCase
{
    use RefreshDatabase;

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
        self::assertSame('manifest_file_sha256_mismatch', $payload['errors'][0]['code']);
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

    public function test_write_mode_fails_closed_without_database_mutation(): void
    {
        $exitCode = Artisan::call('content:import-mbti-comparison-english-package', [
            '--package-sha' => MbtiComparisonEnglishPackageImporter::PACKAGE_SHA256,
            '--write' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertSame('write_mode_not_supported', $payload['errors'][0]['code']);
        self::assertFalse($payload['writes_committed']);
        self::assertFalse($payload['database_write_attempted']);
        self::assertFalse($payload['cms_write_attempted']);
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->withoutGlobalScopes()->count());
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
}
