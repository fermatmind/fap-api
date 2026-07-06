<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PersonalityMbtiContent15MixedImportPreflightCommandTest extends TestCase
{
    private const SOURCE_PACKAGE_SHA = '75244fa4af3c234851519eba5a426daf8766c13e7c4b2bc9e94d5a5855ce6ccb';

    private const AUTHORIZATION_PAYLOAD_SHA = 'be0d1bb584c15f383322c9e5aff560709c46ea1e34d135cb9ced6d1e4601fe15';

    public function test_mixed_content15_package_preflights_profile_at_and_cross_type_rows_without_writes(): void
    {
        [$packagePath, $authorizationPath] = $this->writeFixturePair($this->validPackage(), $this->validAuthorizationPackage());

        $exitCode = Artisan::call('personality:mbti-content15-mixed-import-preflight', [
            '--package' => $packagePath,
            '--authorization-package' => $authorizationPath,
            '--source-package-sha256' => self::SOURCE_PACKAGE_SHA,
            '--authorization-payload-sha256' => self::AUTHORIZATION_PAYLOAD_SHA,
            '--import-scope-mode' => 'top_blocker_batch_only',
            '--record-count' => '9',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($payload['ok']);
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['dry_run_only']);
        $this->assertFalse($payload['write_supported_in_this_pr']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertFalse($payload['publish_attempted']);
        $this->assertFalse($payload['index_attempted']);
        $this->assertFalse($payload['search_release_attempted']);
        $this->assertFalse($payload['sitemap_llms_release_attempted']);
        $this->assertSame(9, $payload['record_count']);
        $this->assertSame(9, $payload['authorization_record_count']);
        $this->assertSame(4, $payload['profile_record_count']);
        $this->assertSame(5, $payload['comparison_record_count']);
        $this->assertSame(1, $payload['at_comparison_count']);
        $this->assertSame(4, $payload['cross_type_comparison_count']);
        $this->assertSame([], $payload['errors']);

        $rowsByPath = [];
        foreach ($payload['rows'] as $row) {
            $rowsByPath[$row['target_path']] = $row;
        }

        $profile = $rowsByPath['/zh/personality/istj-a'];
        $at = $rowsByPath['/zh/personality/intp-a-vs-intp-t'];
        $crossType = $rowsByPath['/zh/personality/entj-vs-intj'];

        $this->assertSame('personality_profile_variant_revisions', $profile['target']['target_table']);
        $this->assertSame('ISTJ-A', $profile['identity']['runtime_type_code']);
        $this->assertSame('would_stage_profile_content_draft', $profile['action']);
        $this->assertSame('personality_profile_sections', $at['target']['target_table']);
        $this->assertSame('mbti64_comparison_a_vs_t', $at['target']['section_key']);
        $this->assertSame('at', $at['identity']['comparison_kind']);
        $this->assertSame('mbti_cross_type_comparison_authorities', $crossType['target']['target_table']);
        $this->assertSame('cross_type', $crossType['identity']['comparison_kind']);
        $this->assertContains('personality_profile_variant_revisions', $payload['field_mapping_contract']['profile_target_tables']);
        $this->assertContains('personality_profile_sections', $payload['field_mapping_contract']['at_comparison_target_tables']);
        $this->assertContains('mbti_cross_type_comparison_authorities', $payload['field_mapping_contract']['cross_type_comparison_target_tables']);
        $this->assertContains('comparison_public_projection_v1', $payload['field_mapping_contract']['cross_type_comparison_target_tables']);
        $this->assertSame(self::SOURCE_PACKAGE_SHA, $payload['production_import_gate']['required_exact_authorization']['source_package_sha256']);
        $this->assertSame(self::AUTHORIZATION_PAYLOAD_SHA, $payload['production_import_gate']['required_exact_authorization']['authorization_payload_sha256']);
    }

    public function test_source_sha_mismatch_fails_closed(): void
    {
        [$packagePath, $authorizationPath] = $this->writeFixturePair($this->validPackage(), $this->validAuthorizationPackage());

        $exitCode = Artisan::call('personality:mbti-content15-mixed-import-preflight', [
            '--package' => $packagePath,
            '--authorization-package' => $authorizationPath,
            '--source-package-sha256' => str_repeat('0', 64),
            '--authorization-payload-sha256' => self::AUTHORIZATION_PAYLOAD_SHA,
            '--import-scope-mode' => 'top_blocker_batch_only',
            '--record-count' => '9',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('source_package_sha256_mismatch', array_column($payload['errors'], 'code'));
        $this->assertFalse($payload['cms_write_attempted']);
    }

    public function test_authorization_sha_mismatch_fails_closed(): void
    {
        [$packagePath, $authorizationPath] = $this->writeFixturePair($this->validPackage(), $this->validAuthorizationPackage());

        $exitCode = Artisan::call('personality:mbti-content15-mixed-import-preflight', [
            '--package' => $packagePath,
            '--authorization-package' => $authorizationPath,
            '--source-package-sha256' => self::SOURCE_PACKAGE_SHA,
            '--authorization-payload-sha256' => str_repeat('f', 64),
            '--import-scope-mode' => 'top_blocker_batch_only',
            '--record-count' => '9',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('authorization_payload_sha256_mismatch', array_column($payload['errors'], 'code'));
        $this->assertFalse($payload['cms_write_attempted']);
    }

    public function test_record_count_mismatch_fails_closed(): void
    {
        $package = $this->validPackage();
        array_pop($package['records']);
        [$packagePath, $authorizationPath] = $this->writeFixturePair($package, $this->validAuthorizationPackage());

        $exitCode = Artisan::call('personality:mbti-content15-mixed-import-preflight', [
            '--package' => $packagePath,
            '--authorization-package' => $authorizationPath,
            '--source-package-sha256' => self::SOURCE_PACKAGE_SHA,
            '--authorization-payload-sha256' => self::AUTHORIZATION_PAYLOAD_SHA,
            '--import-scope-mode' => 'top_blocker_batch_only',
            '--record-count' => '9',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertContains('record_count_mismatch', array_column($payload['errors'], 'code'));
        $this->assertFalse($payload['cms_write_attempted']);
    }

    public function test_write_mode_is_refused_before_package_reading_or_mutation(): void
    {
        $exitCode = Artisan::call('personality:mbti-content15-mixed-import-preflight', [
            '--package' => '/tmp/does-not-matter.json',
            '--authorization-package' => '/tmp/does-not-matter-auth.json',
            '--write' => true,
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();

        $this->assertSame(1, $exitCode);
        $this->assertFalse($payload['ok']);
        $this->assertFalse($payload['write_supported_in_this_pr']);
        $this->assertFalse($payload['writes_committed']);
        $this->assertFalse($payload['cms_write_attempted']);
        $this->assertStringContainsString('--write is intentionally unsupported', (string) ($payload['errors'][0]['message'] ?? ''));
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
                'authorization_record_id' => str_replace('mbti-cms-22:', 'mbti-cms-23:', $record['dry_run_record_id']),
                'source_dry_run_record_id' => $record['dry_run_record_id'],
                'target_path' => $record['target_path'],
                'kind' => $record['kind'],
                'locale' => $record['locale'],
                'slug' => $record['slug'],
                'code' => $record['code'],
                'cms_resource' => $record['cms_resource'],
                'cms_key' => $record['cms_key'],
                'import_action' => $record['import_action'],
                'exact_payload_sha256' => $record['exact_payload_sha256'],
                'required_schema_status' => 'pass',
                'faq_count' => $record['schema_validation']['faq_count'],
                'section_keys' => $record['schema_validation']['section_keys'],
                'indexability_held_before_import' => true,
                'production_import_authorized' => false,
                'operator_review_required' => true,
            ];
        }

        return [
            'id' => 'MBTI-CMS-23',
            'artifact' => 'fixture',
            'status' => 'ready_for_operator_authorization',
            'authorization_package' => [
                'status' => 'ready_for_operator_authorization',
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
        $code = strtoupper($slug);
        $payload = $this->payload($slug, 'profile');
        $sectionKeys = [
            'direct_answer',
            'who_it_fits',
            'who_it_does_not_fit',
            'common_misunderstanding',
            'at_difference',
            'career_scenario',
            'relationship_scenario',
            'stress_scenario',
        ];

        return [
            'dry_run_record_id' => 'mbti-cms-22:profile:'.$slug,
            'kind' => 'profile',
            'target_path' => '/zh/personality/'.$slug,
            'target_url' => 'https://fermatmind.com/zh/personality/'.$slug,
            'locale' => 'zh-CN',
            'slug' => $slug,
            'code' => $code,
            'cms_resource' => 'personality_profile',
            'cms_key' => [
                'locale' => 'zh-CN',
                'framework' => 'mbti',
                'profile_code' => $code,
                'slug' => $slug,
            ],
            'import_action' => 'upsert_profile_content_draft',
            'approval_state' => 'approved_for_final_dry_run',
            'exact_payload_sha256' => hash('sha256', $slug.'-profile'),
            'schema_validation' => [
                'status' => 'pass',
                'errors' => [],
                'section_keys' => $sectionKeys,
                'faq_count' => 6,
                'seo_title_present' => true,
                'canonical_matches_target_url' => true,
                'indexability_held' => true,
            ],
            'dry_run_payload' => ['payload' => $payload],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function comparisonRecord(string $slug, string $left, string $right): array
    {
        $payload = $this->payload($slug, 'comparison');
        $sectionKeys = [
            'direct_answer',
            'quick_judgment_table',
            'easy_misread',
            'real_scenario_differences',
            'do_not_misjudge',
        ];

        return [
            'dry_run_record_id' => 'mbti-cms-22:comparison:'.$slug,
            'kind' => 'comparison',
            'target_path' => '/zh/personality/'.$slug,
            'target_url' => 'https://fermatmind.com/zh/personality/'.$slug,
            'locale' => 'zh-CN',
            'slug' => $slug,
            'code' => $left.' VS '.$right,
            'cms_resource' => 'personality_comparison',
            'cms_key' => [
                'locale' => 'zh-CN',
                'framework' => 'mbti',
                'comparison_slug' => $slug,
                'left_code' => $left,
                'right_code' => $right,
            ],
            'import_action' => 'upsert_comparison_content_draft',
            'approval_state' => 'approved_for_final_dry_run',
            'exact_payload_sha256' => hash('sha256', $slug.'-comparison'),
            'schema_validation' => [
                'status' => 'pass',
                'errors' => [],
                'section_keys' => $sectionKeys,
                'faq_count' => 4,
                'seo_title_present' => true,
                'canonical_matches_target_url' => true,
                'indexability_held' => true,
            ],
            'dry_run_payload' => ['payload' => $payload],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function payload(string $slug, string $kind): array
    {
        return [
            'title' => strtoupper($slug),
            'summary' => 'fixture summary',
            'seo' => [
                'title' => strtoupper($slug).' | 费马测试',
                'description' => 'fixture description',
            ],
            'canonical' => '/zh/personality/'.$slug,
            'robots' => 'noindex,nofollow',
            'launch_state' => 'cms_import_draft_only',
            'index_eligible' => false,
            'sitemap_eligible' => false,
            'llms_eligible' => false,
            'sections' => [
                ['key' => $kind === 'profile' ? 'direct_answer' : 'quick_judgment_table', 'body' => 'fixture section'],
            ],
            'faq' => [
                ['question' => 'Q1', 'answer' => 'A1'],
                ['question' => 'Q2', 'answer' => 'A2'],
                ['question' => 'Q3', 'answer' => 'A3'],
                ['question' => 'Q4', 'answer' => 'A4'],
                ['question' => 'Q5', 'answer' => 'A5'],
                ['question' => 'Q6', 'answer' => 'A6'],
            ],
            'internal_links' => [
                ['href' => '/zh/personality', 'anchor_text' => 'MBTI 人格', 'role' => 'hub', 'safe_public_route' => true],
                ['href' => '/zh/tests/mbti-personality-test-16-personality-types', 'anchor_text' => 'MBTI 测试', 'role' => 'test', 'safe_public_route' => true],
                ['href' => '/zh/personality/intp-a-vs-intp-t', 'anchor_text' => '热门对比', 'role' => 'comparison', 'safe_public_route' => true],
            ],
            'schema' => ['type' => 'WebPage'],
            'method_boundary' => 'not diagnostic',
            'evidence_notes' => ['fixture'],
        ];
    }

    /**
     * @param  array<string,mixed>  $package
     * @param  array<string,mixed>  $authorizationPackage
     * @return array{0:string,1:string}
     */
    private function writeFixturePair(array $package, array $authorizationPackage): array
    {
        $prefix = sys_get_temp_dir().'/mbti-cms26-'.bin2hex(random_bytes(6));
        $packagePath = $prefix.'-source.json';
        $authorizationPath = $prefix.'-authorization.json';

        File::put($packagePath, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        File::put($authorizationPath, json_encode($authorizationPackage, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [$packagePath, $authorizationPath];
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);

        return $payload;
    }
}
