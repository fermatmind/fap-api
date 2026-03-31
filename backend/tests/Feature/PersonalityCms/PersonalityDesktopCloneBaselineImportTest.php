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

        $this->assertNotSame('', trim((string) data_get($entjTContent, 'content_json.letters_intro.headline')));
        $this->assertNotSame('', trim((string) data_get($istpAContent, 'content_json.chapters.career.matched_jobs.summary')));

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
}
