<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbtiFullProfileImportDryRunCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_plans_all_32_chinese_profile_assets_without_writes(): void
    {
        $this->seedPublishedIndexableProfiles();
        $packagePaths = $this->writeApprovedPackages();

        $exitCode = Artisan::call('personality:mbti-full-profile-import-dry-run', [
            '--package' => $packagePaths,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertTrue($payload['ok']);
        self::assertSame('pass', $payload['status']);
        self::assertTrue($payload['dry_run_only']);
        self::assertFalse($payload['write_supported_in_this_pr']);
        self::assertFalse($payload['writes_committed']);
        self::assertFalse($payload['cms_write_attempted']);
        self::assertSame(4, $payload['package_count']);
        self::assertSame(32, $payload['record_count']);
        self::assertSame(28, $payload['repair_record_count']);
        self::assertSame(4, $payload['verify_only_record_count']);
        self::assertCount(32, $payload['rows']);
        self::assertCount(32, array_unique(array_column($payload['rows'], 'slug')));
        self::assertSame('zh-CN', $payload['rows'][0]['locale']);
        self::assertTrue($payload['rows'][0]['existing_target']['effective_indexable']);
        self::assertSame('index,follow', $payload['rows'][0]['existing_target']['variant_robots']);
        self::assertSame(0, PersonalityProfileVariantRevision::query()->count());

        $repeatExitCode = Artisan::call('personality:mbti-full-profile-import-dry-run', [
            '--package' => array_reverse($packagePaths),
            '--dry-run' => true,
            '--json' => true,
        ]);
        $repeatPayload = $this->jsonOutput();

        self::assertSame(0, $repeatExitCode);
        self::assertSame($payload['idempotency_key'], $repeatPayload['idempotency_key']);
        self::assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_it_fails_closed_when_an_existing_profile_is_not_indexable(): void
    {
        $this->seedPublishedIndexableProfiles();
        PersonalityProfile::query()->where('canonical_type_code', 'ISTJ')->update(['is_indexable' => false]);

        $exitCode = Artisan::call('personality:mbti-full-profile-import-dry-run', [
            '--package' => $this->writeApprovedPackages(),
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertContains('existing_target_not_indexable', array_column($payload['errors'], 'code'));
        self::assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_it_requires_artifact_only_and_rejects_declared_side_effects(): void
    {
        $this->seedPublishedIndexableProfiles();
        $packagePaths = $this->writeApprovedPackages();
        $package = json_decode((string) File::get($packagePaths[0]), true, 512, JSON_THROW_ON_ERROR);
        $package['assets'][0]['handoff_policy']['cms_write_attempted'] = true;
        File::put($packagePaths[0], (string) json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $exitCode = Artisan::call('personality:mbti-full-profile-import-dry-run', [
            '--package' => $packagePaths,
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertContains('handoff_side_effect_declared', array_column($payload['errors'], 'code'));
        self::assertSame(0, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_it_refuses_write_mode_before_reading_a_package(): void
    {
        $exitCode = Artisan::call('personality:mbti-full-profile-import-dry-run', [
            '--package' => '/not/a/real/package.json',
            '--write' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertFalse($payload['write_supported_in_this_pr']);
        self::assertStringContainsString('--write is intentionally unsupported', (string) ($payload['errors'][0]['message'] ?? ''));
    }

    private function seedPublishedIndexableProfiles(): void
    {
        foreach (PersonalityProfile::BASE_TYPE_CODES as $typeCode) {
            $profile = PersonalityProfile::query()->create([
                'org_id' => 0,
                'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
                'type_code' => $typeCode,
                'canonical_type_code' => $typeCode,
                'slug' => strtolower($typeCode),
                'locale' => 'zh-CN',
                'title' => $typeCode.' 人格',
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
                'published_at' => now()->subMinute(),
                'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            ]);

            foreach (['A', 'T'] as $variantCode) {
                $variant = PersonalityProfileVariant::query()->create([
                    'org_id' => 0,
                    'personality_profile_id' => (int) $profile->id,
                    'canonical_type_code' => $typeCode,
                    'variant_code' => $variantCode,
                    'runtime_type_code' => $typeCode.'-'.$variantCode,
                    'type_name' => $typeCode.' '.$variantCode,
                    'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
                    'is_published' => true,
                    'published_at' => now()->subMinute(),
                ]);
                PersonalityProfileVariantSeoMeta::query()->create([
                    'org_id' => 0,
                    'personality_profile_variant_id' => (int) $variant->id,
                    'robots' => 'index,follow',
                ]);
            }
        }
    }

    /** @return list<string> */
    private function writeApprovedPackages(): array
    {
        $cohorts = [
            'NT' => ['INTJ', 'INTP', 'ENTJ', 'ENTP'],
            'NF' => ['INFJ', 'INFP', 'ENFJ', 'ENFP'],
            'SJ' => ['ISTJ', 'ISFJ', 'ESTJ', 'ESFJ'],
            'SP' => ['ISTP', 'ISFP', 'ESTP', 'ESFP'],
        ];
        $paths = [];
        foreach ($cohorts as $cohort => $types) {
            $assets = [];
            foreach ($types as $typeCode) {
                foreach (['A', 'T'] as $variantCode) {
                    $assets[] = $this->asset($typeCode, $variantCode);
                }
            }
            $package = [
                'artifact' => 'MBTI-PROFILE-'.$cohort.'-'.(['NT' => '31', 'NF' => '32', 'SJ' => '33', 'SP' => '34'][$cohort]).'-CONTENT-PACKAGE',
                'assets' => $assets,
            ];
            $path = sys_get_temp_dir().'/mbti-cms-profile-37-'.strtolower($cohort).'-'.bin2hex(random_bytes(4)).'.json';
            File::put($path, (string) json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $paths[] = $path;
        }

        return $paths;
    }

    /** @return array<string,mixed> */
    private function asset(string $typeCode, string $variantCode): array
    {
        $slug = strtolower($typeCode).'-'.strtolower($variantCode);
        $verifyOnly = in_array($slug, ['istj-a', 'istp-a', 'isfp-a', 'esfj-a'], true);
        $cms = $verifyOnly ? null : [
            'title' => $typeCode.'-'.$variantCode.' 人格特点',
            'h1' => $typeCode.'-'.$variantCode.' 人格',
            'meta_description' => '了解 '.$typeCode.'-'.$variantCode.' 的定义、场景、常见误解与 A/T 差异。',
            'direct_answer' => $typeCode.'-'.$variantCode.' 是用于自我理解的 MBTI 人格变体描述，不用于诊断或筛选。',
            'sections' => array_map(static fn (string $key): array => [
                'key' => $key,
                'title' => $key.' 标题',
                'body' => $key.' 内容说明。',
            ], ['definition', 'suitable_for', 'not_suitable_for', 'common_misread', 'base16_difference', 'at_difference', 'career_scenarios', 'relationship_scenarios', 'stress_scenarios']),
            'faq' => array_map(static fn (int $index): array => ['question' => $typeCode.'-'.$variantCode.' 问题 '.$index, 'answer' => '回答 '.$index], range(1, 6)),
            'internal_links' => [
                ['href' => '/zh/personality'],
                ['href' => '/zh/tests/mbti-personality-test-16-personality-types'],
                ['href' => '/zh/personality/'.strtolower($typeCode).'-a-vs-'.strtolower($typeCode).'-t'],
                ['href' => '/zh/personality/intj-vs-intp'],
                ['href' => '/zh/personality/entj-vs-intj'],
            ],
        ];

        return [
            'asset_id' => 'MBTI-PROFILE-'.strtoupper($slug),
            'framework' => 'mbti64',
            'page_type' => 'profile',
            'locale' => 'zh',
            'path' => '/zh/personality/'.$slug,
            'mbti_type' => $typeCode,
            'variant' => $variantCode,
            'audit_status' => $verifyOnly ? 'verify_only' : 'needs_content_repair',
            'source_ledger' => [['source_id' => 'fixture-'.$slug]],
            'claim_boundary' => ['medical' => false, 'employment' => false],
            'handoff_policy' => [
                'artifact_only' => true,
                'cms_write_attempted' => false,
                'production_import_attempted' => false,
                'frontend_runtime_change_attempted' => false,
                'sitemap_llms_mutation_attempted' => false,
                'gsc_mutation_attempted' => false,
                'production_deploy_attempted' => false,
            ],
            'cms_fields' => $cms,
        ];
    }

    /** @return array<string,mixed> */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
