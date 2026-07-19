<?php

declare(strict_types=1);

namespace Tests\Feature\V0_5;

use Tests\TestCase;

final class MbtiCompRuntime46ProductionAcceptanceReportTest extends TestCase
{
    private const EXPECTED_SECTION_IDS = [
        'biggest_difference',
        'quick_judgment_table',
        'easy_misread',
        'work_scenarios',
        'relationship_scenarios',
        'stress_scenarios',
        'do_not_misjudge',
        'common_ground',
        'usage_boundary',
    ];

    private const EXPECTED_SLUGS = [
        'intj-a-vs-intj-t',
        'intp-a-vs-intp-t',
        'entj-a-vs-entj-t',
        'entp-a-vs-entp-t',
        'infj-a-vs-infj-t',
        'infp-a-vs-infp-t',
        'enfj-a-vs-enfj-t',
        'enfp-a-vs-enfp-t',
        'istj-a-vs-istj-t',
        'isfj-a-vs-isfj-t',
        'estj-a-vs-estj-t',
        'esfj-a-vs-esfj-t',
        'istp-a-vs-istp-t',
        'isfp-a-vs-isfp-t',
        'estp-a-vs-estp-t',
        'esfp-a-vs-esfp-t',
    ];

    public function test_report_locks_exact_deployment_and_single_record_revision_scope(): void
    {
        $payload = $this->payload();

        $this->assertSame('MBTI-COMP-RUNTIME-46', $payload['task']);
        $this->assertSame('production_runtime_and_all_sixteen_at_comparisons_accepted', $payload['final_decision']);
        $this->assertSame('781d1636b2c74f5852076a5864b681910a1e0e47', $payload['dependency']['merge_sha']);

        $deployment = $payload['deployment'];
        $this->assertSame('code_only', $deployment['mode']);
        $this->assertSame('bc0ed833bc9aae1473ab37f1dead2517e1aff618', $deployment['target_sha']);
        $this->assertSame('mbti-comp-runtime-46-20260719-r3-29674638168-1', $deployment['release_name']);
        $this->assertTrue($deployment['deployed_revision_verified']);
        $this->assertTrue($deployment['health_and_contract_smoke_passed']);
        $this->assertFalse($deployment['cms_or_database_write_performed']);

        $revision = $payload['content_revision'];
        $this->assertSame('intp-a-vs-intp-t', $revision['slug']);
        $this->assertSame(1, $revision['record_count']);
        $this->assertSame('10b306f2dbac4f9a801a7718ec5584d84f56f6de601ada0f8f677bcb163f960e', $revision['payload_sha256']);
        $this->assertSame('5b8afeec191d348dbb888c6cb4a63ea1e167e1a004bf35e41c1e64399f0c8369', $revision['promotion_package_sha256']);
        $this->assertSame('c9b3c3fa7f68a73e946f6bbc0a3f02ea6a95f3cbf5e9d3141778dd7d6408e03d', $revision['promotion_authorization_sha256']);
        $this->assertSame('6f7148e9787127ce128e19f0a37832be78119c7f1d9dcdf3a5f4d83aa8295ab9', $revision['expected_post_sections_sha256']);
        $this->assertTrue($revision['first_write']['automatic_exact_rollback_passed']);
        $this->assertTrue($revision['first_write']['public_state_restored_before_retry']);
        $this->assertTrue($revision['successful_retry']['writes_committed']);
        $this->assertTrue($revision['successful_retry']['exact_nine_section_readback']);
        $this->assertFalse($revision['successful_retry']['rollback_executed']);
        $this->assertFalse($revision['publication_changed']);
        $this->assertFalse($revision['indexability_changed']);
        $this->assertFalse($revision['sitemap_changed']);
        $this->assertFalse($revision['llms_changed']);
        $this->assertFalse($revision['search_action_attempted']);
        $this->assertTrue($revision['rollback_ready']);
    }

    public function test_report_proves_all_sixteen_public_targets_meet_the_exact_contract(): void
    {
        $acceptance = $this->payload()['acceptance'];
        $targets = $acceptance['targets'];

        $this->assertSame('CMS/backend public API', $acceptance['authority_source']);
        $this->assertSame('public HTTPS only', $acceptance['transport']);
        $this->assertSame(16, $acceptance['target_count']);
        $this->assertSame(16, $acceptance['api_pass_count']);
        $this->assertSame(16, $acceptance['page_pass_count']);
        $this->assertSame(9, $acceptance['expected_section_count']);
        $this->assertSame(self::EXPECTED_SECTION_IDS, $acceptance['expected_section_ids']);
        $this->assertSame(5, $acceptance['expected_faq_count']);
        $this->assertCount(16, $targets);
        $this->assertSame(self::EXPECTED_SLUGS, array_column($targets, 'slug'));
        $this->assertCount(16, array_unique(array_column($targets, 'slug')));

        foreach ($targets as $target) {
            $this->assertSame(200, $target['api_status'], $target['slug']);
            $this->assertSame(200, $target['page_status'], $target['slug']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $target['sections_sha256'], $target['slug']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $target['projection_sha256'], $target['slug']);
            $this->assertSame("https://fermatmind.com/zh/personality/{$target['slug']}", $target['canonical']);
            $this->assertTrue($target['pass'], $target['slug']);
        }

        $this->assertTrue($acceptance['all_section_titles_and_bodies_non_empty']);
        $this->assertTrue($acceptance['all_faq_counts_match']);
        $this->assertTrue($acceptance['all_canonicals_match']);
        $this->assertTrue($acceptance['all_robots_index_follow']);
        $this->assertTrue($acceptance['all_jsonld_present']);
        $this->assertTrue($acceptance['all_visible_pages_match_section_titles']);
        $this->assertFalse($acceptance['frontend_fallback_used']);
        $this->assertFalse($acceptance['local_package_runtime_authority_used']);
    }

    public function test_report_records_all_forbidden_actions_as_not_performed(): void
    {
        $forbidden = $this->payload()['forbidden_actions'];

        foreach ($forbidden as $action => $performed) {
            $this->assertFalse($performed, $action);
        }

        $report = (string) file_get_contents(base_path('docs/seo/mbti-comp-runtime-46-production-acceptance.md'));
        $this->assertStringContainsString('Aggregate result: API `16/16`; visible pages `16/16`.', $report);
        $this->assertStringContainsString('No server-internal inspection was used.', $report);
        $this->assertStringContainsString('The next train item is `MBTI-COMP-GATE-47`', $report);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $path = base_path('docs/seo/generated/mbti-comp-runtime-46-production-acceptance.v1.json');

        $this->assertFileExists($path);

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
