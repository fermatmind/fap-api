<?php

declare(strict_types=1);

namespace Tests\Feature\PersonalityCms;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantCloneContent;
use App\PersonalityCms\DesktopClone\PersonalityDesktopCloneAssetSlotSupport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PersonalityDesktopCloneBaselineImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_publishes_32_full_code_zh_clone_content_with_compatibility_fields_and_is_idempotent(): void
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

        $infjAContent = $this->cloneContentByRuntimeType('INFJ-A');
        $entjTContent = $this->cloneContentByRuntimeType('ENTJ-T');
        $istpAContent = $this->cloneContentByRuntimeType('ISTP-A');

        $this->assertSame('zh-CN', $infjAContent['locale']);
        $this->assertIsString(data_get($infjAContent, 'content_json.letters_intro.headline'));
        $this->assertNotSame('', trim((string) data_get($infjAContent, 'content_json.letters_intro.headline')));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.overview.paragraphs'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.career.strengths.items'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.career.weaknesses.items'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.growth.strengths.items'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.growth.weaknesses.items'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.relationships.strengths.items'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.relationships.weaknesses.items'));
        $this->assertIsString(data_get($infjAContent, 'content_json.chapters.career.matched_jobs.fit_bucket'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.career.matched_jobs.job_examples'));
        $this->assertIsString(data_get($infjAContent, 'content_json.chapters.career.matched_guides.summary'));
        // These modules are compatibility transition fields and must remain present
        // until removal gates are satisfied across import/consumer/tests.
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.career.career_ideas.items'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.career.work_styles.items'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.growth.what_energizes.items'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.growth.what_drains.items'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.relationships.superpowers.items'));
        $this->assertNotEmpty((array) data_get($infjAContent, 'content_json.chapters.relationships.pitfalls.items'));
        $this->assertSame(
            '你明显更容易被外部世界激活，但这种外倾仍保留着收回来整理自己的能力；你不是一直要热闹，而是更容易在互动中启动状态。',
            data_get($infjAContent, 'content_json.traits.axis_explainers.EI.E.light.band_nuance'),
        );
        $this->assertSame(
            '你的直觉倾向已经比较明确。你通常会比别人更早想到模式、方向和潜在空间，而不只盯着眼前事实。',
            data_get($infjAContent, 'content_json.traits.axis_explainers.SN.N.clear.band_nuance'),
        );
        $this->assertSame(
            '你的敏感倾向非常清楚。你会天然保持较高的自我要求和环境警觉度，这会带来推进力与精细度，但也更容易让你长期紧绷、难以彻底放松。',
            data_get($infjAContent, 'content_json.traits.axis_explainers.AT.T.strong.band_nuance'),
        );
        $this->assertNotSame('', trim((string) data_get($infjAContent, 'content_json.chapters.career.traits_unlock.title')));
        $this->assertNotSame('', trim((string) data_get($infjAContent, 'content_json.chapters.growth.traits_unlock.title')));
        $this->assertNotSame('', trim((string) data_get($infjAContent, 'content_json.chapters.relationships.traits_unlock.title')));
        $this->assertSame(
            data_get($infjAContent, 'content_json.chapters.career.influentialTraits.0.label'),
            data_get($infjAContent, 'content_json.chapters.career.traits_unlock.items.0.label'),
        );
        $this->assertSame(
            data_get($infjAContent, 'content_json.chapters.growth.influentialTraits.0.label'),
            data_get($infjAContent, 'content_json.chapters.growth.traits_unlock.items.0.label'),
        );
        $this->assertSame(
            data_get($infjAContent, 'content_json.chapters.relationships.influentialTraits.0.label'),
            data_get($infjAContent, 'content_json.chapters.relationships.traits_unlock.items.0.label'),
        );

        $this->assertNotSame('', trim((string) data_get($entjTContent, 'content_json.letters_intro.headline')));
        $this->assertNotSame('', trim((string) data_get($istpAContent, 'content_json.chapters.career.matched_jobs.summary')));
        $this->assertNotSame('', trim((string) data_get($entjTContent, 'content_json.chapters.growth.what_energizes.title')));
        $this->assertNotSame('', trim((string) data_get($istpAContent, 'content_json.chapters.relationships.superpowers.title')));

        $first = PersonalityProfileVariantCloneContent::query()->orderBy('id')->firstOrFail();
        $slotIds = array_column((array) $first->asset_slots_json, 'slot_id');

        $this->assertSame(PersonalityDesktopCloneAssetSlotSupport::allowedSlotIds(), $slotIds);
        $this->assertSame(
            0,
            collect((array) $first->asset_slots_json)
                ->where('status', PersonalityDesktopCloneAssetSlotSupport::STATUS_READY)
                ->count(),
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

    public function test_import_selected_type_does_not_overwrite_unselected_rows(): void
    {
        $this->seedZhVariantsForAllMbtiBaseTypes();

        $this->artisan('personality:import-desktop-clone-baseline', [
            '--locale' => ['zh-CN'],
            '--status' => 'published',
            '--upsert' => true,
            '--source-dir' => '../content_baselines/personality_clone',
        ])->assertExitCode(0);

        $infjRecord = $this->cloneContentModelByRuntimeType('INFJ-A');
        $infjContent = is_array($infjRecord->content_json) ? $infjRecord->content_json : [];
        $infjContent['hero']['summary'] = 'manually changed summary';
        $infjRecord->forceFill([
            'content_json' => $infjContent,
        ])->save();

        $entjBefore = $this->cloneContentModelByRuntimeType('ENTJ-T');
        $entjBeforeSnapshot = [
            'content_json' => $entjBefore->content_json,
            'asset_slots_json' => $entjBefore->asset_slots_json,
            'meta_json' => $entjBefore->meta_json,
            'schema_version' => $entjBefore->schema_version,
            'status' => $entjBefore->status,
            'published_at' => $entjBefore->published_at?->toISOString(),
        ];

        $this->artisan('personality:import-desktop-clone-baseline', [
            '--locale' => ['zh-CN'],
            '--type' => ['INFJ-A'],
            '--status' => 'published',
            '--upsert' => true,
            '--source-dir' => '../content_baselines/personality_clone',
        ])
            ->expectsOutputToContain('rows_found=1')
            ->expectsOutputToContain('will_create=0')
            ->expectsOutputToContain('will_update=1')
            ->expectsOutputToContain('will_skip=0')
            ->assertExitCode(0);

        $infjAfter = $this->cloneContentModelByRuntimeType('INFJ-A');
        $this->assertNotSame(
            'manually changed summary',
            trim((string) data_get($infjAfter->content_json, 'hero.summary')),
        );

        $entjAfter = $this->cloneContentModelByRuntimeType('ENTJ-T');
        $entjAfterSnapshot = [
            'content_json' => $entjAfter->content_json,
            'asset_slots_json' => $entjAfter->asset_slots_json,
            'meta_json' => $entjAfter->meta_json,
            'schema_version' => $entjAfter->schema_version,
            'status' => $entjAfter->status,
            'published_at' => $entjAfter->published_at?->toISOString(),
        ];
        $this->assertSame($entjBeforeSnapshot, $entjAfterSnapshot);
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

    public function test_import_fails_when_baseline_is_missing_full_code_rows(): void
    {
        $this->seedZhVariantsForAllMbtiBaseTypes();

        $sourceFile = base_path('../content_baselines/personality_clone/mbti_desktop_clone.zh-CN.json');
        $raw = file_get_contents($sourceFile);
        $this->assertIsString($raw);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['variants'] ?? null);

        $decoded['variants'] = array_values(array_filter(
            $decoded['variants'],
            static fn (mixed $variant): bool => is_array($variant) && strtoupper(trim((string) ($variant['full_code'] ?? ''))) !== 'INFJ-A',
        ));

        $tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'desktop-clone-baseline-'.uniqid('', true);
        $tempDir = $tempRoot.DIRECTORY_SEPARATOR.'personality_clone';
        mkdir($tempDir, 0777, true);
        mkdir($tempRoot.DIRECTORY_SEPARATOR.'personality', 0777, true);
        mkdir($tempRoot.DIRECTORY_SEPARATOR.'career_jobs', 0777, true);
        mkdir($tempRoot.DIRECTORY_SEPARATOR.'career_guides', 0777, true);
        file_put_contents(
            $tempDir.DIRECTORY_SEPARATOR.'mbti_desktop_clone.zh-CN.json',
            json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        copy(
            base_path('../content_baselines/personality/mbti.zh-CN.json'),
            $tempRoot.DIRECTORY_SEPARATOR.'personality'.DIRECTORY_SEPARATOR.'mbti.zh-CN.json',
        );
        copy(
            base_path('../content_baselines/career_jobs/career_jobs.zh-CN.json'),
            $tempRoot.DIRECTORY_SEPARATOR.'career_jobs'.DIRECTORY_SEPARATOR.'career_jobs.zh-CN.json',
        );
        copy(
            base_path('../content_baselines/career_guides/career_guides.zh-CN.json'),
            $tempRoot.DIRECTORY_SEPARATOR.'career_guides'.DIRECTORY_SEPARATOR.'career_guides.zh-CN.json',
        );

        $this->artisan('personality:import-desktop-clone-baseline', [
            '--locale' => ['zh-CN'],
            '--status' => 'published',
            '--upsert' => true,
            '--source-dir' => $tempDir,
        ])
            ->expectsOutputToContain('Desktop clone baseline locale zh-CN is missing full_code entries: INFJ-A')
            ->assertExitCode(1);

        $this->assertSame(0, PersonalityProfileVariantCloneContent::query()->count());
    }

    public function test_import_fails_when_personality_source_is_missing_required_compatibility_section(): void
    {
        $this->seedZhVariantsForAllMbtiBaseTypes();

        $sourceFile = base_path('../content_baselines/personality/mbti.zh-CN.json');
        $raw = file_get_contents($sourceFile);
        $this->assertIsString($raw);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['variants'] ?? null);

        $decoded['variants'] = array_map(static function (mixed $variant): mixed {
            if (! is_array($variant)) {
                return $variant;
            }

            if (strtoupper(trim((string) ($variant['runtime_type_code'] ?? ''))) !== 'INFJ-A') {
                return $variant;
            }

            $variant['section_overrides'] = array_values(array_filter(
                (array) ($variant['section_overrides'] ?? []),
                static fn (mixed $section): bool => ! (
                    is_array($section)
                    && trim((string) ($section['section_key'] ?? '')) === 'growth.motivators'
                ),
            ));

            return $variant;
        }, $decoded['variants']);

        $tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'desktop-clone-p1-source-'.uniqid('', true);
        $tempDir = $tempRoot.DIRECTORY_SEPARATOR.'personality_clone';
        mkdir($tempDir, 0777, true);
        mkdir($tempRoot.DIRECTORY_SEPARATOR.'personality', 0777, true);
        mkdir($tempRoot.DIRECTORY_SEPARATOR.'career_jobs', 0777, true);
        mkdir($tempRoot.DIRECTORY_SEPARATOR.'career_guides', 0777, true);
        file_put_contents(
            $tempDir.DIRECTORY_SEPARATOR.'mbti_desktop_clone.zh-CN.json',
            file_get_contents(base_path('../content_baselines/personality_clone/mbti_desktop_clone.zh-CN.json')) ?: '',
        );
        file_put_contents(
            $tempRoot.DIRECTORY_SEPARATOR.'personality'.DIRECTORY_SEPARATOR.'mbti.zh-CN.json',
            json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        copy(
            base_path('../content_baselines/career_jobs/career_jobs.zh-CN.json'),
            $tempRoot.DIRECTORY_SEPARATOR.'career_jobs'.DIRECTORY_SEPARATOR.'career_jobs.zh-CN.json',
        );
        copy(
            base_path('../content_baselines/career_guides/career_guides.zh-CN.json'),
            $tempRoot.DIRECTORY_SEPARATOR.'career_guides'.DIRECTORY_SEPARATOR.'career_guides.zh-CN.json',
        );

        $this->artisan('personality:import-desktop-clone-baseline', [
            '--locale' => ['zh-CN'],
            '--status' => 'published',
            '--upsert' => true,
            '--source-dir' => $tempDir,
        ])
            ->expectsOutputToContain('Missing required source section growth.motivators for INFJ-A (zh-CN).')
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

    /**
     * @return array{locale:string,content_json:array<string,mixed>}
     */
    private function cloneContentByRuntimeType(string $runtimeTypeCode): array
    {
        /** @var object{locale:string,content_json:mixed}|null $row */
        $row = DB::table('personality_profile_variant_clone_contents')
            ->join(
                'personality_profile_variants',
                'personality_profile_variants.id',
                '=',
                'personality_profile_variant_clone_contents.personality_profile_variant_id'
            )
            ->join(
                'personality_profiles',
                'personality_profiles.id',
                '=',
                'personality_profile_variants.personality_profile_id'
            )
            ->select([
                'personality_profiles.locale',
                'personality_profile_variant_clone_contents.content_json',
            ])
            ->where('personality_profile_variants.runtime_type_code', strtoupper(trim($runtimeTypeCode)))
            ->first();

        $this->assertNotNull($row);

        $content = $row->content_json;
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = is_array($decoded) ? $decoded : [];
        }

        return [
            'locale' => (string) $row->locale,
            'content_json' => is_array($content) ? $content : [],
        ];
    }

    private function cloneContentModelByRuntimeType(string $runtimeTypeCode): PersonalityProfileVariantCloneContent
    {
        /** @var PersonalityProfileVariantCloneContent|null $record */
        $record = PersonalityProfileVariantCloneContent::query()
            ->whereHas('variant', function ($query) use ($runtimeTypeCode): void {
                $query->where('runtime_type_code', strtoupper(trim($runtimeTypeCode)));
            })
            ->first();

        $this->assertNotNull($record);

        return $record;
    }
}
