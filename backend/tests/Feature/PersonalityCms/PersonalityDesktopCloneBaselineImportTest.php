<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantCloneContent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PersonalityDesktopCloneBaselineImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_publishes_32_full_code_zh_clone_content_and_is_idempotent(): void
    {
        $this->seedZhVariantsForAllMbtiBaseTypes();

        $this->artisan('personality:import-desktop-clone-baseline', [
            '--locale' => ['zh-CN'],
            '--status' => 'published',
            '--upsert' => true,
            '--source-dir' => '../content_baselines/personality_clone',
        ])
            ->expectsOutputToContain('rows_found=32')
            ->expectsOutputToContain('will_create=32')
            ->expectsOutputToContain('will_update=0')
            ->expectsOutputToContain('will_skip=0')
            ->assertExitCode(0);

        $this->assertSame(32, PersonalityProfileVariantCloneContent::query()->count());
        $this->assertSame(
            32,
            PersonalityProfileVariantCloneContent::query()
                ->where('status', PersonalityProfileVariantCloneContent::STATUS_PUBLISHED)
                ->whereNotNull('published_at')
                ->count(),
        );
        $this->assertSame(
            32,
            (int) DB::table('personality_profile_variant_clone_contents')
                ->join(
                    'personality_profile_variants',
                    'personality_profile_variants.id',
                    '=',
                    'personality_profile_variant_clone_contents.personality_profile_variant_id'
                )
                ->distinct('personality_profile_variants.runtime_type_code')
                ->count('personality_profile_variants.runtime_type_code'),
        );

        $this->artisan('personality:import-desktop-clone-baseline', [
            '--locale' => ['zh-CN'],
            '--status' => 'published',
            '--upsert' => true,
            '--source-dir' => '../content_baselines/personality_clone',
        ])
            ->expectsOutputToContain('rows_found=32')
            ->expectsOutputToContain('will_create=0')
            ->expectsOutputToContain('will_update=0')
            ->expectsOutputToContain('will_skip=32')
            ->assertExitCode(0);

        $this->assertSame(32, PersonalityProfileVariantCloneContent::query()->count());
    }

    public function test_import_fails_when_target_variant_is_missing(): void
    {
        $this->artisan('personality:import-desktop-clone-baseline', [
            '--locale' => ['zh-CN'],
            '--type' => ['INFJ-A'],
            '--status' => 'published',
            '--source-dir' => '../content_baselines/personality_clone',
        ])
            ->expectsOutputToContain('Missing personality_profile_variant for full_code=INFJ-A locale=zh-CN.')
            ->assertExitCode(1);

        $this->assertSame(0, PersonalityProfileVariantCloneContent::query()->count());
    }

    private function seedZhVariantsForAllMbtiBaseTypes(): void
    {
        foreach ($this->mbtiBaseTypes() as $baseCode) {
            $profile = PersonalityProfile::query()->create([
                'org_id' => 0,
                'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
                'type_code' => $baseCode,
                'slug' => strtolower($baseCode),
                'locale' => 'zh-CN',
                'title' => $baseCode.' profile',
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => true,
                'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
                'published_at' => now()->subMinute(),
            ]);

            foreach (['A', 'T'] as $variantCode) {
                PersonalityProfileVariant::query()->create([
                    'personality_profile_id' => (int) $profile->id,
                    'canonical_type_code' => $baseCode,
                    'variant_code' => $variantCode,
                    'runtime_type_code' => $baseCode.'-'.$variantCode,
                    'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
                    'is_published' => true,
                    'published_at' => now()->subMinute(),
                ]);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function mbtiBaseTypes(): array
    {
        return [
            'INTJ', 'INTP', 'ENTJ', 'ENTP',
            'INFJ', 'INFP', 'ENFJ', 'ENFP',
            'ISTJ', 'ISFJ', 'ESTJ', 'ESFJ',
            'ISTP', 'ISFP', 'ESTP', 'ESFP',
        ];
    }
}
