<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\PersonalityProfile;
use App\Models\PersonalityProfileRevision;
use App\Models\PersonalityProfileVariant;
use App\Models\PersonalityProfileVariantRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbtiFullCmsImportCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SOURCE_PACKAGE_SHA = '840288581ce02e26afdd40dde1e25cf995fe334791b0a306929a13c76247a78d';

    private const AUTHORIZATION_PAYLOAD_SHA = 'e44d567ad6092d61076ae70009e5cfa39d1d7b3f5b3a78367e0d241a28ede31e';

    public function test_dry_run_stages_the_complete_43_record_package_only_as_draft_revisions(): void
    {
        $this->seedPublishedProfiles();
        $packagePath = $this->writePackage($this->validPackage());

        $exitCode = Artisan::call('personality:mbti-full-cms-import', $this->commandOptions($packagePath, true));
        $summary = $this->jsonOutput();

        self::assertSame(0, $exitCode);
        self::assertTrue($summary['ok']);
        self::assertTrue($summary['dry_run']);
        self::assertFalse($summary['cms_write_attempted']);
        self::assertFalse($summary['writes_committed']);
        self::assertSame(43, $summary['row_count']);
        self::assertSame(28, $summary['profile_row_count']);
        self::assertSame(15, $summary['at_comparison_row_count']);
        self::assertSame(0, PersonalityProfileRevision::query()->count());
        self::assertSame(0, PersonalityProfileVariantRevision::query()->count());
        self::assertSame(43, count($summary['readback_manifest']));
        self::assertSame('draft_only', $summary['readback_manifest'][0]['visibility']);
    }

    public function test_exact_authorized_write_is_idempotent_and_never_promotes_the_public_projection(): void
    {
        $this->seedPublishedProfiles();
        $packagePath = $this->writePackage($this->validPackage());

        $firstExitCode = Artisan::call('personality:mbti-full-cms-import', $this->commandOptions($packagePath, false));
        $first = $this->jsonOutput();

        self::assertSame(0, $firstExitCode);
        self::assertTrue($first['ok']);
        self::assertTrue($first['cms_write_attempted']);
        self::assertTrue($first['writes_committed']);
        self::assertSame(43, $first['staged_draft_count']);
        self::assertSame(0, $first['published_content_count']);
        self::assertFalse($first['index_attempted']);
        self::assertFalse($first['sitemap_llms_release_attempted']);
        self::assertFalse($first['search_release_attempted']);
        self::assertSame(15, PersonalityProfileRevision::query()->count());
        self::assertSame(28, PersonalityProfileVariantRevision::query()->count());
        self::assertSame('draft_only', data_get(PersonalityProfileVariantRevision::query()->firstOrFail()->snapshot_json, 'mbti_cms_import_40_profile_draft_v1.visibility'));
        self::assertFalse((bool) data_get(PersonalityProfileRevision::query()->firstOrFail()->snapshot_json, 'mbti_cms_import_40_at_comparison_draft_v1.public_projection_promoted'));

        $secondExitCode = Artisan::call('personality:mbti-full-cms-import', $this->commandOptions($packagePath, false));
        $second = $this->jsonOutput();

        self::assertSame(0, $secondExitCode);
        self::assertTrue($second['ok']);
        self::assertSame(0, $second['staged_draft_count']);
        self::assertSame(43, $second['skipped_existing_count']);
        self::assertSame(15, PersonalityProfileRevision::query()->count());
        self::assertSame(28, PersonalityProfileVariantRevision::query()->count());
    }

    public function test_mismatch_unknown_target_and_partial_batches_fail_closed_without_partial_writes(): void
    {
        $this->seedPublishedProfiles();

        $invalidPackages = [
            'authorization' => function (array $package): array {
                $package['exact_package']['authorization_payload_sha256'] = 'not-authorized';

                return $package;
            },
            'partial' => function (array $package): array {
                array_pop($package['repair_records']);

                return $package;
            },
            'unknown-target' => function (array $package): array {
                $package['repair_records'][0]['slug'] = 'unknown-a';

                return $package;
            },
            'wrong-locale' => function (array $package): array {
                $package['repair_records'][0]['locale'] = 'en';

                return $package;
            },
            'wrong-kind' => function (array $package): array {
                $package['repair_records'][0]['entity_kind'] = 'cross_type_comparison';

                return $package;
            },
            'pre-state' => function (array $package): array {
                $package['repair_records'][0]['expected_pre_state']['record_must_exist'] = false;

                return $package;
            },
        ];

        foreach ($invalidPackages as $name => $mutate) {
            $exitCode = Artisan::call('personality:mbti-full-cms-import', $this->commandOptions($this->writePackage($mutate($this->validPackage()), $name), false));
            $summary = $this->jsonOutput();

            self::assertSame(1, $exitCode, $name);
            self::assertFalse($summary['ok'], $name);
            self::assertFalse($summary['writes_committed'], $name);
            self::assertSame(0, PersonalityProfileRevision::query()->count(), $name);
            self::assertSame(0, PersonalityProfileVariantRevision::query()->count(), $name);
        }
    }

    public function test_write_requires_every_explicit_production_safety_guard_before_reading_the_package(): void
    {
        $exitCode = Artisan::call('personality:mbti-full-cms-import', [
            '--package' => '/tmp/not-read.json',
            '--write' => true,
            '--json' => true,
        ]);
        $summary = $this->jsonOutput();

        self::assertSame(1, $exitCode);
        self::assertFalse($summary['ok']);
        self::assertStringContainsString('--production-import-authorized is required', (string) data_get($summary, 'errors.0.message'));
    }

    /** @return array<string,mixed> */
    private function validPackage(): array
    {
        $profileBases = array_slice($this->baseTypes(), 0, 14);
        $records = [];
        foreach ($profileBases as $base) {
            foreach (['A', 'T'] as $variant) {
                $records[] = $this->profileRecord($base.'-'.$variant);
            }
        }
        foreach (array_slice($this->baseTypes(), 0, 15) as $base) {
            $records[] = $this->atComparisonRecord($base);
        }

        return [
            'artifact' => 'MBTI-CMS-APPROVAL-39-EXACT-43-RECORD-REPAIR-APPROVAL-PACKAGE',
            'status' => 'approved_for_fail_closed_importer_preflight',
            'exact_package' => [
                'source_package_sha256' => self::SOURCE_PACKAGE_SHA,
                'authorization_payload_sha256' => self::AUTHORIZATION_PAYLOAD_SHA,
                'import_scope_mode' => 'full_chinese_mbti_repair_batch_only',
                'record_count' => 43,
                'production_import_authorized' => false,
                'production_import_executed' => false,
            ],
            'repair_records' => $records,
        ];
    }

    /** @return array<string,mixed> */
    private function profileRecord(string $runtimeType): array
    {
        $slug = strtolower($runtimeType);
        $payload = $this->payload('profile', $slug, [
            'canonical_type_code' => substr($runtimeType, 0, 4),
            'runtime_type_code' => $runtimeType,
        ]);

        return $this->record('profile', $slug, $payload);
    }

    /** @return array<string,mixed> */
    private function atComparisonRecord(string $baseType): array
    {
        $slug = strtolower($baseType).'-a-vs-'.strtolower($baseType).'-t';
        $payload = $this->payload('at_comparison', $slug, [
            'base_type_code' => $baseType,
            'left_type_code' => $baseType.'-A',
            'right_type_code' => $baseType.'-T',
            'comparison_slug' => $slug,
        ]);

        return $this->record('at_comparison', $slug, $payload);
    }

    /** @param array<string,string> $identity @return array<string,mixed> */
    private function payload(string $kind, string $slug, array $identity): array
    {
        return [
            'locale' => 'zh-CN',
            'page_type' => $kind === 'profile' ? 'variant' : 'comparison',
            'comparison_kind' => $kind === 'at_comparison' ? 'at' : null,
            'identity' => $identity,
            'canonical' => 'https://fermatmind.com/zh/personality/'.$slug,
            'robots' => 'noindex,follow',
            'seo' => [
                'seo_title' => $slug.' title',
                'seo_description' => $slug.' description',
                'quick_answer_summary' => $slug.' answer',
            ],
            'content' => ['direct_answer' => $slug.' answer'],
            'content_sections' => [['id' => 'definition', 'title' => '定义', 'body' => $slug.' body']],
            'faq' => [['question' => $slug.' question', 'answer' => $slug.' answer']],
            'internal_links' => [['href' => '/zh/personality', 'label' => 'MBTI 人格']],
            'structured_metadata' => ['primary_query' => $slug],
            'import_visibility' => [
                'draft_only' => true,
                'no_public_promotion' => true,
                'no_indexability_mutation' => true,
                'no_sitemap_mutation' => true,
                'no_llms_mutation' => true,
            ],
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function record(string $kind, string $slug, array $payload): array
    {
        return [
            'approval_record_id' => 'approval:'.$slug,
            'source_asset_id' => 'asset:'.$slug,
            'entity_kind' => $kind,
            'slug' => $slug,
            'locale' => 'zh-CN',
            'target_path' => '/zh/personality/'.$slug,
            'expected_pre_state' => [
                'record_must_exist' => true,
                'public_projection_must_remain_unchanged_by_import' => true,
                'locale' => 'zh-CN',
                'framework' => 'mbti64',
                'entity_kind' => $kind,
            ],
            'expected_post_state' => [
                'revision_staged' => true,
                'revision_visibility' => 'draft_only',
                'public_projection_promoted' => false,
                'is_indexable_mutated' => false,
                'sitemap_eligibility_mutated' => false,
                'llms_eligibility_mutated' => false,
                'content_payload_sha256' => $this->hashJson($payload),
            ],
            'import_payload' => $payload,
        ];
    }

    /** @return array<string,mixed> */
    private function commandOptions(string $packagePath, bool $dryRun): array
    {
        $options = [
            '--package' => $packagePath,
            '--source-package-sha256' => self::SOURCE_PACKAGE_SHA,
            '--authorization-payload-sha256' => self::AUTHORIZATION_PAYLOAD_SHA,
            '--import-scope-mode' => 'full_chinese_mbti_repair_batch_only',
            '--record-count' => '43',
            $dryRun ? '--dry-run' : '--write' => true,
            '--json' => true,
        ];
        if (! $dryRun) {
            $options += [
                '--production-import-authorized' => true,
                '--no-index' => true,
                '--no-sitemap' => true,
                '--no-llms' => true,
                '--no-search-release' => true,
            ];
        }

        return $options;
    }

    private function seedPublishedProfiles(): void
    {
        foreach ($this->baseTypes() as $typeCode) {
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
            foreach (['A', 'T'] as $variantCode) {
                PersonalityProfileVariant::query()->create([
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
            }
        }
    }

    /** @return list<string> */
    private function baseTypes(): array
    {
        return ['INTJ', 'INTP', 'ENTJ', 'ENTP', 'INFJ', 'INFP', 'ENFJ', 'ENFP', 'ISTJ', 'ISFJ', 'ESTJ', 'ESFJ', 'ISTP', 'ISFP', 'ESTP', 'ESFP'];
    }

    private function writePackage(array $package, string $suffix = ''): string
    {
        $path = storage_path('framework/testing/mbti-cms-import-40-'.$suffix.'-'.uniqid('', true).'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $path;
    }

    /** @return array<string,mixed> */
    private function jsonOutput(): array
    {
        $decoded = json_decode(trim(Artisan::output()), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @param array<string,mixed> $value */
    private function hashJson(array $value): string
    {
        return hash('sha256', (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
