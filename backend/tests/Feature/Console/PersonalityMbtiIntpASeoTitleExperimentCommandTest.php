<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersonalityMbtiIntpASeoTitleExperimentCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PACKAGE_PATH = 'docs/seo/personality/experiments/zh-intp-a-seo-title-v1.json';

    private const CURRENT_TITLE = 'INTP-A 人格特点：分析建模、可能性探索和独立解题 | FermatMind';

    private const PROPOSED_TITLE = 'INTP-A 是什么？人格特点、优势盲点与适合场景 | FermatMind';

    private const CURRENT_DESCRIPTION = '了解 INTP-A 的分析建模、可能性探索和独立解题、适合与不适合的场景、A/T 差异、职业、关系、压力应对、常见误解与 FAQ。内容仅用于自我理解和成长复盘。';

    private const APPROVAL = 'I authorize one inactive staging CMS revision for the zh-CN INTP-A seo_title experiment. No production, live SEO metadata, publish, indexability, sitemap, llms, search, or deploy write is authorized.';

    public function test_dry_run_validates_one_target_without_writes(): void
    {
        $this->createAuthority();

        [$exitCode, $receipt] = $this->runCommand(['--dry-run' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($receipt['ok']);
        $this->assertSame('planned', $receipt['status']);
        $this->assertSame(0, $receipt['revision_created_count']);
        $this->assertFalse($receipt['writes_committed']);
        $this->assertSame(0, $receipt['live_projection_changes']);
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_write_creates_one_inactive_revision_and_preserves_live_authority(): void
    {
        [, $variant, $seoMeta] = $this->createAuthority();
        $seoMeta->refresh();
        $before = $seoMeta->getAttributes();

        [$exitCode, $receipt] = $this->runCommand([
            '--write' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-indexability-change' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($receipt['ok']);
        $this->assertSame('draft_revision_created', $receipt['status']);
        $this->assertSame(1, $receipt['revision_created_count']);
        $this->assertTrue($receipt['writes_committed']);
        $this->assertSame(0, $receipt['live_projection_changes']);
        $this->assertSame(1, PersonalityProfileVariantRevision::query()->count());

        $revision = PersonalityProfileVariantRevision::query()->firstOrFail();
        $this->assertSame((int) $variant->id, (int) $revision->personality_profile_variant_id);
        $this->assertSame('inactive_draft', $revision->snapshot_json['status']);
        $this->assertSame(
            ['field', 'current', 'proposed'],
            array_keys($revision->snapshot_json['change']),
        );
        $this->assertSame(self::PROPOSED_TITLE, $revision->snapshot_json['change']['proposed']);

        $seoMeta->refresh();
        $this->assertSame($before, $seoMeta->getAttributes());
        $this->assertSame(self::CURRENT_TITLE, $seoMeta->seo_title);
    }

    public function test_same_package_write_is_idempotent(): void
    {
        $this->createAuthority();
        $writeOptions = [
            '--write' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-indexability-change' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
        ];

        [$firstExit] = $this->runCommand($writeOptions);
        [$secondExit, $secondReceipt] = $this->runCommand($writeOptions);

        $this->assertSame(0, $firstExit);
        $this->assertSame(0, $secondExit);
        $this->assertSame('idempotent_existing_draft', $secondReceipt['status']);
        $this->assertSame(0, $secondReceipt['revision_created_count']);
        $this->assertSame(1, $secondReceipt['idempotent_count']);
        $this->assertFalse($secondReceipt['writes_committed']);
        $this->assertSame(1, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_idempotent_rerun_rejects_live_authority_drift(): void
    {
        [, $variant] = $this->createAuthority();
        $section = PersonalityProfileVariantSection::query()->create([
            'org_id' => 0,
            'personality_profile_variant_id' => (int) $variant->id,
            'section_key' => 'core_snapshot',
            'render_variant' => 'cards',
            'body_md' => 'Stable body.',
            'body_html' => '<p>Stable body.</p>',
            'payload_json' => ['items' => [['title' => 'Stable item']]],
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        $writeOptions = [
            '--write' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-indexability-change' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
        ];

        [$firstExit] = $this->runCommand($writeOptions);
        $section->update(['payload_json' => ['items' => [['title' => 'Drifted item']]]]);
        [$secondExit, $secondReceipt] = $this->runCommand($writeOptions);

        $this->assertSame(0, $firstExit);
        $this->assertSame(1, $secondExit);
        $this->assertFalse($secondReceipt['ok']);
        $this->assertSame(1, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_idempotent_rerun_rejects_public_profile_field_drift(): void
    {
        [$profile] = $this->createAuthority();
        $writeOptions = [
            '--write' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-indexability-change' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
        ];

        [$firstExit] = $this->runCommand($writeOptions);
        $profile->update(['subtitle' => 'Drifted public subtitle']);
        [$secondExit, $secondReceipt] = $this->runCommand($writeOptions);

        $this->assertSame(0, $firstExit);
        $this->assertSame(1, $secondExit);
        $this->assertFalse($secondReceipt['ok']);
        $this->assertSame(1, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_baseline_drift_fails_closed(): void
    {
        [, , $seoMeta] = $this->createAuthority();
        $seoMeta->update(['seo_title' => 'Drifted title']);

        [$exitCode, $receipt] = $this->runCommand(['--dry-run' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($receipt['ok']);
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_non_title_authority_baseline_drift_fails_closed(): void
    {
        [, , $seoMeta] = $this->createAuthority();
        $seoMeta->update(['canonical_url' => 'https://fermatmind.com/zh/personality/intp']);

        [$exitCode, $receipt] = $this->runCommand(['--dry-run' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($receipt['ok']);
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_extra_change_field_fails_closed(): void
    {
        $this->createAuthority();
        $package = $this->package();
        $package['change']['seo_description'] = 'Not authorized';
        $path = $this->writeTemporaryPackage($package);

        [$exitCode, $receipt] = $this->runCommand(['--dry-run' => true], $path);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($receipt['ok']);
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_same_experiment_id_with_different_package_sha_fails_closed(): void
    {
        $this->createAuthority();
        $writeOptions = [
            '--write' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-indexability-change' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
        ];
        [$firstExit] = $this->runCommand($writeOptions);
        $this->assertSame(0, $firstExit);

        $package = $this->package();
        $package['evidence_notes'][] = 'Collision test with a different exact package identity.';
        $path = $this->writeTemporaryPackage($package);
        [$secondExit, $secondReceipt] = $this->runCommand($writeOptions, $path);

        $this->assertSame(1, $secondExit);
        $this->assertFalse($secondReceipt['ok']);
        $this->assertSame(1, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_write_rejects_invalid_receipt_destination_before_database_commit(): void
    {
        $this->createAuthority();
        $outputDirectory = sys_get_temp_dir().'/fm-intp-a-seo-title-receipt-dir-'.Str::random(12);
        File::ensureDirectoryExists($outputDirectory);
        $this->beforeApplicationDestroyed(static fn () => File::deleteDirectory($outputDirectory));

        $exitCode = Artisan::call('personality:mbti-intp-a-seo-title-experiment', [
            '--package' => base_path(self::PACKAGE_PATH),
            '--confirm-package-sha256' => hash_file('sha256', base_path(self::PACKAGE_PATH)),
            '--target-env' => 'staging',
            '--operator-approved' => self::APPROVAL,
            '--allow-testing' => true,
            '--write' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-indexability-change' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--json' => true,
            '--output' => $outputDirectory,
        ]);
        $receipt = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($receipt);
        $this->assertFalse($receipt['ok']);
        $this->assertSame('runtime_error', $receipt['errors'][0]['code']);
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_post_commit_receipt_failure_preserves_committed_transaction_truth(): void
    {
        $this->createAuthority();
        $output = sys_get_temp_dir().'/fm-intp-a-seo-title-failing-receipt-'.Str::random(12).'.json';
        $this->beforeApplicationDestroyed(static fn () => @unlink($output));
        File::partialMock()
            ->shouldReceive('put')
            ->once()
            ->andReturn(false);

        $exitCode = Artisan::call('personality:mbti-intp-a-seo-title-experiment', [
            '--package' => base_path(self::PACKAGE_PATH),
            '--confirm-package-sha256' => hash_file('sha256', base_path(self::PACKAGE_PATH)),
            '--target-env' => 'staging',
            '--operator-approved' => self::APPROVAL,
            '--allow-testing' => true,
            '--write' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-indexability-change' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--json' => true,
            '--output' => $output,
        ]);
        $receipt = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($receipt);
        $this->assertFalse($receipt['ok']);
        $this->assertSame('receipt_write_error', $receipt['status']);
        $this->assertSame('receipt_write_error', $receipt['errors'][0]['code']);
        $this->assertTrue($receipt['writes_committed']);
        $this->assertSame(1, $receipt['revision_created_count']);
        $this->assertSame(1, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_invalid_command_guard_does_not_touch_existing_output_path(): void
    {
        $this->createAuthority();
        $output = sys_get_temp_dir().'/fm-intp-a-seo-title-existing-receipt-'.Str::random(12).'.json';
        File::put($output, 'sentinel');
        $this->beforeApplicationDestroyed(static fn () => File::delete($output));

        $exitCode = Artisan::call('personality:mbti-intp-a-seo-title-experiment', [
            '--package' => base_path(self::PACKAGE_PATH),
            '--confirm-package-sha256' => hash_file('sha256', base_path(self::PACKAGE_PATH)),
            '--target-env' => 'staging',
            '--operator-approved' => 'invalid approval',
            '--allow-testing' => true,
            '--write' => true,
            '--draft-only' => true,
            '--no-publish' => true,
            '--no-indexability-change' => true,
            '--no-sitemap' => true,
            '--no-llms' => true,
            '--no-search-release' => true,
            '--json' => true,
            '--output' => $output,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertSame('sentinel', File::get($output));
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{int, array<string, mixed>}
     */
    private function runCommand(array $options, ?string $packagePath = null): array
    {
        $packagePath ??= base_path(self::PACKAGE_PATH);
        $output = sys_get_temp_dir().'/fm-intp-a-seo-title-receipt-'.Str::random(12).'.json';
        $options = array_merge([
            '--package' => $packagePath,
            '--confirm-package-sha256' => hash_file('sha256', $packagePath),
            '--target-env' => 'staging',
            '--operator-approved' => self::APPROVAL,
            '--allow-testing' => true,
            '--json' => true,
            '--output' => $output,
        ], $options);

        $exitCode = Artisan::call('personality:mbti-intp-a-seo-title-experiment', $options);
        $receipt = json_decode((string) File::get($output), true);
        File::delete($output);

        $this->assertIsArray($receipt);

        return [$exitCode, $receipt];
    }

    /**
     * @return array<string, mixed>
     */
    private function package(): array
    {
        $package = json_decode((string) File::get(base_path(self::PACKAGE_PATH)), true);
        $this->assertIsArray($package);

        return $package;
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function writeTemporaryPackage(array $package): string
    {
        $path = sys_get_temp_dir().'/fm-intp-a-seo-title-package-'.Str::random(12).'.json';
        File::put($path, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $this->beforeApplicationDestroyed(static fn () => File::delete($path));

        return $path;
    }

    /**
     * @return array{PersonalityProfile, PersonalityProfileVariant, PersonalityProfileVariantSeoMeta}
     */
    private function createAuthority(): array
    {
        $profile = PersonalityProfile::query()->create([
            'org_id' => 0,
            'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
            'type_code' => 'INTP',
            'canonical_type_code' => 'INTP',
            'slug' => 'intp',
            'locale' => 'zh-CN',
            'title' => 'INTP - 逻辑学家',
            'type_name' => '逻辑学家型',
            'nickname' => '逻辑学家',
            'keywords_json' => [],
            'status' => 'published',
            'is_public' => true,
            'is_indexable' => true,
            'published_at' => now(),
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
        ]);
        $variant = PersonalityProfileVariant::query()->create([
            'org_id' => 0,
            'personality_profile_id' => (int) $profile->id,
            'canonical_type_code' => 'INTP',
            'variant_code' => 'A',
            'runtime_type_code' => 'INTP-A',
            'type_name' => '逻辑学家',
            'nickname' => '逻辑学家',
            'keywords_json' => [],
            'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            'is_published' => true,
            'published_at' => now(),
        ]);
        $seoMeta = PersonalityProfileVariantSeoMeta::query()->create([
            'org_id' => 0,
            'personality_profile_variant_id' => (int) $variant->id,
            'seo_title' => self::CURRENT_TITLE,
            'seo_description' => self::CURRENT_DESCRIPTION,
            'canonical_url' => 'https://fermatmind.com/zh/personality/intp-a',
            'og_title' => self::CURRENT_TITLE,
            'og_description' => self::CURRENT_DESCRIPTION,
            'og_image_url' => null,
            'twitter_title' => self::CURRENT_TITLE,
            'twitter_description' => self::CURRENT_DESCRIPTION,
            'twitter_image_url' => null,
            'robots' => 'index,follow',
            'jsonld_overrides_json' => [
                'url' => 'https://fermatmind.com/zh/personality/intp-a',
                'name' => 'INTP-A 人格特点',
                'description' => self::CURRENT_DESCRIPTION,
            ],
        ]);

        return [$profile, $variant, $seoMeta];
    }
}
