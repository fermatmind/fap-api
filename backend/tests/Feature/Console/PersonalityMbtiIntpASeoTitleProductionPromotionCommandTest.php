<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\UsesIsolatedSqliteDatabase;
use Tests\TestCase;

final class PersonalityMbtiIntpASeoTitleProductionPromotionCommandTest extends TestCase
{
    use UsesIsolatedSqliteDatabase;

    private const PACKAGE_PATH = 'docs/seo/personality/experiments/zh-intp-a-seo-title-production-promotion-v1.json';

    private const CONTROL_SHA = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const OLD_TITLE = 'INTP-A 人格特点：分析建模、可能性探索和独立解题 | FermatMind';

    private const NEW_TITLE = 'INTP-A 是什么？人格特点、优势盲点与适合场景 | FermatMind';

    private const DESCRIPTION = '了解 INTP-A 的分析建模、可能性探索和独立解题、适合与不适合的场景、A/T 差异、职业、关系、压力应对、常见误解与 FAQ。内容仅用于自我理解和成长复盘。';

    public function test_dry_run_is_zero_write_and_validates_the_exact_baseline(): void
    {
        $this->createAuthority();

        [$exit, $receipt] = $this->runCommand('dry-run');

        $this->assertSame(0, $exit);
        $this->assertSame('planned', $receipt['status']);
        $this->assertFalse($receipt['writes_committed']);
        $this->assertSame(0, $receipt['seo_title_changes']);
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_write_changes_only_title_creates_marker_invalidates_cache_and_is_idempotent(): void
    {
        [$profile, $variant, $seo] = $this->createAuthority();
        $profileBefore = $profile->fresh()->getAttributes();
        $variantBefore = $variant->fresh()->getAttributes();
        $seoBefore = $seo->fresh()->getAttributes();
        $sectionsBefore = PersonalityProfileVariantSection::query()->get()->toArray();

        [$firstExit, $first] = $this->runCommand('write');
        [$secondExit, $second] = $this->runCommand('write');

        $this->assertSame(0, $firstExit);
        $this->assertSame(0, $secondExit);
        $this->assertSame('promoted_live', $first['status']);
        $this->assertSame(1, $first['seo_title_changes']);
        $this->assertSame(1, $first['audit_revision_created_count']);
        $this->assertSame(1, $first['cache_invalidations']);
        $this->assertSame('idempotent_promoted_live', $second['status']);
        $this->assertSame(1, $second['idempotent_count']);
        $this->assertSame(1, PersonalityProfileVariantRevision::query()->count());

        $profile->refresh();
        $variant->refresh();
        $seo->refresh();
        $seoAfter = $seo->getAttributes();
        $this->assertSame($profileBefore, $profile->getAttributes());
        $this->assertSame($variantBefore, $variant->getAttributes());
        $this->assertSame($sectionsBefore, PersonalityProfileVariantSection::query()->get()->toArray());
        $this->assertSame(self::NEW_TITLE, $seoAfter['seo_title']);
        unset($seoBefore['seo_title'], $seoBefore['updated_at'], $seoAfter['seo_title'], $seoAfter['updated_at']);
        $this->assertSame($seoBefore, $seoAfter);

        $snapshot = PersonalityProfileVariantRevision::query()->firstOrFail()->snapshot_json;
        $this->assertSame('personality.mbti-seo-field-override.v1', $snapshot['schema_version']);
        $this->assertSame('promoted_live', $snapshot['status']);
        $this->assertSame('personality_profile_variant_seo_meta.seo_title', $snapshot['change']['field']);
    }

    public function test_baseline_drift_and_unowned_prechange_fail_closed(): void
    {
        [, , $seo] = $this->createAuthority();
        $seo->update(['seo_title' => 'unowned pre-change']);

        [$exit, $receipt] = $this->runCommand('dry-run');

        $this->assertSame(1, $exit);
        $this->assertFalse($receipt['ok']);
        $this->assertStringContainsString('baseline drifted', $receipt['errors'][0]['message']);
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_same_promotion_id_with_different_package_fails_closed(): void
    {
        $this->createAuthority();
        [$firstExit] = $this->runCommand('write');
        $this->assertSame(0, $firstExit);

        $package = $this->package();
        $package['audit_note'] = 'different exact package bytes';
        $path = $this->temporaryPackage($package);
        [$secondExit, $receipt] = $this->runCommand('write', $path);

        $this->assertSame(1, $secondExit);
        $this->assertFalse($receipt['ok']);
        $this->assertStringContainsString('conflicting', $receipt['errors'][0]['message']);
        $this->assertSame(1, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_rollback_restores_old_title_and_blocks_automatic_repromotion(): void
    {
        [, , $seo] = $this->createAuthority();
        [$writeExit] = $this->runCommand('write');
        [$rollbackExit, $rollback] = $this->runCommand('rollback');
        [$retryExit, $retry] = $this->runCommand('write');

        $this->assertSame(0, $writeExit);
        $this->assertSame(0, $rollbackExit);
        $this->assertSame('rolled_back', $rollback['status']);
        $this->assertSame(1, $rollback['cache_invalidations']);
        $this->assertSame(self::OLD_TITLE, $seo->fresh()->seo_title);
        $this->assertSame(2, PersonalityProfileVariantRevision::query()->count());
        $this->assertSame(1, $retryExit);
        $this->assertStringContainsString('cannot be promoted automatically again', $retry['errors'][0]['message']);
    }

    public function test_invalid_marker_checksum_fails_closed(): void
    {
        [, $variant] = $this->createAuthority();
        PersonalityProfileVariantRevision::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'revision_no' => 1,
            'snapshot_json' => [
                'schema_version' => 'personality.mbti-seo-field-override.v1',
                'snapshot_sha256' => str_repeat('0', 64),
            ],
            'note' => 'corrupt marker',
            'created_at' => now(),
        ]);

        [$exit, $receipt] = $this->runCommand('dry-run');

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('checksum mismatch', $receipt['errors'][0]['message']);
    }

    public function test_cache_failure_rolls_back_title_and_marker(): void
    {
        [, , $seo] = $this->createAuthority();
        [$preflightExit] = $this->runCommand('dry-run');
        $this->assertSame(0, $preflightExit);
        Cache::shouldReceive('forever')->once()->andThrow(new RuntimeException('cache unavailable'));

        [$exit, $receipt] = $this->runCommand('write');

        $this->assertSame(1, $exit);
        $this->assertFalse($receipt['ok']);
        $this->assertSame(self::OLD_TITLE, $seo->fresh()->seo_title);
        $this->assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    /** @return array{int,array<string,mixed>} */
    private function runCommand(string $mode, ?string $packagePath = null): array
    {
        $packagePath ??= base_path(self::PACKAGE_PATH);
        $packageSha = hash_file('sha256', $packagePath);
        $approval = sprintf(
            'I authorize zh-CN INTP-A seo_title production promotion mode %s for control SHA %s, active revision %s, package SHA %s, staging run 31395530368, staging receipt d5bdc286f156f7b07f4694b0dc702461eeb64f1fdd359d0cfc42b22beef1d57a; no other content, publication, indexability, discoverability, or search change.',
            $mode,
            self::CONTROL_SHA,
            self::CONTROL_SHA,
            $packageSha,
        );
        $output = sys_get_temp_dir().'/fm-intp-a-production-promotion-'.Str::random(12).'.json';
        $exit = Artisan::call('personality:mbti-intp-a-seo-title-production-promotion', [
            '--package' => $packagePath,
            '--confirm-package-sha256' => $packageSha,
            '--control-sha' => self::CONTROL_SHA,
            '--active-revision' => self::CONTROL_SHA,
            '--staging-run-id' => '31395530368',
            '--staging-receipt-sha256' => 'd5bdc286f156f7b07f4694b0dc702461eeb64f1fdd359d0cfc42b22beef1d57a',
            '--operator-approved' => $approval,
            '--'.$mode => true,
            '--allow-testing' => true,
            '--json' => true,
            '--output' => $output,
        ]);
        $receipt = json_decode((string) File::get($output), true);
        File::delete($output);
        $this->assertIsArray($receipt);

        return [$exit, $receipt];
    }

    /** @return array{PersonalityProfile,PersonalityProfileVariant,PersonalityProfileVariantSeoMeta} */
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
        PersonalityProfileVariantSection::query()->create([
            'personality_profile_variant_id' => (int) $variant->id,
            'section_key' => 'core_traits',
            'render_variant' => 'rich_text',
            'body_md' => 'unchanged body',
            'body_html' => '<p>unchanged body</p>',
            'payload_json' => [],
            'sort_order' => 10,
            'is_enabled' => true,
        ]);
        $seo = PersonalityProfileVariantSeoMeta::query()->create([
            'org_id' => 0,
            'personality_profile_variant_id' => (int) $variant->id,
            'seo_title' => self::OLD_TITLE,
            'seo_description' => self::DESCRIPTION,
            'canonical_url' => 'https://fermatmind.com/zh/personality/intp-a',
            'og_title' => self::OLD_TITLE,
            'og_description' => self::DESCRIPTION,
            'og_image_url' => null,
            'twitter_title' => self::OLD_TITLE,
            'twitter_description' => self::DESCRIPTION,
            'twitter_image_url' => null,
            'robots' => 'index,follow',
            'jsonld_overrides_json' => [
                'url' => 'https://fermatmind.com/zh/personality/intp-a',
                'name' => 'INTP-A 人格特点',
                'description' => self::DESCRIPTION,
            ],
        ]);

        return [$profile, $variant, $seo];
    }

    /** @return array<string,mixed> */
    private function package(): array
    {
        $package = json_decode((string) File::get(base_path(self::PACKAGE_PATH)), true);
        $this->assertIsArray($package);

        return $package;
    }

    /** @param array<string,mixed> $package */
    private function temporaryPackage(array $package): string
    {
        $path = sys_get_temp_dir().'/fm-intp-a-production-package-'.Str::random(12).'.json';
        File::put($path, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $this->beforeApplicationDestroyed(static fn () => File::delete($path));

        return $path;
    }
}
