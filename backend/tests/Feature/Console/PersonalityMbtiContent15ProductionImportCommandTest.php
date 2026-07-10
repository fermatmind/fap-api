<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\MbtiCrossTypeComparisonAuthority;
use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileSection;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use App\Models\PersonalityProfileVariantSection;
use App\Models\PersonalityProfileVariantSeoMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbtiContent15ProductionImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_PACKAGE_SHA = '75244fa4af3c234851519eba5a426daf8766c13e7c4b2bc9e94d5a5855ce6ccb';

    private const AUTHORIZATION_PAYLOAD_SHA = 'be0d1bb584c15f383322c9e5aff560709c46ea1e34d135cb9ced6d1e4601fe15';

    public function test_dry_run_and_exact_authorized_write_import_the_nine_record_batch_without_releasing_indexability(): void
    {
        $this->seedPublicProfiles();
        [$packagePath, $authorizationPath] = $this->writeFixturePair($this->validPackage(), $this->validAuthorizationPackage());

        $dryRunExitCode = Artisan::call('personality:mbti-content15-production-import', $this->commandOptions($packagePath, $authorizationPath, true));
        $dryRun = $this->jsonOutput();

        self::assertSame(0, $dryRunExitCode);
        self::assertTrue($dryRun['ok']);
        self::assertFalse($dryRun['writes_committed']);
        self::assertSame(9, count($dryRun['rows']));
        self::assertSame(0, PersonalityProfileVariantSection::query()->count());
        self::assertSame(0, MbtiCrossTypeComparisonAuthority::query()->count());

        $writeExitCode = Artisan::call('personality:mbti-content15-production-import', array_merge(
            $this->commandOptions($packagePath, $authorizationPath, false),
            [
                '--production-import-authorized' => true,
                '--no-index' => true,
                '--no-sitemap' => true,
                '--no-llms' => true,
                '--no-search-release' => true,
            ],
        ));
        $written = $this->jsonOutput();

        self::assertSame(0, $writeExitCode);
        self::assertTrue($written['ok']);
        self::assertTrue($written['writes_committed']);
        self::assertTrue($written['cms_write_attempted']);
        self::assertSame(9, $written['published_content_count']);
        self::assertFalse($written['index_attempted']);
        self::assertFalse($written['sitemap_llms_release_attempted']);
        self::assertFalse($written['search_release_attempted']);

        $istj = $this->variantFor('ISTJ-A');
        $section = PersonalityProfileVariantSection::query()
            ->where('personality_profile_variant_id', (int) $istj->id)
            ->where('section_key', 'direct_answer')
            ->firstOrFail();
        self::assertSame('fixture section for istj-a', $section->body_md);
        self::assertSame(4, PersonalityProfileVariantRevision::query()->count());

        $seo = PersonalityProfileVariantSeoMeta::query()
            ->where('personality_profile_variant_id', (int) $istj->id)
            ->firstOrFail();
        self::assertSame('noindex,follow', $seo->robots);
        self::assertSame('ISTJ-A | FermatMind', $seo->seo_title);

        $intp = $this->profileFor('INTP');
        $atComparison = PersonalityProfileSection::query()
            ->where('profile_id', (int) $intp->id)
            ->where('section_key', 'mbti64_comparison_a_vs_t')
            ->firstOrFail();
        self::assertSame('mbti_content15_production_import_v1', data_get($atComparison->payload_json, 'source'));
        self::assertTrue((bool) data_get($atComparison->payload_json, 'indexability_held'));

        $authority = MbtiCrossTypeComparisonAuthority::query()->where('slug', 'entj-vs-intj')->firstOrFail();
        self::assertTrue((bool) $authority->is_public);
        self::assertFalse((bool) $authority->is_indexable);
        self::assertFalse((bool) $authority->sitemap_eligible);
        self::assertFalse((bool) $authority->llms_eligible);
        self::assertFalse((bool) $authority->search_submission_eligible);
        self::assertSame('held_for_mbti_index_24', $authority->indexability_status);
        self::assertSame('ENTJ', $authority->left_type_code);
        self::assertSame('INTJ', $authority->right_type_code);
        self::assertSame(4, MbtiCrossTypeComparisonAuthority::query()->count());
    }

    public function test_write_is_refused_before_package_reading_without_every_exact_execution_guard(): void
    {
        $exitCode = Artisan::call('personality:mbti-content15-production-import', [
            '--package' => '/tmp/no-read.json',
            '--authorization-package' => '/tmp/no-read-auth.json',
            '--write' => true,
            '--json' => true,
        ]);
        $payload = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($payload['ok']);
        self::assertFalse($payload['writes_committed']);
        self::assertFalse($payload['cms_write_attempted']);
        self::assertStringContainsString('--production-import-authorized is required', (string) data_get($payload, 'errors.0.message'));
    }

    /**
     * @return array<string,mixed>
     */
    private function commandOptions(string $packagePath, string $authorizationPath, bool $dryRun): array
    {
        return [
            '--package' => $packagePath,
            '--authorization-package' => $authorizationPath,
            '--source-package-sha256' => self::SOURCE_PACKAGE_SHA,
            '--authorization-payload-sha256' => self::AUTHORIZATION_PAYLOAD_SHA,
            '--import-scope-mode' => 'top_blocker_batch_only',
            '--record-count' => '9',
            $dryRun ? '--dry-run' : '--write' => true,
            '--json' => true,
        ];
    }

    private function seedPublicProfiles(): void
    {
        foreach (['ISTJ', 'ISTP', 'ISFP', 'ESFJ', 'INTP'] as $typeCode) {
            $profile = PersonalityProfile::query()->create([
                'org_id' => 0,
                'scale_code' => PersonalityProfile::SCALE_CODE_MBTI,
                'type_code' => $typeCode,
                'canonical_type_code' => $typeCode,
                'slug' => strtolower($typeCode),
                'locale' => 'zh-CN',
                'title' => $typeCode,
                'status' => 'published',
                'is_public' => true,
                'is_indexable' => false,
                'published_at' => now()->subMinute(),
                'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
            ]);

            if ($typeCode === 'INTP') {
                continue;
            }

            PersonalityProfileVariant::query()->create([
                'org_id' => 0,
                'personality_profile_id' => (int) $profile->id,
                'canonical_type_code' => $typeCode,
                'variant_code' => 'A',
                'runtime_type_code' => $typeCode.'-A',
                'type_name' => $typeCode.' A',
                'schema_version' => PersonalityProfile::SCHEMA_VERSION_V2,
                'is_published' => true,
                'published_at' => now()->subMinute(),
            ]);
        }
    }

    private function variantFor(string $runtimeTypeCode): PersonalityProfileVariant
    {
        return PersonalityProfileVariant::query()->where('runtime_type_code', $runtimeTypeCode)->firstOrFail();
    }

    private function profileFor(string $typeCode): PersonalityProfile
    {
        return PersonalityProfile::query()->where('canonical_type_code', $typeCode)->firstOrFail();
    }

    /**
     * @return array<string,mixed>
     */
    private function validPackage(): array
    {
        return [
            'id' => 'MBTI-CMS-22',
            'artifact' => 'fixture',
            'status' => 'final_dry_run_package_ready',
            'exact_package' => ['package_sha256' => self::SOURCE_PACKAGE_SHA],
            'records' => [
                $this->profileRecord('istj-a'),
                $this->profileRecord('istp-a'),
                $this->profileRecord('isfp-a'),
                $this->profileRecord('esfj-a'),
                $this->comparisonRecord('intp-a-vs-intp-t', 'INTP-A', 'INTP-T'),
                $this->comparisonRecord('intj-vs-intp', 'INTJ', 'INTP'),
                $this->comparisonRecord('entj-vs-intj', 'ENTJ', 'INTJ'),
                $this->comparisonRecord('infj-vs-infp', 'INFJ', 'INFP'),
                $this->comparisonRecord('istj-vs-isfj', 'ISTJ', 'ISFJ'),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validAuthorizationPackage(): array
    {
        $records = [];
        foreach ($this->validPackage()['records'] as $record) {
            $records[] = [
                'authorization_record_id' => str_replace('mbti-cms-22:', 'mbti-cms-23:', (string) $record['dry_run_record_id']),
                'source_dry_run_record_id' => $record['dry_run_record_id'],
                'exact_payload_sha256' => $record['exact_payload_sha256'],
            ];
        }

        return [
            'id' => 'MBTI-CMS-23',
            'authorization_package' => [
                'production_import_authorized' => false,
                'exact_authorization_payload_sha256' => self::AUTHORIZATION_PAYLOAD_SHA,
                'source_package_sha256' => self::SOURCE_PACKAGE_SHA,
                'import_scope_mode' => 'top_blocker_batch_only',
                'records' => $records,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function profileRecord(string $slug): array
    {
        return $this->record($slug, 'profile', strtoupper($slug), []);
    }

    /**
     * @return array<string,mixed>
     */
    private function comparisonRecord(string $slug, string $left, string $right): array
    {
        return $this->record($slug, 'comparison', $left.' VS '.$right, [
            'comparison_slug' => $slug,
            'left_code' => $left,
            'right_code' => $right,
        ]);
    }

    /**
     * @param  array<string,string>  $comparisonKey
     * @return array<string,mixed>
     */
    private function record(string $slug, string $kind, string $code, array $comparisonKey): array
    {
        $profile = $kind === 'profile';
        $sectionKeys = $profile
            ? ['direct_answer', 'who_it_fits', 'who_it_does_not_fit', 'common_misunderstanding', 'at_difference', 'career_scenario', 'relationship_scenario', 'stress_scenario']
            : ['direct_answer', 'quick_judgment_table', 'easy_misread', 'real_scenario_differences', 'do_not_misjudge'];
        $payloadSha = hash('sha256', $slug.'-'.$kind);
        $cmsKey = $profile
            ? ['locale' => 'zh-CN', 'framework' => 'mbti', 'profile_code' => $code, 'slug' => $slug]
            : array_merge(['locale' => 'zh-CN', 'framework' => 'mbti'], $comparisonKey);

        return [
            'dry_run_record_id' => 'mbti-cms-22:'.$kind.':'.$slug,
            'kind' => $kind,
            'target_path' => '/zh/personality/'.$slug,
            'locale' => 'zh-CN',
            'slug' => $slug,
            'code' => $code,
            'cms_resource' => $profile ? 'personality_profile' : 'personality_comparison',
            'cms_key' => $cmsKey,
            'import_action' => $profile ? 'upsert_profile_content_draft' : 'upsert_comparison_content_draft',
            'approval_state' => 'approved_for_final_dry_run',
            'exact_payload_sha256' => $payloadSha,
            'schema_validation' => [
                'status' => 'pass',
                'section_keys' => $sectionKeys,
                'faq_count' => $profile ? 6 : 4,
                'indexability_held' => true,
            ],
            'dry_run_payload' => ['payload' => $this->payload($slug, $kind, $sectionKeys)],
        ];
    }

    /**
     * @param  list<string>  $sectionKeys
     * @return array<string,mixed>
     */
    private function payload(string $slug, string $kind, array $sectionKeys): array
    {
        $sections = [];
        foreach ($sectionKeys as $index => $sectionKey) {
            $section = [
                'key' => $sectionKey,
                'title' => $sectionKey,
                'body' => 'fixture section for '.$slug,
            ];
            if ($sectionKey === 'quick_judgment_table') {
                $section['rows'] = [['dimension' => '判断入口', 'left' => '左', 'right' => '右']];
            }
            $sections[] = $section;
        }

        return [
            'title' => strtoupper($slug),
            'summary' => 'fixture summary '.$slug,
            'seo' => [
                'title' => strtoupper($slug).' | FermatMind',
                'meta_description' => 'fixture description '.$slug,
            ],
            'canonical' => 'https://fermatmind.com/zh/personality/'.$slug,
            'robots' => 'noindex,follow',
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'sections' => $sections,
            'faq' => array_map(static fn (int $number): array => ['question' => 'Q'.$number, 'answer' => 'A'.$number], range(1, $kind === 'profile' ? 6 : 4)),
            'internal_links' => [
                ['href' => '/zh/personality', 'anchor_text' => 'MBTI 人格', 'safe_public_route' => true],
                ['href' => '/zh/tests/mbti-personality-test-16-personality-types', 'anchor_text' => 'MBTI 测试', 'safe_public_route' => true],
                ['href' => '/zh/personality/intp-a-vs-intp-t', 'anchor_text' => '热门对比', 'safe_public_route' => true],
            ],
            'method_boundary' => ['medical_diagnostic_claim' => false],
            'evidence_notes' => ['fixture'],
            'claim_boundary' => '人格内容仅用于自我理解，不用于诊断、筛选或决定。',
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorizationPackage
     * @return array{0:string,1:string}
     */
    private function writeFixturePair(array $package, array $authorizationPackage): array
    {
        $prefix = sys_get_temp_dir().'/mbti-cms27-'.bin2hex(random_bytes(6));
        $packagePath = $prefix.'-source.json';
        $authorizationPath = $prefix.'-authorization.json';
        File::put($packagePath, (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::put($authorizationPath, (string) json_encode($authorizationPackage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$packagePath, $authorizationPath];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }
}
